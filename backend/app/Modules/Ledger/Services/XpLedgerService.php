<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\XpAccount;
use App\Modules\Ledger\Models\XpLedgerEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class XpLedgerService
{
    public function __construct(private readonly Clock $clock) {}

    public function credit(
        XpAccount $account,
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
    ): XpLedgerEntry {
        if ($amount <= 0) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('XP 入账金额必须大于 0', 'XP credit amount must be greater than 0'), 422);
        }

        return $this->post($account, $amount, $entryType, $sourceModule, $sourceType, $sourceId, $idempotencyKey, $businessDate, $approvedBy, $operatorId, $note);
    }

    public function reverse(
        XpLedgerEntry $original,
        string $idempotencyKey,
        ?int $operatorId = null,
    ): XpLedgerEntry {
        if ($original->reversal_of_id !== null) {
            throw new ApiException('INVALID_STATE', BiMsg::of('不能冲正冲正记录', 'Cannot reverse a reversal entry'), 409);
        }

        $existing = XpLedgerEntry::query()->where('reversal_of_id', $original->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $account = XpAccount::query()->whereKey($original->account_id)->lockForUpdate()->firstOrFail();

        return $this->post(
            $account,
            -abs($original->amount),
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
        XpAccount $account,
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
    ): XpLedgerEntry {
        $existing = XpLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $account = XpAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
        $before = $account->balance;
        $after = $before + $amount;
        if ($after < 0) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('XP 余额不足', 'Insufficient XP balance'), 422);
        }

        try {
            $entry = XpLedgerEntry::query()->create([
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
                return XpLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
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
