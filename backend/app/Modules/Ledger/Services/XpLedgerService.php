<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Settings\Settings;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\XpAccount;
use App\Modules\Ledger\Models\XpLedgerEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class XpLedgerService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly LevelCurve $levels,
        private readonly Settings $settings,
    ) {}

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

        $existing = XpLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $entry = $this->post($account, $amount, $entryType, $sourceModule, $sourceType, $sourceId, $idempotencyKey, $businessDate, $approvedBy, $operatorId, $note);
        $this->applyLevelUps($account->fresh(), $businessDate);

        return $entry->fresh();
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

    public function progressPayload(XpAccount $account): array
    {
        $level = max(1, (int) $account->level);
        $maxLevel = $this->maxLevel($account->family_id);
        $toNext = $this->levels->toNextLevel($level);
        $atCap = $level >= $maxLevel;

        return [
            'balance' => $account->balance,
            'lifetime_earned' => $account->lifetime_earned,
            'level' => $level,
            'max_level' => $maxLevel,
            'to_next' => $atCap ? 0 : $toNext,
            'progress' => $atCap ? 1.0 : ($toNext > 0 ? min(1.0, round($account->balance / $toNext, 4)) : 1.0),
        ];
    }

    /** Apply any pending level-ups for XP that was credited before the level curve existed. */
    public function reconcileLevels(XpAccount $account, string $businessDate): void
    {
        DB::transaction(function () use ($account, $businessDate) {
            $this->applyLevelUps($account, $businessDate);
        });
    }

    private function applyLevelUps(XpAccount $account, string $businessDate): void
    {
        $account = XpAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
        $maxLevel = $this->maxLevel($account->family_id);
        $step = 0;

        while ($account->level < $maxLevel) {
            $need = $this->levels->toNextLevel((int) $account->level);
            if ($account->balance < $need) {
                break;
            }

            $step++;
            $this->post(
                $account,
                -$need,
                LedgerEntryType::LevelUp,
                SourceModule::System,
                'xp_level_up',
                $account->child_id,
                'level_up:'.$account->child_id.':'.$account->level.':to:'.($account->level + 1).':'.$businessDate.':'.$step.':'.$account->lifetime_earned,
                $businessDate,
                null,
                null,
                '升级至 '.($account->level + 1).' 级',
            );

            $account = XpAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $account->level = (int) $account->level + 1;
            $account->lock_version++;
            $account->updated_at = $this->clock->now();
            $account->save();
        }

        // Cap the bar at max level so it no longer grows past a full bar.
        if ($account->level >= $maxLevel) {
            $cap = max(0, $this->levels->toNextLevel($maxLevel) - 1);
            if ($account->balance > $cap) {
                $overflow = $account->balance - $cap;
                $this->post(
                    $account,
                    -$overflow,
                    LedgerEntryType::LevelUp,
                    SourceModule::System,
                    'xp_level_cap',
                    $account->child_id,
                    'level_cap:'.$account->child_id.':'.$businessDate.':'.$account->lifetime_earned.':'.$overflow,
                    $businessDate,
                    null,
                    null,
                    '满级经验封顶',
                );
            }
        }
    }

    private function maxLevel(int $familyId): int
    {
        return (int) $this->settings->get('xp.max_level', $familyId);
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
