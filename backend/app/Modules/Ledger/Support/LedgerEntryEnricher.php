<?php

namespace App\Modules\Ledger\Support;

use App\Modules\Habit\Models\Habit;
use App\Modules\Habit\Models\HabitRecord;
use App\Modules\Ledger\Models\CoinLedgerEntry;
use App\Modules\Ledger\Models\DeductionPreset;
use App\Modules\Ledger\Models\PointLedgerEntry;
use App\Modules\Ledger\Models\RewardGrant;
use App\Modules\Ledger\Models\XpLedgerEntry;
use App\Modules\Poetry\Models\PoetryPoem;
use App\Modules\Poetry\Models\PoetryRecitationRecord;
use App\Modules\Shop\Models\RewardRedemption;
use App\Modules\Task\Models\TaskInstance;
use Illuminate\Support\Collection;

class LedgerEntryEnricher
{
    /**
     * @param  Collection<int, XpLedgerEntry|PointLedgerEntry|CoinLedgerEntry>  $entries
     * @param  'xp'|'point'|'coin'  $asset
     * @return list<array<string, mixed>>
     */
    public function present(Collection $entries, string $asset): array
    {
        if ($entries->isEmpty()) {
            return [];
        }

        $policyByLedgerId = $this->policyCodesByLedgerId($entries, $asset);
        $namesByKey = $this->sourceNames($entries);

        return $entries->map(function ($entry) use ($policyByLedgerId, $namesByKey) {
            $sourceType = $entry->source_type;
            $sourceId = $entry->source_id !== null ? (int) $entry->source_id : null;
            $key = $sourceType && $sourceId !== null ? $sourceType.':'.$sourceId : null;

            return [
                'ulid' => $entry->ulid,
                'amount' => (int) $entry->amount,
                'balance_after' => (int) $entry->balance_after,
                'entry_type' => $entry->entry_type->value,
                'source_module' => $entry->source_module->value,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'policy_code' => $policyByLedgerId[$entry->id] ?? null,
                'source_name' => $key !== null ? ($namesByKey[$key] ?? null) : null,
                'note' => $entry->note,
                'business_date' => $entry->business_date?->toDateString(),
                'created_at' => $entry->created_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, XpLedgerEntry|PointLedgerEntry|CoinLedgerEntry>  $entries
     * @param  'xp'|'point'|'coin'  $asset
     * @return array<int, string>
     */
    private function policyCodesByLedgerId(Collection $entries, string $asset): array
    {
        $ids = $entries->pluck('id')->all();
        $column = match ($asset) {
            'xp' => 'xp_ledger_id',
            'point' => 'point_ledger_id',
            default => 'coin_ledger_id',
        };

        return RewardGrant::query()
            ->whereIn($column, $ids)
            ->get([$column, 'policy_code'])
            ->mapWithKeys(fn (RewardGrant $g) => [(int) $g->{$column} => (string) $g->policy_code])
            ->all();
    }

    /**
     * @param  Collection<int, XpLedgerEntry|PointLedgerEntry|CoinLedgerEntry>  $entries
     * @return array<string, string>
     */
    private function sourceNames(Collection $entries): array
    {
        $idsByType = [];
        foreach ($entries as $entry) {
            if ($entry->source_type === null || $entry->source_id === null) {
                continue;
            }
            $idsByType[$entry->source_type][] = (int) $entry->source_id;
        }

        $out = [];

        if (! empty($idsByType['task_instance'])) {
            foreach (TaskInstance::query()->whereIn('id', array_unique($idsByType['task_instance']))->get(['id', 'snapshot']) as $row) {
                $title = is_array($row->snapshot) ? ($row->snapshot['title'] ?? null) : null;
                if (is_string($title) && $title !== '') {
                    $out['task_instance:'.$row->id] = $title;
                }
            }
        }

        if (! empty($idsByType['habit_record'])) {
            $records = HabitRecord::query()
                ->whereIn('id', array_unique($idsByType['habit_record']))
                ->get(['id', 'habit_id']);
            $habits = Habit::query()
                ->whereIn('id', $records->pluck('habit_id')->unique()->all())
                ->pluck('title', 'id');
            foreach ($records as $record) {
                $title = $habits[$record->habit_id] ?? null;
                if (is_string($title) && $title !== '') {
                    $out['habit_record:'.$record->id] = $title;
                }
            }
        }

        if (! empty($idsByType['reward_redemption'])) {
            foreach (RewardRedemption::query()->whereIn('id', array_unique($idsByType['reward_redemption']))->get(['id', 'reward_snapshot']) as $row) {
                $name = is_array($row->reward_snapshot) ? ($row->reward_snapshot['name'] ?? null) : null;
                if (is_string($name) && $name !== '') {
                    $out['reward_redemption:'.$row->id] = $name;
                }
            }
        }

        if (! empty($idsByType['poetry_recitation'])) {
            $records = PoetryRecitationRecord::query()
                ->whereIn('id', array_unique($idsByType['poetry_recitation']))
                ->get(['id', 'poem_id']);
            $poems = PoetryPoem::query()
                ->whereIn('id', $records->pluck('poem_id')->unique()->all())
                ->pluck('title', 'id');
            foreach ($records as $record) {
                $title = $poems[$record->poem_id] ?? null;
                if (is_string($title) && $title !== '') {
                    $out['poetry_recitation:'.$record->id] = $title;
                }
            }
        }

        if (! empty($idsByType['deduction_preset'])) {
            foreach (DeductionPreset::query()->whereIn('id', array_unique($idsByType['deduction_preset']))->get(['id', 'title']) as $row) {
                if (is_string($row->title) && $row->title !== '') {
                    $out['deduction_preset:'.$row->id] = $row->title;
                }
            }
        }

        return $out;
    }
}
