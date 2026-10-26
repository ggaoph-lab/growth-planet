<?php

namespace Tests\Feature\Growth;

use App\Modules\Core\Settings\Settings;
use App\Modules\EnglishBeta\DailyMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class EnglishBetaTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_score_grants_proportional_rewards_once(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $settings = app(Settings::class);
        $settings->set('english_beta.enable_scoring', true, $family->id, $parent->id);
        $settings->set('english_beta.max_point_reward', 10, $family->id, $parent->id);
        $settings->set('english_beta.max_coin_reward', 10, $family->id, $parent->id);
        $settings->set('ai.qwen_api_key', 'test-key', $family->id, $parent->id);
        Http::fake(['dashscope.aliyuncs.com/*' => Http::response(['choices' => [[
            'message' => ['content' => '{"score":90,"feedback":"表达很清楚，继续加油！"}'],
        ]]], 200)]);
        $this->actingAsKid($child, $device, $token);
        $id = $this->postJson('/api/v1/kid/english-beta/sessions')->assertCreated()->json('data.id');
        DB::table('english_beta_turns')->insert([
            'session_id' => $id, 'speaker' => 'child', 'text' => 'I would like an apple, please.',
            'provider' => 'local', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/kid/english-beta/sessions/$id/finish")->assertOk()
            ->assertJsonPath('data.score', 90)->assertJsonPath('data.point_reward', 9)->assertJsonPath('data.coin_reward', 9);
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/finish")->assertOk()
            ->assertJsonPath('data.point_reward', 9);
        $this->assertSame(1, DB::table('reward_grants')->where('grant_key', 'english_beta:session:'.$id)->count());
        $this->assertSame(9, (int) DB::table('point_ledger')->where('source_type', 'english_beta_session')->where('source_id', $id)->sum('amount'));
        $this->assertSame(9, (int) DB::table('coin_ledger')->where('source_type', 'english_beta_session')->where('source_id', $id)->sum('amount'));
        Http::assertSentCount(1);
    }

    public function test_ai_scoring_without_key_does_not_award(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $settings = app(Settings::class);
        $settings->set('english_beta.enable_scoring', true, $family->id, $parent->id);
        $settings->set('english_beta.max_point_reward', 10, $family->id, $parent->id);
        $this->actingAsKid($child, $device, $token);
        $id = $this->postJson('/api/v1/kid/english-beta/sessions')->assertCreated()->json('data.id');
        DB::table('english_beta_turns')->insert([
            'session_id' => $id, 'speaker' => 'child', 'text' => 'An apple, please.',
            'provider' => 'local', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/finish")->assertOk()
            ->assertJsonPath('data.score', null)->assertJsonPath('data.score_status', 'unavailable')
            ->assertJsonPath('data.point_reward', 0);
        $this->assertSame(0, DB::table('reward_grants')->where('source_type', 'english_beta_session')->count());
    }

    public function test_child_can_look_up_a_word_without_an_ai_key(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->actingAsKid($child, $device, $token);

        $this->postJson('/api/v1/kid/english-beta/words/lookup', ['word' => 'tomorrow', 'context' => 'What would you like tomorrow?'])
            ->assertOk()->assertJsonPath('data.meaning_zh', '明天')->assertJsonPath('data.pos_zh', '时间副词');
        $this->postJson('/api/v1/kid/english-beta/words/lookup', ['word' => 'broccoli', 'context' => 'Find the broccoli.'])
            ->assertOk()->assertJsonPath('data.meaning_zh', '西兰花')->assertJsonPath('data.pos_zh', '名词');
        $this->postJson('/api/v1/kid/english-beta/words/lookup', ['word' => 'unknownword', 'context' => 'unknownword'])
            ->assertOk()->assertJsonPath('data.meaning_zh', null);
    }

    public function test_two_daily_scenes_allow_one_choice_and_new_scenes_complete(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-27 10:00:00', 'Asia/Shanghai'));
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->actingAsKid($child, $device, $token);

        $home = $this->getJson('/api/v1/kid/english-beta')->assertOk();
        $this->assertSame(['supermarket', 'bakery'], array_column($home->json('data.scenes'), 'code'));
        $bakery = $this->postJson('/api/v1/kid/english-beta/sessions', ['scene' => 'bakery'])->assertCreated();
        $id = $bakery->json('data.id');
        $this->getJson('/api/v1/kid/english-beta')->assertJsonPath('data.selected_scene', 'bakery');
        $this->postJson('/api/v1/kid/english-beta/sessions', ['scene' => 'supermarket'])->assertStatus(409);
        foreach ($bakery->json('data.scene_data.words') as $word) {
            $this->postJson("/api/v1/kid/english-beta/sessions/$id/listen", ['answer' => $word])->assertOk();
        }
        $order = $this->postJson("/api/v1/kid/english-beta/sessions/$id/turns", ['text' => 'I would like cake, please.'])->assertOk();
        $this->assertContains('ask_for_item', $order->json('data.completed_tasks'));
        $price = $this->postJson("/api/v1/kid/english-beta/sessions/$id/turns", ['text' => 'How much is it?'])->assertOk();
        $this->assertContains('checkout', $price->json('data.completed_tasks'));
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/finish")->assertOk();

        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-28 10:00:00', 'Asia/Shanghai'));
        $this->assertSame(['restaurant', 'toy_store'], array_column($this->getJson('/api/v1/kid/english-beta')->json('data.scenes'), 'code'));
        $toy = $this->postJson('/api/v1/kid/english-beta/sessions', ['scene' => 'toy_store'])->assertCreated();
        $this->assertSame(['ball', 'puzzle', 'robot', 'kite'], $toy->json('data.scene_data.words'));
    }

    public function test_restaurant_menu_rotates_daily(): void
    {
        $menus = app(DailyMenu::class);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-26 10:00:00', 'Asia/Shanghai'));
        $first = $menus->restaurantToday('Asia/Shanghai');
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-27 10:00:00', 'Asia/Shanghai'));
        $this->assertNotSame($first, $menus->restaurantToday('Asia/Shanghai'));
    }

    /** Existing sessions without a menu keep the original P0 supermarket route. */
    private function legacyStart(): \Illuminate\Testing\TestResponse
    {
        $response = $this->postJson('/api/v1/kid/english-beta/sessions')->assertCreated();
        $id = $response->json('data.id');
        DB::table('english_beta_sessions')->where('id', $id)->update(['menu_code' => null]);
        $session = DB::table('english_beta_sessions')->find($id);
        foreach (['apple', 'banana', 'milk', 'bread'] as $word) {
            DB::table('english_beta_vocabulary')->insertOrIgnore([
                'family_id' => $session->family_id, 'child_id' => $session->child_id,
                'word' => $word, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $response;
    }

    public function test_daily_menu_collects_ingredients_before_dialogue_and_saves_tomorrow_choice(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-26 10:00:00', 'Asia/Shanghai'));
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->actingAsKid($child, $device, $token);

        $start = $this->postJson('/api/v1/kid/english-beta/sessions')->assertCreated();
        $id = $start->json('data.id');
        $menu = $start->json('data.scene_data.menu');
        $this->assertSame(app(DailyMenu::class)->today($family->timezone), $menu['code']);
        $this->assertCount(4, $menu['ingredients']);
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/turns", ['text' => 'I would like food.'])->assertStatus(409);
        foreach ($menu['words'] as $index => $word) {
            $reply = $this->postJson("/api/v1/kid/english-beta/sessions/$id/listen", ['answer' => $word])->assertOk();
            if ($index < 3) $this->assertNotContains('collect_ingredients', $reply->json('data.completed_tasks'));
        }
        $this->assertContains('collect_ingredients', $reply->json('data.completed_tasks'));
        $request = $this->postJson("/api/v1/kid/english-beta/sessions/$id/turns", ['text' => 'I would like '.$menu['words'][0].', please.'])->assertOk();
        $this->assertContains('ask_for_ingredient', $request->json('data.completed_tasks'));
        $checkout = $this->postJson("/api/v1/kid/english-beta/sessions/$id/turns", ['text' => 'How much is it?'])->assertOk();
        $this->assertContains('checkout', $checkout->json('data.completed_tasks'));
        $this->assertStringContainsString('tomorrow', strtolower($checkout->json('data.text')));
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/turns", ['text' => 'How are you?'])->assertStatus(409);
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/hint")->assertStatus(409);
        $choice = $start->json('data.tomorrow_options.0');
        $this->assertCount(3, $start->json('data.tomorrow_options'));
        $selected = $this->postJson("/api/v1/kid/english-beta/sessions/$id/turns", ['text' => 'I would like '.$choice['title_en'].' tomorrow.'])->assertOk();
        $this->assertStringContainsString('Tomorrow we will make '.$choice['title_en'], $selected->json('data.text'));
        $this->assertDatabaseHas('english_beta_menu_choices', [
            'child_id' => $child->id, 'menu_date' => '2026-10-27', 'menu_code' => $choice['code'],
        ]);
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/finish")->assertOk();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-27 10:00:00', 'Asia/Shanghai'));
        $next = $this->postJson('/api/v1/kid/english-beta/sessions')->assertCreated();
        $next->assertJsonPath('data.scene_data.menu.code', $choice['code']);
        $this->assertStringContainsString('Yesterday we bought', $next->json('data.greeting'));
    }

    public function test_supermarket_menu_changes_on_adjacent_days_without_a_choice(): void
    {
        $menus = app(DailyMenu::class);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-26 10:00:00', 'Asia/Shanghai'));
        $today = $menus->today('Asia/Shanghai');
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-27 10:00:00', 'Asia/Shanghai'));
        $this->assertNotSame($today, $menus->today('Asia/Shanghai'));
    }

    public function test_automatic_menu_skips_yesterdays_saved_choice(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-26 10:00:00', 'Asia/Shanghai'));
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->actingAsKid($child, $device, $token);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-27 10:00:00', 'Asia/Shanghai'));
        $automatic = app(DailyMenu::class)->today($family->timezone);
        DB::table('english_beta_menu_choices')->insert([
            'child_id' => $child->id, 'menu_date' => '2026-10-26', 'menu_code' => $automatic,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $start = $this->postJson('/api/v1/kid/english-beta/sessions')->assertCreated();
        $this->assertNotSame($automatic, $start->json('data.scene_data.menu.code'));
    }

    public function test_daily_menu_uses_contextual_qwen_when_configured(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $settings = app(Settings::class);
        $settings->set('english_beta.allow_ai', true, $family->id);
        $settings->set('english_beta.provider', 'qwen', $family->id);
        $settings->set('ai.qwen_api_key', 'test-only-secret', $family->id);
        $this->actingAsKid($child, $device, $token);
        $start = $this->postJson('/api/v1/kid/english-beta/sessions')->assertCreated();
        $id = $start->json('data.id');
        foreach ($start->json('data.scene_data.menu.words') as $word) {
            $this->postJson("/api/v1/kid/english-beta/sessions/$id/listen", ['answer' => $word])->assertOk();
        }
        Http::fake(['*' => Http::response(['choices' => [['message' => [
            'content' => '{"en":"Sure! That sounds tasty.","zh":"好呀！听起来很美味。"}',
        ]]]])]);
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/turns", ['text' => 'I had soup yesterday.'])
            ->assertOk()->assertJsonPath('data.provider', 'qwen');
        Http::assertSent(function ($request) use ($start) {
            $system = $request['messages'][0]['content'];
            return str_contains($system, 'natural American English')
                && str_contains($system, $start->json('data.scene_data.menu.title_en'))
                && str_contains($system, 'past tense')
                && str_contains($system, 'future tense');
        });
    }

    public function test_child_can_finish_mock_supermarket_without_touching_existing_english_or_rewards(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        app(Settings::class)->set('english_beta.allow_voice_input', true, $family->id);
        $this->actingAsKid($child, $device, $token);
        $this->getJson('/api/v1/kid/english-beta')->assertOk()->assertJsonPath('data.settings.minutes_per_session', 20);
        $id = $this->legacyStart()->assertCreated()->json('data.id');
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/listen", ['answer' => 'apple'])->assertOk()->assertJsonPath('data.correct', true)->assertJsonPath('data.next_target', 'banana');
        $this->getJson('/api/v1/kid/english-beta')->assertOk()->assertJsonPath('data.active_session.listening_target', 'banana')
            ->assertJsonPath('data.active_session.listening_progress.completed', 1);
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/listen", ['answer' => 'banana'])->assertOk()->assertJsonPath('data.correct', true)->assertJsonPath('data.next_target', 'milk');
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/listen", ['answer' => 'milk'])->assertOk()->assertJsonPath('data.correct', true)->assertJsonPath('data.next_target', 'bread');
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/listen", ['answer' => 'bread'])->assertOk()->assertJsonPath('data.correct', true)
            ->assertJsonPath('data.next_target', null)->assertJsonPath('data.listening_progress.completed', 4);
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/listen", ['answer' => 'apple'])->assertStatus(409);
        $this->getJson('/api/v1/kid/english-beta')->assertOk()->assertJsonPath('data.active_session.practice_word', 'milk');
        $milk = $this->postJson("/api/v1/kid/english-beta/sessions/$id/turns", ['text' => 'I would like milk, please.'])
            ->assertOk()->assertJsonPath('data.provider', 'mock');
        $this->assertStringContainsString('How much is it', $milk->json('data.text'));
        $this->assertStringNotContainsString('Can you say: I would like milk', $milk->json('data.text'));
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/finish", ['completed_tasks' => ['checkout']])->assertOk();
        $this->assertDatabaseHas('english_beta_vocabulary', ['child_id' => $child->id, 'word' => 'milk', 'understood' => true, 'spoken' => false, 'used_in_sentence' => true]);
        $this->assertDatabaseHas('english_beta_tasks', ['session_id' => $id, 'code' => 'ask_for_milk']);
        $this->assertDatabaseMissing('english_beta_tasks', ['session_id' => $id, 'code' => 'checkout']);
        $this->assertSame(0, DB::table('reward_grants')->count());
    }

    public function test_partner_guides_next_mission_instead_of_repeating_greeting(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->actingAsKid($child, $device, $token);
        $id = $this->legacyStart()->assertCreated()->json('data.id');
        $reply = $this->postJson("/api/v1/kid/english-beta/sessions/$id/turns", ['text' => 'I want to buy an apple.'])
            ->assertOk()->json('data.text');
        $this->assertStringContainsString('milk', $reply);
        $this->assertStringNotContainsString('Welcome to the supermarket', $reply);
        $this->assertStringNotContainsString('I would like apple', $reply);
        $milk = $this->postJson("/api/v1/kid/english-beta/sessions/$id/turns", ['text' => 'I would like milk, please.'])->assertOk();
        $this->assertStringContainsString('How much is it', $milk->json('data.text'));
        $checkout = $this->postJson("/api/v1/kid/english-beta/sessions/$id/turns", ['text' => 'How much is it?'])->assertOk();
        $this->assertContains('checkout', $checkout->json('data.completed_tasks'));
        $this->assertStringContainsString('two dollars', $checkout->json('data.text'));
    }

    public function test_help_me_suggests_a_reply_without_claiming_child_practice(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->actingAsKid($child, $device, $token);
        $id = $this->legacyStart()->assertCreated()->json('data.id');
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/hint")->assertStatus(409);
        foreach (['apple', 'banana', 'milk', 'bread'] as $word) {
            $this->postJson("/api/v1/kid/english-beta/sessions/$id/listen", ['answer' => $word])
                ->assertOk()->assertJsonPath('data.correct', true);
        }
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/hint")->assertOk()
            ->assertJsonPath('data.text', 'I would like milk, please.')
            ->assertJsonPath('data.provider', 'mock');
        $this->getJson('/api/v1/kid/english-beta')->assertOk()
            ->assertJsonPath('data.active_session.turns.0.speaker', 'hint');
        $this->assertDatabaseMissing('english_beta_tasks', ['session_id' => $id, 'code' => 'ask_for_milk']);
        $this->assertDatabaseHas('english_beta_vocabulary', [
            'child_id' => $child->id, 'word' => 'milk', 'spoken' => false, 'used_in_sentence' => false,
        ]);
        $this->assertSame(0, DB::table('english_beta_turns')->where('session_id', $id)->where('speaker', 'child')->count());

        $reply = $this->postJson("/api/v1/kid/english-beta/sessions/$id/turns", ['text' => 'I would like milk, please.'])
            ->assertOk();
        $this->assertContains('ask_for_milk', $reply->json('data.completed_tasks'));
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/hint")->assertOk()
            ->assertJsonPath('data.text', 'How much is it?');
        $this->assertDatabaseMissing('english_beta_tasks', ['session_id' => $id, 'code' => 'checkout']);
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson("/api/v1/kid/english-beta/sessions/$id/hint")->assertOk();
        }
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/hint")->assertStatus(429);
    }

    public function test_help_me_can_use_qwen_without_marking_a_child_turn(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $settings = app(Settings::class);
        $settings->set('english_beta.allow_ai', true, $family->id);
        $settings->set('english_beta.provider', 'qwen', $family->id);
        $settings->set('ai.qwen_api_key', 'test-only-secret', $family->id);
        $this->actingAsKid($child, $device, $token);
        $id = $this->legacyStart()->assertCreated()->json('data.id');
        foreach (['apple', 'banana', 'milk', 'bread'] as $word) {
            $this->postJson("/api/v1/kid/english-beta/sessions/$id/listen", ['answer' => $word])->assertOk();
        }
        Http::fake(['*' => Http::response(['choices' => [['message' => [
            'content' => '{"en":"I would like milk, please.","zh":"我想要牛奶，谢谢。"}',
        ]]]])]);
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/hint")->assertOk()
            ->assertJsonPath('data.provider', 'qwen')
            ->assertJsonPath('data.text', 'I would like milk, please.');
        Http::assertSent(fn ($request) => $request['model'] === 'qwen-flash'
            && str_contains($request['messages'][0]['content'], 'Suggest exactly one short sentence'));
        $this->assertSame(0, DB::table('english_beta_turns')->where('session_id', $id)->where('speaker', 'child')->count());
    }

    public function test_new_visit_reviews_words_without_erasing_mastery_or_repeating_within_a_visit(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->actingAsKid($child, $device, $token);
        $first = $this->legacyStart()->assertCreated()->json('data.id');
        $this->postJson("/api/v1/kid/english-beta/sessions/$first/listen", ['answer' => 'apple'])
            ->assertOk()->assertJsonPath('data.next_target', 'banana');
        $this->postJson("/api/v1/kid/english-beta/sessions/$first/finish")->assertOk();

        $second = $this->legacyStart()->assertCreated()->json('data.id');
        $this->getJson('/api/v1/kid/english-beta')->assertOk()
            ->assertJsonPath('data.active_session.listening_target', 'banana')
            ->assertJsonPath('data.active_session.listening_progress.completed', 0);
        $this->assertNotSame($first, $second);
        foreach (['banana', 'milk', 'bread', 'apple'] as $index => $word) {
            $response = $this->postJson("/api/v1/kid/english-beta/sessions/$second/listen", ['answer' => $word])
                ->assertOk()->assertJsonPath('data.correct', true)
                ->assertJsonPath('data.listening_progress.completed', $index + 1);
            $this->assertSame(['milk', 'bread', 'apple', null][$index], $response->json('data.next_target'));
        }
        $this->getJson('/api/v1/kid/english-beta')->assertOk()
            ->assertJsonPath('data.active_session.listening_target', null)
            ->assertJsonPath('data.active_session.listening_progress.completed', 4);
        $this->assertDatabaseHas('english_beta_vocabulary', ['child_id' => $child->id, 'word' => 'apple', 'understood' => true]);
    }

    public function test_wrong_listening_answer_and_typed_word_do_not_claim_voice_mastery(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->actingAsKid($child, $device, $token);
        $id = $this->legacyStart()->assertCreated()->json('data.id');
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/listen", ['answer' => 'milk'])->assertOk()->assertJsonPath('data.correct', false);
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/turns", ['text' => 'I would like milk, please.'])->assertOk();
        $this->assertDatabaseHas('english_beta_vocabulary', ['child_id' => $child->id, 'word' => 'milk', 'understood' => false, 'spoken' => false, 'used_in_sentence' => true]);
        $state = $this->getJson('/api/v1/kid/english-beta')->assertOk()->assertJsonPath('data.active_session.id', $id);
        $this->assertNotSame('milk', $state->json('data.active_session.practice_word'));
        $this->assertFalse($state->json('data.settings.allow_voice_input'));
        $this->legacyStart()->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(1, DB::table('english_beta_sessions')->count());
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/turns", ['text' => 'milk', 'input_mode' => 'speech'])->assertForbidden();
    }

    public function test_qwen_audio_draft_marks_spoken_once_and_cannot_be_replayed(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $settings = app(Settings::class);
        // Voice ASR is independent from chat partner AI.
        $settings->set('english_beta.allow_voice_input', true, $family->id);
        $settings->set('english_beta.voice_provider', 'qwen', $family->id);
        $settings->set('english_beta.asr_model', 'qwen3-asr-flash', $family->id);
        $settings->set('english_beta.allow_ai', false, $family->id);
        $settings->set('english_beta.provider', 'mock', $family->id);
        $settings->set('ai.qwen_api_key', 'test-only-secret', $family->id);
        $this->actingAsKid($child, $device, $token);
        $id = $this->legacyStart()->assertCreated()->json('data.id');
        $this->getJson('/api/v1/kid/english-beta')->assertJsonPath('data.settings.voice_available', true)
            ->assertJsonPath('data.settings.voice_provider', 'qwen')
            ->assertJsonPath('data.settings.asr_model', 'qwen3-asr-flash')
            ->assertJsonPath('data.settings.chat_model', 'qwen-flash');
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/turns", ['text' => 'milk', 'input_mode' => 'speech'])->assertForbidden();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'I would like milk, please.']]]])]);
        $wav = 'RIFF'.pack('V', 44).'WAVEfmt '.str_repeat("\0", 40);
        $draft = $this->post("/api/v1/kid/english-beta/sessions/$id/transcribe", [
            'audio' => UploadedFile::fake()->createWithContent('speech.wav', $wav), 'duration_ms' => 1000,
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.text', 'I would like milk, please.')
            ->json('data.voice_draft_id');
        Http::assertSent(fn ($request) => $request['model'] === 'qwen3-asr-flash' && str_starts_with($request['messages'][0]['content'][0]['input_audio']['data'], 'data:audio/'));
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/turns", [
            'text' => 'I would like milk, please.', 'input_mode' => 'speech', 'voice_draft_id' => $draft,
        ])->assertOk()->assertJsonPath('data.provider', 'mock');
        $this->assertDatabaseHas('english_beta_vocabulary', ['child_id' => $child->id, 'word' => 'milk', 'spoken' => true]);
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/turns", [
            'text' => 'I would like milk, please.', 'input_mode' => 'speech', 'voice_draft_id' => $draft,
        ])->assertUnprocessable();
        $this->assertSame(2, DB::table('english_beta_turns')->where('session_id', $id)->count());
    }

    public function test_parent_can_set_chat_and_voice_models_separately(): void
    {
        ['parent' => $parent] = $this->makeFamily();
        $this->actingAs($parent)->putJson('/api/v1/parent/english-beta/settings', [
            'minutes_per_session' => 20,
            'days_per_week' => 5,
            'provider' => 'qwen',
            'chat_model' => 'qwen-plus',
            'allow_ai' => true,
            'token_mode' => 'frugal',
            'allow_voice_input' => true,
            'speech_rate_percent' => 80,
            'voice_provider' => 'qwen',
            'asr_model' => 'qwen3-asr-flash',
        ])->assertOk()
            ->assertJsonPath('data.provider', 'qwen')
            ->assertJsonPath('data.chat_model', 'qwen-plus')
            ->assertJsonPath('data.token_mode', 'frugal')
            ->assertJsonPath('data.voice_provider', 'qwen')
            ->assertJsonPath('data.asr_model', 'qwen3-asr-flash')
            ->assertJsonPath('data.models.tts', 'qwen3-tts-flash-realtime')
            ->assertJsonPath('data.allow_ai', true)
            ->assertJsonPath('data.allow_voice_input', true);
        $this->getJson('/api/v1/parent/english-beta')->assertOk()
            ->assertJsonPath('data.settings.speech_rate_percent', 80);
    }

    public function test_frugal_mode_skips_chat_model_for_mission_turns(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $settings = app(Settings::class);
        $settings->set('english_beta.allow_ai', true, $family->id);
        $settings->set('english_beta.provider', 'qwen', $family->id);
        $settings->set('english_beta.token_mode', 'frugal', $family->id);
        $settings->set('english_beta.chat_model', 'qwen-flash', $family->id);
        $settings->set('ai.qwen_api_key', 'test-only-secret', $family->id);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Should not be used.']]]])]);
        $this->actingAsKid($child, $device, $token);
        $id = $this->legacyStart()->assertCreated()->json('data.id');
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/turns", ['text' => 'I would like milk, please.'])
            ->assertOk()
            ->assertJsonPath('data.provider', 'mock');
        Http::assertNothingSent();
    }

    public function test_other_child_cannot_write_session_and_disabled_beta_is_blocked(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $first = $this->makeChild($family);
        $second = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $id = DB::table('english_beta_sessions')->insertGetId(['family_id' => $family->id, 'child_id' => $first->id, 'scene' => 'supermarket', 'provider' => 'mock', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsKid($second, $device, $token)->postJson("/api/v1/kid/english-beta/sessions/$id/turns", ['text' => 'milk'])->assertNotFound();
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/hint")->assertNotFound();
        app(Settings::class)->set('planets.english_beta', false, $family->id);
        $this->getJson('/api/v1/kid/english-beta')->assertForbidden()->assertJsonPath('error.code', 'PLANET_DISABLED');
    }

    public function test_parent_settings_and_report_are_family_scoped(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        ['parent' => $otherParent] = $this->makeFamily('other-beta-parent');
        app(Settings::class)->set('english_beta.minutes_per_session', 15, $family->id);
        $this->actingAs($otherParent)->getJson('/api/v1/parent/english-beta')->assertOk()->assertJsonPath('data.settings.minutes_per_session', 20)->assertJsonCount(0, 'data.sessions');
    }

    public function test_existing_parent_settings_request_keeps_voice_recognition_off(): void
    {
        ['parent' => $parent] = $this->makeFamily();
        $this->actingAs($parent)->putJson('/api/v1/parent/english-beta/settings', [
            'minutes_per_session' => 15, 'days_per_week' => 4, 'provider' => 'mock', 'allow_ai' => false,
        ])->assertOk()
            ->assertJsonPath('data.allow_voice_input', false)
            ->assertJsonPath('data.chat_model', 'qwen-flash')
            ->assertJsonPath('data.voice_provider', 'qwen')
            ->assertJsonPath('data.asr_model', 'qwen3-asr-flash');
    }

    public function test_restaurant_reuses_vocabulary_and_records_cross_scene_evidence(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->actingAsKid($child, $device, $token);

        $supermarket = $this->legacyStart()->assertCreated()->json('data.id');
        $this->postJson("/api/v1/kid/english-beta/sessions/$supermarket/turns", ['text' => 'I would like milk, please.'])->assertOk();
        $this->postJson("/api/v1/kid/english-beta/sessions/$supermarket/finish")->assertOk();

        // A child chooses one of the two offered scenes each day; the next restaurant day has milk on its menu.
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 10:00:00', 'Asia/Shanghai'));

        $restaurant = $this->postJson('/api/v1/kid/english-beta/sessions', ['scene' => 'restaurant'])
            ->assertCreated()->assertJsonPath('data.scene', 'restaurant')->json('data.id');
        foreach (app(DailyMenu::class)->findRestaurant('soup')['words'] as $word) {
            $this->postJson("/api/v1/kid/english-beta/sessions/$restaurant/listen", ['answer' => $word])
                ->assertOk()->assertJsonPath('data.correct', true);
        }
        $this->postJson("/api/v1/kid/english-beta/sessions/$restaurant/turns", ['text' => 'I would like milk, please.'])
            ->assertOk()->assertJsonPath('data.completed_tasks.1', 'order_food');
        $this->assertDatabaseHas('english_beta_tasks', ['session_id' => $restaurant, 'code' => 'choose_dish']);
        $this->assertDatabaseHas('english_beta_tasks', ['session_id' => $restaurant, 'code' => 'order_food']);
        $this->assertSame(1, DB::table('english_beta_vocabulary')->where('child_id', $child->id)->where('word', 'milk')->count());
        $this->assertSame(2, DB::table('english_beta_word_evidence')->where('child_id', $child->id)
            ->where('word', 'milk')->distinct()->count('scene'));
        $this->postJson('/api/v1/kid/english-beta/sessions', ['scene' => 'toy_store'])->assertStatus(409);
    }

    public function test_parent_report_only_lists_words_practiced_in_two_scenes(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $now = now();
        $first = DB::table('english_beta_sessions')->insertGetId([
            'family_id' => $family->id, 'child_id' => $child->id, 'scene' => 'supermarket',
            'provider' => 'mock', 'started_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $second = DB::table('english_beta_sessions')->insertGetId([
            'family_id' => $family->id, 'child_id' => $child->id, 'scene' => 'restaurant',
            'provider' => 'mock', 'started_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([[$first, 'supermarket', 'milk'], [$second, 'restaurant', 'milk'], [$first, 'supermarket', 'apple']] as [$sessionId, $scene, $word]) {
            DB::table('english_beta_word_evidence')->insert([
                'family_id' => $family->id, 'child_id' => $child->id, 'session_id' => $sessionId,
                'scene' => $scene, 'word' => $word, 'dimension' => 'used_in_sentence',
                'source' => 'text', 'created_at' => $now,
            ]);
        }
        ['family' => $otherFamily] = $this->makeFamily('other-evidence-parent');
        $otherChild = $this->makeChild($otherFamily);
        $otherSession = DB::table('english_beta_sessions')->insertGetId([
            'family_id' => $otherFamily->id, 'child_id' => $otherChild->id, 'scene' => 'restaurant',
            'provider' => 'mock', 'started_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('english_beta_word_evidence')->insert([
            'family_id' => $otherFamily->id, 'child_id' => $otherChild->id, 'session_id' => $otherSession,
            'scene' => 'restaurant', 'word' => 'apple', 'dimension' => 'understood',
            'source' => 'listen', 'created_at' => $now,
        ]);
        $otherSecond = DB::table('english_beta_sessions')->insertGetId([
            'family_id' => $otherFamily->id, 'child_id' => $otherChild->id, 'scene' => 'supermarket',
            'provider' => 'mock', 'started_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('english_beta_word_evidence')->insert([
            'family_id' => $otherFamily->id, 'child_id' => $otherChild->id, 'session_id' => $otherSecond,
            'scene' => 'supermarket', 'word' => 'apple', 'dimension' => 'understood',
            'source' => 'listen', 'created_at' => $now,
        ]);
        $this->actingAs($parent)->getJson('/api/v1/parent/english-beta')->assertOk()
            ->assertJsonCount(1, 'data.transfer_words')
            ->assertJsonPath('data.transfer_words.0.word', 'milk')
            ->assertJsonCount(2, 'data.scene_summary');
    }

    public function test_weekly_plan_counts_real_practice_days_and_recommends_less_used_scene(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-26 10:00:00', 'Asia/Shanghai'));
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family, ['nickname' => '小星']);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->actingAsKid($child, $device, $token);
        $home = $this->getJson('/api/v1/kid/english-beta')->assertOk()
            ->assertJsonPath('data.weekly_plan.practiced_days', 0)
            ->assertJsonPath('data.weekly_plan.target_days', 5);
        $this->assertSame('supermarket', $home->json('data.weekly_plan.suggested_scene'));
        $id = $this->legacyStart()->assertCreated()->json('data.id');
        $this->getJson('/api/v1/kid/english-beta')->assertJsonPath('data.weekly_plan.practiced_days', 0);
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/listen", ['answer' => 'apple'])->assertOk();
        $this->postJson("/api/v1/kid/english-beta/sessions/$id/finish")->assertOk();
        $this->getJson('/api/v1/kid/english-beta')->assertOk()
            ->assertJsonPath('data.weekly_plan.practiced_days', 1)
            ->assertJsonPath('data.weekly_plan.remaining_days', 4)
            ->assertJsonPath('data.weekly_plan.suggested_scene', 'restaurant');
    }

    public function test_parent_weekly_plan_names_children_and_stays_in_family(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family, ['nickname' => '小星']);
        ['family' => $otherFamily] = $this->makeFamily('other-weekly-parent');
        $this->makeChild($otherFamily, ['nickname' => '别家孩子']);
        $this->actingAs($parent)->getJson('/api/v1/parent/english-beta')->assertOk()
            ->assertJsonCount(1, 'data.children')
            ->assertJsonPath('data.children.0.id', $child->id)
            ->assertJsonPath('data.children.0.nickname', '小星')
            ->assertJsonPath('data.children.0.plan.target_days', 5);
    }
}
