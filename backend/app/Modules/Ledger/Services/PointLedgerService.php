<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\PointHoldStatus;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\PointAccount;
use App\Modules\Ledger\Models\PointHold;
use App\Modules\Ledger\Models\PointLedgerEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class PointLedgerService
{
    public function __construct(private readonly Clock $clock) {}

    public function credit(
        PointAccount $account,
        int $amount,
        LedgerEntryType $entryType,
        SourceModule $sourceModule,
        ?string $sourceType,
        ?int $sourceId,
        string $idempotencyKey,
        string $businessDate,
        ?int $approvedBy = null,
        ?int $operatorId = null,
        ?string $note = null,
    ): PointLedgerEntry {
        if ($amount <= 0) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('积分入账金额必须大于 0', 'Point credit amount must be greater than 0'), 422);
        }

        return $this->post($account, $amount, $entryType, $sourceModule, $sourceType, $sourceId, $idempotencyKey, $businessDate, $approvedBy, $operatorId, $note);
    }

    public function debit(
        PointAccount $account,
        int $amount,
        LedgerEntryType $entryType,
        SourceModule $sourceModule,
        ?string $sourceType,
        ?int $sourceId,
        string $idempotencyKey,
        string $businessDate,
        ?int $approvedBy = null,
        ?int $operatorId = null,
        ?string $note = null,
    ): PointLedgerEntry {
        if ($amount <= 0) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('积分扣款金额必须大于 0', 'Point debit amount must be greater than 0'), 422);
        }

        $account = PointAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
        if ($account->available() < $amount) {
            throw new ApiException('INSUFFICIENT_POINTS', BiMsg::of('积分不足', 'Insufficient points'), 422);
        }

        return $this->post($account, -$amount, $entryType, $sourceModule, $sourceType, $sourceId, $idempotencyKey, $businessDate, $approvedBy, $operatorId, $note);
    }

    public function hold(
        PointAccount $account,
        int $amount,
        string $sourceType,
        int $sourceId,
        string $idempotencyKey,
    ): PointHold {
        $existing = PointHold::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $account = PointAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
        if ($account->available() < $amount) {
            throw new ApiException('INSUFFICIENT_POINTS', BiMsg::of('积分不足', 'Insufficient points'), 422);
        }

        try {
            $hold = PointHold::query()->create([
                'family_id' => $account->family_id,
                'account_id' => $account->id,
                'amount' => $amount,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'status' => PointHoldStatus::Active,
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicateKey($e)) {
                return PointHold::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
            }
            throw $e;
        }

        $account->held += $amount;
        $account->lock_version++;
        $account->updated_at = $this->clock->now();
        $account->save();

        return $hold;
    }

    public function capture(PointHold $hold, string $ledgerIdempotencyKey, string $businessDate, ?int $approvedBy = null): PointLedgerEntry
    {
        $hold = PointHold::query()->whereKey($hold->id)->lockForUpdate()->firstOrFail();
        if ($hold->status === PointHoldStatus::Captured && $hold->captured_ledger_id) {
            return PointLedgerEntry::query()->findOrFail($hold->captured_ledger_id);
        }
        if ($hold->status !== PointHoldStatus::Active) {
            throw new ApiException('INVALID_STATE', BiMsg::of('预留状态不正确', 'Invalid reservation status'), 409);
        }

        $account = PointAccount::query()->whereKey($hold->account_id)->lockForUpdate()->firstOrFail();
        // Drop this hold from `held` before debiting. Otherwise post()'s
        // held <= balance_after check still counts the reserved amount and
        // falsely rejects captures when available < hold (e.g. exact balance).
        $account->held -= $hold->amount;
        $account->lock_version++;
        $account->updated_at = $this->clock->now();
        $account->save();

        $entry = $this->post(
            $account,
            -$hold->amount,
            LedgerEntryType::Redemption,
            SourceModule::Shop,
            $hold->source_type,
            $hold->source_id,
            $ledgerIdempotencyKey,
            $businessDate,
            $approvedBy,
            null,
            '兑换扣款',
        );

        $hold->status = PointHoldStatus::Captured;
        $hold->captured_ledger_id = $entry->id;
        $hold->save();

        return $entry;
    }

    public function release(PointHold $hold): PointHold
    {
        $hold = PointHold::query()->whereKey($hold->id)->lockForUpdate()->firstOrFail();
        if ($hold->status === PointHoldStatus::Released) {
            return $hold;
        }
        if ($hold->status !== PointHoldStatus::Active) {
            throw new ApiException('INVALID_STATE', BiMsg::of('预留状态不正确', 'Invalid reservation status'), 409);
        }

        $account = PointAccount::query()->whereKey($hold->account_id)->lockForUpdate()->firstOrFail();
        $account->held -= $hold->amount;
        $account->lock_version++;
        $account->updated_at = $this->clock->now();
        $account->save();

        $hold->status = PointHoldStatus::Released;
        $hold->save();

        return $hold;
    }

    public function reverse(PointLedgerEntry $original, string $idempotencyKey, ?int $operatorId = null): PointLedgerEntry
    {
        $existing = PointLedgerEntry::query()->where('reversal_of_id', $original->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $account = PointAccount::query()->whereKey($original->account_id)->lockForUpdate()->firstOrFail();

        return $this->post(
            $account,
            -$original->amount,
            LedgerEntryType::Reversal,
            $original->source_module,
            $original->source_type,
            $original->source_id,
            $idempotencyKey,
            $original->business_date->toDateString(),
            null,
            $operatorId,
            '冲正',
            $original->id,
        );
    }

    private function post(
        PointAccount $account,
        int $amount,
        LedgerEntryType $entryType,
        SourceModule $sourceModule,
        ?string $sourceType,
        ?int $sourceId,
        string $idempotencyKey,
        string $businessDate,
        ?int $approvedBy,
        ?int $operatorId,
        ?string $note,
        ?int $reversalOfId = null,
    ): PointLedgerEntry {
        $existing = PointLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $account = PointAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
        $before = $account->balance;
        $after = $before + $amount;
        if ($after < 0) {
            throw new ApiException('INSUFFICIENT_POINTS', BiMsg::of('积分不足', 'Insufficient points'), 422);
        }
        if ($account->held > $after) {
            throw new ApiException('INSUFFICIENT_POINTS', BiMsg::of('积分不足（含预留）', 'Insufficient points (including reserved)'), 422);
        }

        try {
            $entry = PointLedgerEntry::query()->create([
                'ulid' => (string) Str::ulid(),
                'family_id' => $account->family_id,
                'account_id' => $account->id,
                'child_id' => $account->child_id,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'entry_type' => $entryType,
                'source_module' => $sourceModule,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'idempotency_key' => $idempotencyKey,
                'reversal_of_id' => $reversalOfId,
                'approved_by' => $approvedBy,
                'operator_id' => $operatorId,
                'note' => $note,
                'business_date' => $businessDate,
                'created_at' => $this->clock->now(),
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicateKey($e)) {
                return PointLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
            }
            throw $e;
        }

        $account->balance = $after;
        if ($amount > 0) {
            $account->lifetime_earned += $amount;
        }
        $account->lock_version++;
        $account->updated_at = $this->clock->now();
        $account->save();

        return $entry;
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'Duplicate') || $e->getCode() === '23000';
    }
}
