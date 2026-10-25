<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Ledger\Enums\CoinHoldStatus;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\CoinAccount;
use App\Modules\Ledger\Models\CoinHold;
use App\Modules\Ledger\Models\CoinLedgerEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class CoinLedgerService
{
    public function __construct(private readonly Clock $clock) {}

    public function credit(
        CoinAccount $account,
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
    ): CoinLedgerEntry {
        if ($amount <= 0) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('金币入账金额必须大于 0', 'Coin credit amount must be greater than 0'), 422);
        }

        return $this->post($account, $amount, $entryType, $sourceModule, $sourceType, $sourceId, $idempotencyKey, $businessDate, $approvedBy, $operatorId, $note);
    }

    public function debit(
        CoinAccount $account,
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
    ): CoinLedgerEntry {
        if ($amount <= 0) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('金币扣款金额必须大于 0', 'Coin debit amount must be greater than 0'), 422);
        }

        $account = CoinAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
        if ($account->available() < $amount) {
            throw new ApiException('INSUFFICIENT_COINS', BiMsg::of('金币不足', 'Insufficient coins'), 422);
        }

        return $this->post($account, -$amount, $entryType, $sourceModule, $sourceType, $sourceId, $idempotencyKey, $businessDate, $approvedBy, $operatorId, $note);
    }

    public function hold(
        CoinAccount $account,
        int $amount,
        string $sourceType,
        int $sourceId,
        string $idempotencyKey,
    ): CoinHold {
        $existing = CoinHold::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $account = CoinAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
        if ($account->available() < $amount) {
            throw new ApiException('INSUFFICIENT_COINS', BiMsg::of('金币不足', 'Insufficient coins'), 422);
        }

        try {
            $hold = CoinHold::query()->create([
                'family_id' => $account->family_id,
                'account_id' => $account->id,
                'amount' => $amount,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'status' => CoinHoldStatus::Active,
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicateKey($e)) {
                return CoinHold::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
            }
            throw $e;
        }

        $account->held += $amount;
        $account->lock_version++;
        $account->updated_at = $this->clock->now();
        $account->save();

        return $hold;
    }

    public function capture(CoinHold $hold, string $ledgerIdempotencyKey, string $businessDate, ?int $approvedBy = null): CoinLedgerEntry
    {
        $hold = CoinHold::query()->whereKey($hold->id)->lockForUpdate()->firstOrFail();
        if ($hold->status === CoinHoldStatus::Captured && $hold->captured_ledger_id) {
            return CoinLedgerEntry::query()->findOrFail($hold->captured_ledger_id);
        }
        if ($hold->status !== CoinHoldStatus::Active) {
            throw new ApiException('INVALID_STATE', BiMsg::of('预留状态不正确', 'Invalid reservation status'), 409);
        }

        $account = CoinAccount::query()->whereKey($hold->account_id)->lockForUpdate()->firstOrFail();
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

        $hold->status = CoinHoldStatus::Captured;
        $hold->captured_ledger_id = $entry->id;
        $hold->save();

        return $entry;
    }

    public function release(CoinHold $hold): CoinHold
    {
        $hold = CoinHold::query()->whereKey($hold->id)->lockForUpdate()->firstOrFail();
        if ($hold->status === CoinHoldStatus::Released) {
            return $hold;
        }
        if ($hold->status !== CoinHoldStatus::Active) {
            throw new ApiException('INVALID_STATE', BiMsg::of('预留状态不正确', 'Invalid reservation status'), 409);
        }

        $account = CoinAccount::query()->whereKey($hold->account_id)->lockForUpdate()->firstOrFail();
        $account->held -= $hold->amount;
        $account->lock_version++;
        $account->updated_at = $this->clock->now();
        $account->save();

        $hold->status = CoinHoldStatus::Released;
        $hold->save();

        return $hold;
    }

    public function reverse(CoinLedgerEntry $original, string $idempotencyKey, ?int $operatorId = null): CoinLedgerEntry
    {
        $existing = CoinLedgerEntry::query()->where('reversal_of_id', $original->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $account = CoinAccount::query()->whereKey($original->account_id)->lockForUpdate()->firstOrFail();

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
        CoinAccount $account,
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
    ): CoinLedgerEntry {
        $existing = CoinLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $account = CoinAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
        $before = $account->balance;
        $after = $before + $amount;
        if ($after < 0) {
            throw new ApiException('INSUFFICIENT_COINS', BiMsg::of('金币不足', 'Insufficient coins'), 422);
        }
        if ($account->held > $after) {
            throw new ApiException('INSUFFICIENT_COINS', BiMsg::of('金币不足（含预留）', 'Insufficient coins (including reserved)'), 422);
        }

        try {
            $entry = CoinLedgerEntry::query()->create([
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
                return CoinLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
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
