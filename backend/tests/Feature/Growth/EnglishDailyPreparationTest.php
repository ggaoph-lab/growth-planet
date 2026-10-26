<?php

namespace Tests\Feature\Growth;

use App\Modules\Core\Settings\Settings;
use App\Modules\English\Services\EnglishAiCoach;
use App\Modules\English\Services\EnglishDailyPreparation;
use App\Modules\English\Services\EnglishFairyTaleService;
use App\Modules\English\Services\EnglishLearningService;
use App\Modules\Task\Services\QwenTtsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnglishDailyPreparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.api_key' => '', 'services.qwen.api_key' => '', 'services.openai.api_key' => '']);
        Http::preventStrayRequests();
    }

    public function test_preparing_content_does_not_mark_heard_or_complete_learning(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $this->travelTo(CarbonImmutable::parse('2026-10-21 05:00', $family->timezone));
        $this->mock(QwenTtsService::class, function ($mock) {
            $mock->shouldReceive('warm')->andReturnUsing(fn ($texts) => array_map(fn ($text) => ['text' => $text, 'audio_base64' => 'cached'], $texts));
        });

        $service = app(EnglishDailyPreparation::class);
        $result = $service->prepare($child);
        $this->assertTrue($result['ready']);
        $this->assertSame(0, $result['words']);
        $this->assertNull($service->prepare($child));
        $this->assertDatabaseHas('english_ai_records', ['child_id' => $child->id, 'purpose' => 'fairy_tale_day', 'business_date' => '2026-10-21']);
        $this->assertSame(0, DB::table('english_learning_answers')->count());
        $this->assertSame(0, DB::table('english_learning_progress')->count());
        $payload = app(EnglishFairyTaleService::class)->forChild($child);
        $this->assertTrue($payload['available']);
        $this->assertFalse($payload['heard']);
        $plan = app(EnglishLearningService::class)->plan($child);
        $this->assertSame(0, $plan['new_words_completed']);
        $this->assertFalse($plan['review_unlocked']);
        Http::assertNothingSent();
    }

    public function test_family_timezone_controls_preparation_and_paused_children_are_skipped(): void
    {
        ['family' => $family] = $this->makeFamily();
        $family->timezone = 'Asia/Shanghai';
        $family->save();
        $child = $this->makeChild($family);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 20:59', 'UTC'));
        $this->assertNull(app(EnglishDailyPreparation::class)->prepare($child));
        $this->assertDatabaseCount('english_ai_records', 0);
        $child->status = 'paused';
        $child->save();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 21:00', 'UTC'));
        $this->artisan('english:prepare-day')->assertSuccessful();
        $this->assertDatabaseCount('english_ai_records', 0);
    }

    public function test_failed_audio_is_retried_after_cooldown_at_most_three_times(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $this->travelTo(CarbonImmutable::parse('2026-10-21 05:00', $family->timezone));
        $this->mock(QwenTtsService::class, function ($mock) {
            $mock->shouldReceive('warm')->andReturnUsing(fn ($texts) => array_map(fn ($text) => ['text' => $text, 'audio_base64' => null], $texts));
        });
        $service = app(EnglishDailyPreparation::class);
        $this->assertFalse($service->prepare($child)['ready']);
        $this->assertNull($service->prepare($child));
        $this->travel(30)->minutes();
        $this->assertFalse($service->prepare($child)['ready']);
        $this->travel(30)->minutes();
        $this->assertFalse($service->prepare($child)['ready']);
        $this->travel(30)->minutes();
        $this->assertNull($service->prepare($child));
    }

    public function test_prepared_day_retries_only_in_background_and_restores_context_after_errors(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        config(['services.openai.api_key' => 'test-key', 'services.openai.model' => 'test-model']);
        app(Settings::class)->set('ai.provider', 'openai', $family->id);
        $this->travelTo(CarbonImmutable::parse('2026-10-21 08:00', $family->timezone));
        $coach = app(EnglishAiCoach::class);
        $record = (object) ['status' => 'local', 'provider' => 'openai', 'model' => $coach->selectedModel($child), 'business_date' => '2026-10-21', 'created_at' => now()->subHour()];
        $this->assertTrue($coach->shouldRetryLocal($child, $record));
        Cache::put(EnglishDailyPreparation::cacheKey($child->id, '2026-10-21', 'openai', $coach->selectedModel($child)), ['attempts' => 1], 3600);
        $this->assertFalse($coach->shouldRetryLocal($child, $record));
        $this->assertTrue($coach->preparingDay(fn () => $coach->shouldRetryLocal($child, $record)));
        try {
            $coach->preparingDay(fn () => throw new \RuntimeException('test'));
        } catch (\RuntimeException) {
        }
        $this->assertFalse($coach->shouldRetryLocal($child, $record));
    }
}
