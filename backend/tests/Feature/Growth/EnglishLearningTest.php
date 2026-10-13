<?php

namespace Tests\Feature\Growth;

use App\Modules\English\Services\EnglishLearningService;
use App\Modules\Ledger\Models\RewardPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EnglishLearningTest extends TestCase
{
    use RefreshDatabase;

    public function test_learning_rewards_once_and_schedules_spaced_reviews(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 23:30:00', 'Asia/Shanghai'));
        $firstPlan = $service->plan($child);
        $this->assertCount(10, $firstPlan['items']);
        $this->assertCount(3, array_values(array_filter($firstPlan['items'], fn ($item) => empty($item['probe']))));
        $first = $service->answer($child, 'apple', '苹果');
        $this->assertTrue($first['correct']);
        $this->assertSame(1, $first['coins']);
        $this->assertSame('2026-10-14', $first['next_review']);
        try {
            $service->answer($child, 'apple', '苹果');
            $this->fail('A completed card must not pay again.');
        } catch (ValidationException) {
            $this->assertSame(1, DB::table('reward_grants')->where('policy_code', 'english.practice')->count());
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-14 00:30:00', 'Asia/Shanghai'));
        $review = $service->plan($child)['items'][0];
        $this->assertTrue($review['review']);
        $this->assertContains('apple', $review['options']);
        $second = $service->answer($child, 'apple', 'apple');
        $this->assertSame('2026-10-17', $second['next_review']);
    }

    public function test_wrong_answers_reset_progress_and_new_cards_are_limited(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(EnglishLearningService::class);
        $wrong = $service->answer($child, 'apple', '香蕉');
        $this->assertFalse($wrong['correct']);
        $this->assertSame(0, $wrong['coins']);
        $this->assertSame(0, DB::table('reward_grants')->count());
        $this->assertTrue($service->answer($child, 'apple', 'apple')['correct']);
        $service->answer($child, 'banana', '香蕉');
        $service->answer($child, 'like-apples', '我喜欢苹果。');
        $after = $service->plan($child);
        $this->assertSame(3, $after['completed']);
        $this->assertNotEmpty($after['items']);
        $this->assertTrue($after['items'][0]['probe']);
        $probe = $after['items'][0];
        $wrongOption = collect($probe['options'])->first(fn ($option) => $option !== $probe['meaning']);
        $probeResult = $service->answer($child, $probe['key'], (string) $wrongOption);
        $this->assertFalse($probeResult['correct']);
        $this->assertSame(0, $probeResult['coins']);
        $this->assertSame(3, $probeResult['plan']['completed']);
        $this->assertGreaterThan(0, $probeResult['plan']['vocabulary']['estimated_size']);
        $other = $this->makeChild($family, ['nickname' => '另一个孩子']);
        $this->assertSame(0, $service->plan($other)['learned']);
    }

    public function test_learning_continues_after_ten_and_rewards_still_cap(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        RewardPolicy::query()->where('family_id', $family->id)->where('code', 'english.practice')->update(['is_active' => false]);
        $service = app(EnglishLearningService::class);
        foreach (array_slice(config('english_learning.items'), 0, 12) as $item) {
            DB::table('english_learning_progress')->insert(['child_id' => $child->id, 'item_key' => $item['key'], 'stage' => 1, 'introduced_date' => '2026-01-01', 'due_date' => '2026-01-02']);
        }
        for ($i = 0; $i < 10; $i++) {
            $card = $service->plan($child)['items'][0];
            $answer = $service->answer($child, $card['key'], $card['text']);
            $this->assertSame(0, $answer['coins']);
        }
        $afterTen = $service->plan($child);
        $this->assertNotEmpty($afterTen['items']);
        $this->assertSame(2, $afterTen['due']);
        $this->assertDatabaseCount('reward_grants', 0);

        RewardPolicy::query()->where('family_id', $family->id)->where('code', 'english.practice')->update([
            'is_active' => true,
            'max_per_day' => EnglishLearningService::REWARD_DAILY_LIMIT,
        ]);
        foreach ($afterTen['items'] as $card) {
            if (! empty($card['probe']) || ! empty($card['challenge'])) {
                continue;
            }
            $service->answer($child, $card['key'], $card['review'] ? $card['text'] : $card['meaning']);
        }
        $coinTotal = (int) DB::table('reward_grants')->where('policy_code', 'english.practice')->sum('coin_amount');
        $this->assertGreaterThan(0, $coinTotal);
        $this->assertLessThanOrEqual(EnglishLearningService::REWARD_DAILY_LIMIT, $coinTotal);
    }

    public function test_kid_api_validates_answers_and_replays_idempotently(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->actingAsKid($child, $device, $token)->getJson('/api/v1/kid/english/learning')->assertOk()->assertJsonCount(10, 'data.items');
        $key = (string) Str::uuid();
        for ($i = 0; $i < 2; $i++) {
            $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/kid/english/learning/answer', ['key' => 'apple', 'answer' => '苹果'])->assertOk()->assertJsonPath('data.correct', true);
        }
        $this->assertDatabaseCount('english_learning_answers', 1);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/kid/english/learning/answer', ['key' => 'banana', 'answer' => 'made up'])->assertUnprocessable();
    }
}
