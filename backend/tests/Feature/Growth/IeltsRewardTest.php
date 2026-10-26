<?php

namespace Tests\Feature\Growth;

use App\Modules\Core\Settings\Settings;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\TrustedDevice;
use App\Modules\Ielts\Services\IeltsRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IeltsRewardTest extends TestCase
{
    use RefreshDatabase;

    public function test_compute_points_by_grade_and_coins_by_accuracy(): void
    {
        // 10 words: 7 Good, 2 Hard, 1 Again → 7 + 1 = 8 points, accuracy 90%, 5 new words → 5 coins.
        $graded = ['w1' => 3, 'w2' => 3, 'w3' => 3, 'w4' => 3, 'w5' => 3, 'w6' => 3, 'w7' => 4, 'w8' => 2, 'w9' => 2, 'w10' => 1];
        $new = ['w1', 'w2', 'w3', 'w4', 'w5'];

        $r = IeltsRewardService::compute($graded, $new);
        $this->assertSame(8, $r['points']);
        $this->assertSame(90, $r['accuracy']);
        $this->assertSame(5, $r['coins']);

        // 60–89 %: half the new words.
        $r = IeltsRewardService::compute(['a' => 3, 'b' => 3, 'c' => 3, 'd' => 1, 'e' => 1], ['a', 'b', 'c', 'd', 'e']);
        $this->assertSame(60, $r['accuracy']);
        $this->assertSame(3, $r['points']);
        $this->assertSame(2, $r['coins']);

        // < 60 %: points still paid, no coins.
        $r = IeltsRewardService::compute(['a' => 3, 'b' => 1, 'c' => 1], ['a', 'b', 'c']);
        $this->assertSame(1, $r['points']);
        $this->assertSame(0, $r['coins']);

        // Only graded words count as new; empty session pays nothing.
        $r = IeltsRewardService::compute(['a' => 3], ['a', 'ghost']);
        $this->assertSame(1, $r['new_words']);
        $this->assertSame(0, IeltsRewardService::compute([], ['a'])['points']);
    }

    public function test_session_settles_once_and_pays_points_and_coins(): void
    {
        ['child' => $child, 'device' => $device, 'token' => $token] = $this->kid();

        $body = [
            'session_id' => 's1700000000000',
            'graded' => ['abandon' => 3, 'library' => 3, 'volcano' => 2, 'orchestra' => 1],
            'new_ids' => ['abandon', 'library', 'volcano', 'orchestra'],
        ];

        $this->settle($child, $device, $token, $body)
            ->assertOk()
            ->assertJsonPath('data.settled', true)
            ->assertJsonPath('data.points', 2)
            ->assertJsonPath('data.accuracy', 75)
            ->assertJsonPath('data.coins', 2)
            ->assertJsonPath('data.capped', false);

        $this->assertSame(2, $this->points($child));
        $this->assertSame(2, $this->coins($child));

        // Same session again (new idempotency key, e.g. after reload): no second payout.
        $this->settle($child, $device, $token, $body)
            ->assertOk()
            ->assertJsonPath('data.settled', false)
            ->assertJsonPath('data.points', 0)
            ->assertJsonPath('data.coins', 0);

        $this->assertSame(2, $this->points($child));
        $this->assertSame(2, $this->coins($child));
    }

    public function test_daily_caps_apply_to_points_and_coins(): void
    {
        ['child' => $child, 'device' => $device, 'token' => $token] = $this->kid();

        $perfect = function (string $prefix): array {
            $graded = [];
            $new = [];
            for ($i = 0; $i < 10; $i++) {
                $graded[$prefix.$i] = 3;
                $new[] = $prefix.$i;
            }

            return ['graded' => $graded, 'new_ids' => $new];
        };

        $this->settle($child, $device, $token, ['session_id' => 'a'] + $perfect('a'))
            ->assertOk()->assertJsonPath('data.points', 10)->assertJsonPath('data.coins', 10);
        $this->settle($child, $device, $token, ['session_id' => 'b'] + $perfect('b'))
            ->assertOk()->assertJsonPath('data.points', 10)->assertJsonPath('data.coins', 0)->assertJsonPath('data.capped', true);
        $this->settle($child, $device, $token, ['session_id' => 'c'] + $perfect('c'))
            ->assertOk()->assertJsonPath('data.points', 0)->assertJsonPath('data.coins', 0)->assertJsonPath('data.capped', true);

        $this->assertSame(20, $this->points($child));
        $this->assertSame(10, $this->coins($child));
    }

    public function test_parent_can_adjust_daily_caps(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();

        $this->actingAsParent($parent)
            ->getJson('/api/v1/parent/ielts/settings')
            ->assertOk()
            ->assertJsonPath('data.rewards_enabled', true)
            ->assertJsonPath('data.points_daily_cap', 20)
            ->assertJsonPath('data.coins_daily_cap', 10)
            ->assertJsonPath('data.mastered_points', 2)
            ->assertJsonPath('data.mastered_daily_cap', 20);

        $this->actingAsParent($parent)
            ->putJson('/api/v1/parent/ielts/settings', ['points_daily_cap' => 3, 'coins_daily_cap' => 1, 'mastered_points' => 5])
            ->assertOk()
            ->assertJsonPath('data.points_daily_cap', 3)
            ->assertJsonPath('data.coins_daily_cap', 1)
            ->assertJsonPath('data.mastered_points', 5);

        $this->actingAsParent($parent)
            ->putJson('/api/v1/parent/ielts/settings', ['points_daily_cap' => 999])
            ->assertStatus(422);

        $settings = app(Settings::class);
        $this->assertSame(3, $settings->get('ielts.points_daily_cap', $family->id));
        $this->assertSame(1, $settings->get('ielts.coins_daily_cap', $family->id));
        // Another family keeps the defaults.
        ['family' => $other] = $this->makeFamily('papa');
        $this->assertSame(20, $settings->get('ielts.points_daily_cap', $other->id));
    }

    public function test_family_caps_limit_the_payout(): void
    {
        ['child' => $child, 'device' => $device, 'token' => $token] = $this->kid();
        $family = $child->family;
        $settings = app(Settings::class);
        $settings->set('ielts.points_daily_cap', 3, $family->id);
        $settings->set('ielts.coins_daily_cap', 1, $family->id);
        $settings->set('ielts.mastered_points', 5, $family->id);

        // A perfect 5-word session would pay 5 points + 5 coins; the family caps bring it down to 3 + 1.
        $graded = ['a' => 3, 'b' => 3, 'c' => 3, 'd' => 3, 'e' => 3];
        $this->settle($child, $device, $token, ['session_id' => 's1', 'graded' => $graded, 'new_ids' => array_keys($graded), 'mastered_ids' => ['a']])
            ->assertOk()
            ->assertJsonPath('data.points', 3)
            ->assertJsonPath('data.coins', 1)
            ->assertJsonPath('data.mastered_points', 5)
            ->assertJsonPath('data.capped', true)
            ->assertJsonPath('data.remaining.points', 0)
            ->assertJsonPath('data.remaining.coins', 0);

        $this->assertSame(8, $this->points($child));
        $this->assertSame(1, $this->coins($child));

        // Mastered bonus 0 switches that reward off entirely.
        $settings->set('ielts.mastered_points', 0, $family->id);
        $this->settle($child, $device, $token, ['session_id' => 's2', 'graded' => ['f' => 3], 'new_ids' => [], 'mastered_ids' => ['f']])
            ->assertOk()
            ->assertJsonPath('data.mastered_words', 0)
            ->assertJsonPath('data.mastered_points', 0);

        // The master switch stops every payout without touching the caps.
        $settings->set('ielts.rewards_enabled', false, $family->id);
        $this->settle($child, $device, $token, ['session_id' => 's3', 'graded' => ['g' => 3], 'new_ids' => ['g']])
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.settled', false)
            ->assertJsonPath('data.points', 0);
        $this->assertSame(8, $this->points($child));
    }

    public function test_kid_cannot_touch_parent_settings(): void
    {
        ['child' => $child, 'device' => $device, 'token' => $token] = $this->kid();

        $this->actingAsKid($child, $device, $token)
            ->putJson('/api/v1/parent/ielts/settings', ['points_daily_cap' => 200])
            ->assertForbidden();
    }

    public function test_mastered_words_pay_once_each(): void
    {
        ['child' => $child, 'device' => $device, 'token' => $token] = $this->kid();

        $body = [
            'session_id' => 'm1',
            'graded' => ['abandon' => 3],
            'new_ids' => [],
            'mastered_ids' => ['abandon', 'volcano'],
        ];
        $this->settle($child, $device, $token, $body)
            ->assertOk()
            ->assertJsonPath('data.points', 1)
            ->assertJsonPath('data.mastered_words', 2)
            ->assertJsonPath('data.mastered_points', 4);
        $this->assertSame(5, $this->points($child));

        $this->settle($child, $device, $token, ['session_id' => 'm2', 'graded' => ['x' => 1], 'new_ids' => [], 'mastered_ids' => ['volcano', 'festival']])
            ->assertOk()
            ->assertJsonPath('data.points', 0)
            ->assertJsonPath('data.mastered_words', 1)
            ->assertJsonPath('data.mastered_points', 2);
        $this->assertSame(7, $this->points($child));
    }

    public function test_rejects_bad_payloads(): void
    {
        ['child' => $child, 'device' => $device, 'token' => $token] = $this->kid();

        $this->settle($child, $device, $token, ['session_id' => 'x', 'graded' => ['a' => 9], 'new_ids' => []])
            ->assertStatus(422);
        $this->settle($child, $device, $token, ['session_id' => 'bad id!', 'graded' => ['a' => 3], 'new_ids' => []])
            ->assertStatus(422);
        $this->settle($child, $device, $token, ['session_id' => 'x', 'graded' => [], 'new_ids' => []])
            ->assertStatus(422);
    }

    /**
     * @return array{child: ChildProfile, device: TrustedDevice, token: string}
     */
    private function kid(): array
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        return compact('child', 'device', 'token');
    }

    /** @param array<string, mixed> $body */
    private function settle(ChildProfile $child, TrustedDevice $device, string $token, array $body): TestResponse
    {
        return $this->actingAsKid($child, $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kid/ielts/sessions/settle', $body);
    }

    private function points(ChildProfile $child): int
    {
        return (int) DB::table('point_accounts')->where('child_id', $child->id)->value('balance');
    }

    private function coins(ChildProfile $child): int
    {
        return (int) DB::table('coin_accounts')->where('child_id', $child->id)->value('balance');
    }
}
