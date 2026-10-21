<?php

namespace Tests\Feature\Growth;

use App\Modules\Core\Settings\Settings;
use App\Modules\English\Services\EnglishContentGate;
use App\Modules\English\Services\EnglishVocabularyService;
use App\Modules\English\Services\EnglishWordUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnglishWordUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_phrase_is_not_labelled_as_one_word(): void
    {
        $gate = app(EnglishContentGate::class);
        $card = $gate->probeCard(['kind' => 'word', 'text' => 'a red apple', 'meaning' => '一个红苹果', 'options' => ['一个红苹果', '一只小猫', '一条小鱼']], [], 3);
        $this->assertSame('phrase', $card['kind']);
        $this->assertSame('', $card['pos']);
        $this->assertSame('', $card['ipa']);
    }

    public function test_daily_word_cards_have_offline_companions_without_calling_ai(): void
    {
        config(['services.gemini.api_key' => '', 'services.qwen.api_key' => '', 'services.openai.api_key' => '']);
        Http::preventStrayRequests();
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $date = now($family->timezone)->toDateString();
        $cards = app(EnglishVocabularyService::class)->dailyWordPack($child, $date);
        $this->assertCount(10, $cards);
        foreach ($cards as $card) {
            $this->assertSame('word', $card['kind']);
            $this->assertNotNull($card['usage']);
            $this->assertArrayHasKey('phrase', $card['usage']);
            $this->assertArrayHasKey('sentence', $card['usage']);
        }
        $this->assertSame(0, DB::table('english_learning_answers')->count());
        Http::assertNothingSent();
    }

    public function test_background_examples_are_saved_and_reused_without_request_time_generation(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $date = now($family->timezone)->toDateString();
        $example = ['word' => 'bird', 'phrase' => ['text' => 'a blue bird', 'meaning' => '一只蓝色的小鸟'], 'sentence' => ['text' => 'I see a blue bird.', 'meaning' => '我看见一只蓝色的小鸟。']];
        config(['services.openai.api_key' => 'test-key', 'services.openai.base_url' => 'https://openai.test/v1']);
        app(Settings::class)->set('ai.provider', 'openai', $family->id);
        Http::fake(['https://openai.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['items' => [$example]])]]]])]);
        $service = app(EnglishWordUsage::class);
        $cards = [['key' => 'word:bird', 'kind' => 'word', 'text' => 'bird', 'meaning' => '鸟']];
        $prepared = $service->prepare($child, $date, $cards);
        $this->assertSame('a blue bird', $prepared[0]['usage']['phrase']['text']);
        $this->assertSame($prepared, $service->attach($child, $date, $cards));
        $this->assertSame($prepared, $service->prepare($child, $date, $cards));
        $this->assertDatabaseHas('english_ai_records', ['child_id' => $child->id, 'purpose' => 'word_usage_day', 'status' => 'ai']);
        Http::assertSentCount(1);
    }

    public function test_examples_must_include_target_word_and_keep_phrase_distinct_from_sentence(): void
    {
        $service = app(EnglishWordUsage::class);
        foreach (config('english_word_usage') as $word => $row) {
            $this->assertNotNull($service->validate($word, ['phrase' => ['text' => $row[0], 'meaning' => $row[1]], 'sentence' => ['text' => $row[2], 'meaning' => $row[3]]]), $word);
        }
        $this->assertNull($service->validate('bird', ['phrase' => ['text' => 'a red apple', 'meaning' => '一个红苹果'], 'sentence' => ['text' => 'I see a bird.', 'meaning' => '我看见一只鸟。']]));
        $this->assertNull($service->validate('bird', ['phrase' => ['text' => 'I see a bird.', 'meaning' => '我看见一只鸟。'], 'sentence' => ['text' => 'I see a bird.', 'meaning' => '我看见一只鸟。']]));
    }
}
