<?php

namespace Tests\Feature\Growth;

use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\DeductionPreset;
use App\Modules\Ledger\Models\PointAccount;
use App\Modules\Ledger\Services\PointLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeductionPresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_gets_default_presets_and_can_crud(): void
    {
        ['parent' => $parent] = $this->makeFamily();

        $list = $this->actingAsParent($parent)
            ->getJson('/api/v1/parent/deduction-presets')
            ->assertOk()
            ->json('data');
        $this->assertGreaterThanOrEqual(3, count($list));

        $created = $this->actingAsParent($parent, sudo: true)
            ->postJson('/api/v1/parent/deduction-presets', [
                'title' => '不写作业',
                'point_amount' => 5,
                'coin_amount' => 0,
            ])
            ->assertCreated()
            ->json('data');
        $this->assertSame('不写作业', $created['title']);
        $this->assertSame(5, $created['point_amount']);

        $this->actingAsParent($parent, sudo: true)
            ->putJson('/api/v1/parent/deduction-presets/'.$created['id'], [
                'point_amount' => 4,
            ])
            ->assertOk()
            ->assertJsonPath('data.point_amount', 4);

        $this->actingAsParent($parent, sudo: true)
            ->deleteJson('/api/v1/parent/deduction-presets/'.$created['id'])
            ->assertOk();

        $this->assertFalse(DeductionPreset::query()->whereKey($created['id'])->exists());
    }

    public function test_parent_can_multi_select_presets_in_one_deduct(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $date = '2026-10-27';

        $points = PointAccount::query()->where('child_id', $child->id)->firstOrFail();
        app(PointLedgerService::class)->credit(
            $points,
            30,
            LedgerEntryType::Reward,
            SourceModule::Parent,
            'test',
            1,
            'seed:point',
            $date,
        );

        $this->actingAsParent($parent)->getJson('/api/v1/parent/deduction-presets')->assertOk();
        $presets = DeductionPreset::query()
            ->where('family_id', $family->id)
            ->where('is_active', true)
            ->orderBy('sort')
            ->take(2)
            ->get();
        $this->assertCount(2, $presets);

        $expectedPoints = $presets->sum('point_amount');

        $this->actingAsParent($parent, sudo: true)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/parent/children/{$child->id}/deduct", [
                'preset_ids' => $presets->pluck('id')->all(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.point', $expectedPoints)
            ->assertJsonPath('data.count', 2);

        $this->assertSame(30 - $expectedPoints, PointAccount::query()->where('child_id', $child->id)->value('balance'));

        $ledger = $this->actingAsParent($parent)
            ->getJson("/api/v1/parent/children/{$child->id}/ledger?asset=point")
            ->assertOk()
            ->json('data.entries');

        $this->assertSame(LedgerEntryType::ParentDeduction->value, $ledger[0]['entry_type']);
        $this->assertSame('deduction_preset', $ledger[0]['source_type']);
        $this->assertSame($presets[1]->title, $ledger[0]['note']);
        $this->assertSame($presets[0]->title, $ledger[1]['note']);
    }
}
