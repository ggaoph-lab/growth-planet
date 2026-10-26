<?php

namespace App\Modules\EnglishBeta;

use App\Modules\Core\Settings\Settings;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Services\RewardGrantService;
use App\Modules\Ledger\Services\RewardPolicySeeder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EnglishBetaController
{
    private function options(Settings $settings, int $familyId): array
    {
        return [
            'minutes_per_session' => $settings->get('english_beta.minutes_per_session', $familyId),
            'days_per_week' => $settings->get('english_beta.days_per_week', $familyId),
            'provider' => $settings->get('english_beta.provider', $familyId),
            'chat_model' => $settings->get('english_beta.chat_model', $familyId),
            'allow_ai' => $settings->get('english_beta.allow_ai', $familyId),
            'enable_scoring' => $settings->get('english_beta.enable_scoring', $familyId),
            'max_point_reward' => $settings->get('english_beta.max_point_reward', $familyId),
            'max_coin_reward' => $settings->get('english_beta.max_coin_reward', $familyId),
            'token_mode' => $settings->get('english_beta.token_mode', $familyId),
            'allow_voice_input' => $settings->get('english_beta.allow_voice_input', $familyId),
            'speech_rate_percent' => $settings->get('english_beta.speech_rate_percent', $familyId),
            'voice_provider' => $settings->get('english_beta.voice_provider', $familyId),
            'asr_model' => $settings->get('english_beta.asr_model', $familyId),
            'voice_available' => $this->voiceKey($settings, $familyId) !== '',
            'tts_provider' => $settings->get('tts.provider', $familyId),
            'tts_voice_en' => $settings->get('tts.voice_en', $familyId),
            'models' => [
                'chat' => (string) $settings->get('english_beta.chat_model', $familyId),
                'asr' => (string) $settings->get('english_beta.asr_model', $familyId),
                'tts' => (string) config('services.qwen.tts_model', 'qwen3-tts-flash-realtime'),
            ],
        ];
    }

    private function chatKey(Settings $settings, int $familyId): string
    {
        if (! $settings->get('english_beta.allow_ai', $familyId)
            || $settings->get('english_beta.provider', $familyId) !== 'qwen') return '';

        return (string) $settings->get('ai.qwen_api_key', $familyId);
    }

    private function voiceKey(Settings $settings, int $familyId): string
    {
        // Voice ASR is independent of the chat partner provider / allow_ai switch.
        if (! $settings->get('english_beta.allow_voice_input', $familyId)
            || $settings->get('english_beta.voice_provider', $familyId) !== 'qwen') return '';

        return (string) $settings->get('ai.qwen_api_key', $familyId);
    }

    private function menuFor(int $childId, string $timezone, DailyMenu $menus): string
    {
        $today = CarbonImmutable::now($timezone);
        $date = $today->toDateString();
        $choice = DB::table('english_beta_menu_choices')->where('child_id', $childId)->where('menu_date', $date)->value('menu_code');
        if ($menus->find($choice)) return $choice;

        $automatic = $menus->today($timezone);
        $yesterdayChoice = DB::table('english_beta_menu_choices')->where('child_id', $childId)
            ->where('menu_date', $today->subDay()->toDateString())->value('menu_code');
        if ($automatic === $yesterdayChoice) return $menus->choices($automatic)[0]['code'];

        return $automatic;
    }

    public function home(CurrentContext $context, Settings $settings, SceneMission $mission, WeeklyPlan $plan, DailyMenu $menus, DailySceneSelection $selection): JsonResponse
    {
        $child = $context->child();
        $active = DB::table('english_beta_sessions')->where('child_id', $child->id)->whereNull('ended_at')->latest('id')->first();
        if ($active && $this->expired($active, $settings, $context->familyId())) {
            DB::table('english_beta_sessions')->where('id', $active->id)->whereNull('ended_at')->update(['ended_at' => now(), 'updated_at' => now()]);
            $active = null;
        }
        $todayMenu = $this->menuFor($child->id, $context->family()->timezone, $menus);
        $chosenScene = $selection->selected($child->id, $context->family()->timezone);
        return response()->json(['data' => [
            'settings' => $this->options($settings, $context->familyId()),
            'scenes' => array_values(array_filter($mission->scenes($todayMenu, $menus->restaurantToday($context->family()->timezone)),
                fn ($scene) => in_array($scene['code'], $selection->offered($context->family()->timezone, $chosenScene), true))),
            'selected_scene' => $chosenScene,
            'scene' => ['code' => 'supermarket', 'title' => '超市星球', 'words' => $mission->words('supermarket', $todayMenu)],
            'vocabulary' => DB::table('english_beta_vocabulary')->where('child_id', $child->id)->get(),
            'active_session' => $active ? $this->sessionState($active, $mission, $menus, $context->family()->timezone) : null,
            'weekly_plan' => $plan->forChild($child->id, $context->family()->timezone, (int) $settings->get('english_beta.days_per_week', $context->familyId()), (int) $settings->get('english_beta.minutes_per_session', $context->familyId()), $mission),
        ]]);
    }

    public function lookupWord(Request $request, CurrentContext $context, Settings $settings, WordGlossary $glossary, DailyMenu $menus): JsonResponse
    {
        $data = $request->validate([
            'word' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z]+(?:[\x27’][A-Za-z]+)?$/u'],
            'context' => ['required', 'string', 'max:240'],
        ]);
        $familyId = $context->familyId();
        return response()->json(['data' => $glossary->lookup(
            $data['word'], $data['context'], $this->chatKey($settings, $familyId),
            (string) $settings->get('english_beta.chat_model', $familyId), $menus,
        )]);
    }

    public function start(Request $request, CurrentContext $context, Settings $settings, SceneMission $mission, DailyMenu $menus, DailySceneSelection $selection): JsonResponse
    {
        $data = $request->validate(['scene' => ['sometimes', Rule::in(['supermarket', 'restaurant', 'bakery', 'toy_store'])]]);
        $selected = $selection->selected($context->child()->id, $context->family()->timezone);
        $offered = $selection->offered($context->family()->timezone, $selected);
        $scene = $data['scene'] ?? $offered[0];
        $child = $context->child();
        abort_unless(in_array($scene, $offered, true), 422, 'This scene is not offered today.');
        abort_if($selected !== null && $selected !== $scene, 409, 'Choose the same scene for today.');
        $menuCode = $scene === 'supermarket' ? $this->menuFor($child->id, $context->family()->timezone, $menus)
            : ($scene === 'restaurant' ? $menus->restaurantToday($context->family()->timezone) : null);
        $now = now();
        $session = DB::transaction(function () use ($context, $settings, $child, $now, $mission, $scene, $menuCode, $selection) {
            DB::table('child_profiles')->where('id', $child->id)->lockForUpdate()->first();
            $chosen = $selection->selected($child->id, $context->family()->timezone);
            abort_if($chosen !== null && $chosen !== $scene, 409, 'Choose the same scene for today.');
            $active = DB::table('english_beta_sessions')->where('child_id', $child->id)->whereNull('ended_at')->latest('id')->first();
            if ($active && ! $this->expired($active, $settings, $context->familyId())) return $active;
            if ($active) DB::table('english_beta_sessions')->where('id', $active->id)->update(['ended_at' => $now, 'updated_at' => $now]);
            $id = DB::table('english_beta_sessions')->insertGetId([
                'family_id' => $context->familyId(), 'child_id' => $child->id,
                'scene' => $scene, 'menu_code' => $menuCode, 'provider' => 'mock', 'started_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($mission->words($scene, $menuCode) as $word) {
                DB::table('english_beta_vocabulary')->insertOrIgnore([
                    'family_id' => $context->familyId(), 'child_id' => $child->id,
                    'word' => $word, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            return DB::table('english_beta_sessions')->find($id);
        });
        return response()->json(['data' => $this->sessionState($session, $mission, $menus, $context->family()->timezone) + ['settings' => $this->options($settings, $context->familyId())]], 201);
    }

    private function expired(object $session, Settings $settings, int $familyId): bool
    {
        return CarbonImmutable::parse($session->started_at)->addMinutes((int) $settings->get('english_beta.minutes_per_session', $familyId))->isPast();
    }

    private function sessionState(object $session, SceneMission $mission, DailyMenu $menus, string $timezone): array
    {
        $menuCode = $session->menu_code ?? null;
        $today = CarbonImmutable::parse($session->started_at)->setTimezone($timezone);
        $yesterdayStart = $today->startOfDay()->subDay()->utc();
        $yesterdayEnd = $today->startOfDay()->utc();
        $yesterdayCode = DB::table('english_beta_sessions')
            ->join('english_beta_tasks', 'english_beta_tasks.session_id', '=', 'english_beta_sessions.id')
            ->where('english_beta_sessions.child_id', $session->child_id)
            ->where('scene', 'supermarket')->where('started_at', '>=', $yesterdayStart)
            ->where('started_at', '<', $yesterdayEnd)->whereNotNull('menu_code')
            ->where('english_beta_tasks.code', 'collect_ingredients')->where('english_beta_tasks.completed', true)
            ->orderByDesc('english_beta_sessions.id')->value('english_beta_sessions.menu_code');
        $yesterday = $menus->find($yesterdayCode);
        $base = $mission->scene($session->scene, $menuCode);
        $greeting = $base['greeting'];
        $greetingZh = $base['greeting_zh'] ?? '';
        if ($session->scene === 'supermarket' && $menuCode) {
            $greeting = $yesterday
                ? 'Yesterday we bought ingredients for '.$yesterday['title_en'].'. How was it? '.$greeting
                : 'What did you eat yesterday? '.$greeting;
            $greetingZh = $yesterday
                ? '昨天我们买了做'.$yesterday['title_zh'].'的食材，吃起来怎么样？'.$greetingZh
                : '你昨天吃了什么？'.$greetingZh;
        }
        $tomorrowDate = CarbonImmutable::now($timezone)->addDay()->toDateString();
        $tomorrowCode = DB::table('english_beta_menu_choices')->where('child_id', $session->child_id)
            ->where('menu_date', $tomorrowDate)->value('menu_code');
        if ($session->scene === 'restaurant' && $menuCode) {
            $base['menu'] = $menus->findRestaurant($menuCode);
        }
        return [
            'id' => $session->id,
            'scene' => $session->scene,
            'started_at' => $session->started_at,
            'scene_data' => $base,
            'greeting' => $greeting,
            'greeting_zh' => $greetingZh,
            'tomorrow_options' => $session->scene === 'supermarket' && $menuCode ? $menus->tomorrowChoices($timezone, $menuCode) : [],
            'tomorrow_choice' => $session->scene === 'supermarket' ? $menus->find($tomorrowCode) : null,
            'turns' => DB::table('english_beta_turns')->where('session_id', $session->id)->orderBy('id')->get(['speaker', 'text', 'text_zh'])->all(),
            'completed_tasks' => $mission->completed($session->id),
            'listening_target' => $mission->listeningTarget((int) $session->child_id, $session->scene, (int) $session->id, $menuCode),
            'listening_progress' => $mission->listeningProgress((int) $session->id, $session->scene, $menuCode),
            'practice_word' => $mission->practiceWord((int) $session->child_id, (int) $session->id, $session->scene, $menuCode),
        ];
    }

    private function session(CurrentContext $context, int $id): object
    {
        return DB::table('english_beta_sessions')->where('id', $id)
            ->where('family_id', $context->familyId())->where('child_id', $context->child()->id)->firstOrFail();
    }

    public function listen(Request $request, CurrentContext $context, Settings $settings, SceneMission $mission, int $id): JsonResponse
    {
        $session = $this->session($context, $id);
        abort_if($session->ended_at !== null || $this->expired($session, $settings, $context->familyId()), 409);
        $menuCode = $session->menu_code ?? null;
        $data = $request->validate(['answer' => ['required', Rule::in($mission->words($session->scene, $menuCode))]]);
        $target = $mission->listeningTarget($context->child()->id, $session->scene, $id, $menuCode);
        abort_if($target === null, 409, 'Listening task is complete.');
        $correct = $data['answer'] === $target;
        if ($correct) {
            DB::table('english_beta_vocabulary')->where('child_id', $context->child()->id)->where('word', $target)
                ->update(['understood' => true, 'updated_at' => now()]);
            $mission->evidence($context->familyId(), $context->child()->id, $id, $session->scene, $target, 'understood', 'listen');
            if ($menuCode || in_array($session->scene, ['bakery', 'toy_store'], true) ? $mission->listeningTarget($context->child()->id, $session->scene, $id, $menuCode) === null
                : $target === $mission->words($session->scene)[0]) $mission->complete($id, $mission->firstTask($session->scene, $menuCode));
        }
        return response()->json(['data' => [
            'correct' => $correct,
            'target' => $target,
            'next_target' => $mission->listeningTarget($context->child()->id, $session->scene, $id, $menuCode),
            'listening_progress' => $mission->listeningProgress($id, $session->scene, $menuCode),
            'completed_tasks' => $mission->completed($id),
        ]]);
    }

    public function turn(Request $request, CurrentContext $context, Settings $settings, Partner $partner, SceneMission $mission, DailyMenu $menus, int $id): JsonResponse
    {
        $session = $this->session($context, $id);
        abort_if($session->ended_at !== null, 409);
        $data = $request->validate([
            'text' => ['required', 'string', 'max:240'],
            'input_mode' => ['sometimes', Rule::in(['text', 'speech'])],
            'voice_draft_id' => ['sometimes', 'uuid'],
        ]);
        abort_if(
            ($data['input_mode'] ?? 'text') === 'speech'
                && ! $settings->get('english_beta.allow_voice_input', $context->familyId()),
            403,
        );
        $input = trim($data['text']);
        abort_if($input === '', 422);
        abort_if($this->expired($session, $settings, $context->familyId()), 409, 'This session has ended.');
        $menuCode = $session->menu_code ?? null;
        abort_if($menuCode && $mission->listeningTarget($context->child()->id, $session->scene, $id, $menuCode) !== null, 409, 'Collect the ingredients first.');
        $voiceDraft = null;
        if (($data['input_mode'] ?? 'text') === 'speech') {
            abort_if(! isset($data['voice_draft_id']) || $this->voiceKey($settings, $context->familyId()) === '', 403);
            $voiceDraft = DB::table('english_beta_voice_drafts')->where('id', $data['voice_draft_id'])
                ->where('session_id', $id)->where('child_id', $context->child()->id)
                ->whereNull('consumed_at')->where('expires_at', '>', now())->first();
            abort_if(! $voiceDraft || $voiceDraft->transcript !== $input, 422);
        } else {
            abort_if(isset($data['voice_draft_id']), 422);
        }
        abort_if(DB::table('english_beta_turns')->where('session_id', $id)->where('speaker', 'child')->count() >= 40, 429);
        $unsafe = preg_match('~https?://|@|\b(?:phone|address|password|secret)\b|\d{7,}~i', $input);
        if ($unsafe) {
            return response()->json(['data' => [
                'text' => 'Let us keep shopping. What would you like to buy?',
                'text_zh' => '我们继续买东西吧。你想买什么？',
                'provider' => 'mock',
            ]]);
        }
        $history = DB::table('english_beta_turns')->where('session_id', $id)
            ->whereIn('speaker', ['child', 'partner'])->orderByDesc('id')->limit(6)->get()->reverse()->values()->all();
        $provider = $settings->get('english_beta.provider', $context->familyId());
        $chatModel = (string) $settings->get('english_beta.chat_model', $context->familyId());
        $key = $this->chatKey($settings, $context->familyId());
        $completed = $mission->completed($id);
        $openingLine = $menuCode ? $this->sessionState($session, $mission, $menus, $context->family()->timezone)['greeting'] : null;
        $pendingTask = $mission->taskForUtterance($session->scene, $input, $menuCode);
        if ($session->scene === 'supermarket' && $menuCode && $pendingTask === 'checkout' && ! in_array('ask_for_ingredient', $completed, true)) $pendingTask = null;
        if ($session->scene === 'restaurant' && $menuCode && $pendingTask === 'ask_bill' && ! in_array('order_food', $completed, true)) $pendingTask = null;
        $tomorrowChoice = null;
        if ($session->scene === 'supermarket' && $menuCode && in_array('checkout', $completed, true)) {
            $normalized = mb_strtolower(trim($input, " \t\n\r\0\x0B.!?"));
            foreach ($menus->tomorrowChoices($context->family()->timezone, $menuCode) as $option) {
                $title = mb_strtolower($option['title_en']);
                if (($normalized === $title || str_contains($normalized, $title)
                    && preg_match('/\b(?:i (?:would like|want|will have)|let.s (?:make|have)|we (?:will|can) (?:make|have))\b/i', $input))
                    && ! preg_match('/\b(?:don.t|do not|not|never)\b/i', $input)) {
                    $tomorrowChoice = $option;
                    break;
                }
            }
        }
        if ($session->scene === 'supermarket' && $menuCode && in_array('checkout', $completed, true)
            && ! $this->sessionState($session, $mission, $menus, $context->family()->timezone)['tomorrow_choice']) {
            abort_if($tomorrowChoice === null, 409, 'Choose tomorrow’s meal first.');
        }
        $reply = $partner->reply($session->scene, $provider, $input, $history, $key, [
            'practice_word' => $mission->practiceWord($context->child()->id, $id, $session->scene, $menuCode),
            'completed_tasks' => $completed,
            'pending_task' => $pendingTask,
            'menu' => $session->scene === 'restaurant' ? $menus->findRestaurant($menuCode) : $menus->find($menuCode),
            'opening_line' => $openingLine,
            'tomorrow_choices' => $menus->tomorrowChoices($context->family()->timezone, $menuCode),
            'tomorrow_choice' => $tomorrowChoice,
            'selected_tomorrow' => $this->sessionState($session, $mission, $menus, $context->family()->timezone)['tomorrow_choice'] ?? $tomorrowChoice,
        ], $chatModel, (string) $settings->get('english_beta.token_mode', $context->familyId()));
        $now = now();
        DB::transaction(function () use ($id, $input, $reply, $now, $context, $mission, $data, $session, $voiceDraft, $menuCode, $tomorrowChoice, $pendingTask) {
            if ($voiceDraft) {
                $claimed = DB::table('english_beta_voice_drafts')->where('id', $voiceDraft->id)
                    ->whereNull('consumed_at')->where('expires_at', '>', $now)->update(['consumed_at' => $now, 'updated_at' => $now]);
                abort_if($claimed !== 1, 409);
            }
            DB::table('english_beta_turns')->insert([
                ['session_id' => $id, 'speaker' => 'child', 'text' => $input, 'text_zh' => null, 'provider' => 'local', 'created_at' => $now, 'updated_at' => $now],
                ['session_id' => $id, 'speaker' => 'partner', 'text' => $reply['text'], 'text_zh' => $reply['text_zh'] ?? null, 'provider' => $reply['provider'], 'created_at' => $now, 'updated_at' => $now],
            ]);
            foreach ($mission->words($session->scene, $menuCode) as $word) {
                if (! preg_match('/\b'.preg_quote($word, '/').'\b/i', $input)) continue;
                $query = DB::table('english_beta_vocabulary')->where('child_id', $context->child()->id)->where('word', $word);
                if (($data['input_mode'] ?? 'text') === 'speech') {
                    $query->update(['spoken' => true, 'updated_at' => $now]);
                    $mission->evidence($context->familyId(), $context->child()->id, $id, $session->scene, $word, 'spoken', 'qwen_asr');
                }
                if (preg_match('/\b(?:i|want|like|buy|have|please)\b/i', $input)) {
                    $query->update(['used_in_sentence' => true, 'updated_at' => $now]);
                    $mission->evidence($context->familyId(), $context->child()->id, $id, $session->scene, $word, 'used_in_sentence', ($data['input_mode'] ?? 'text'));
                }
            }
            if ($code = $pendingTask) $mission->complete($id, $code);
            if ($tomorrowChoice) {
                DB::table('english_beta_menu_choices')->updateOrInsert(
                    ['child_id' => $context->child()->id, 'menu_date' => CarbonImmutable::now($context->family()->timezone)->addDay()->toDateString()],
                    ['menu_code' => $tomorrowChoice['code'], 'updated_at' => $now, 'created_at' => $now],
                );
            }
            if ($reply['provider'] !== $session->provider) DB::table('english_beta_sessions')->where('id', $id)->update(['provider' => $reply['provider'], 'updated_at' => $now]);
        });
        return response()->json(['data' => $reply + ['completed_tasks' => $mission->completed($id)]]);
    }

    public function hint(CurrentContext $context, Settings $settings, Partner $partner, SceneMission $mission, int $id): JsonResponse
    {
        $session = $this->session($context, $id);
        abort_if($session->ended_at !== null || $this->expired($session, $settings, $context->familyId()), 409);
        $menuCode = $session->menu_code ?? null;
        abort_if($mission->listeningTarget($context->child()->id, $session->scene, $id, $menuCode) !== null, 409);
        if ($session->scene === 'supermarket' && $menuCode && in_array('checkout', $mission->completed($id), true)
            && ! $this->sessionState($session, $mission, app(DailyMenu::class), $context->family()->timezone)['tomorrow_choice']) {
            abort(409, 'Choose tomorrow’s meal first.');
        }
        abort_if(DB::table('english_beta_turns')->where('session_id', $id)->where('speaker', 'hint')->count() >= 8, 429);

        $history = DB::table('english_beta_turns')->where('session_id', $id)
            ->whereIn('speaker', ['child', 'partner'])->orderByDesc('id')->limit(6)->get()->reverse()->values()->all();
        $hint = $partner->suggestReply(
            $session->scene,
            $history,
            $mission->completed($id),
            $mission->practiceWord($context->child()->id, $id, $session->scene, $menuCode),
            (string) $settings->get('english_beta.provider', $context->familyId()),
            $this->chatKey($settings, $context->familyId()),
            (string) $settings->get('english_beta.chat_model', $context->familyId()),
            $session->scene === 'restaurant' ? app(DailyMenu::class)->findRestaurant($menuCode)
                : ($menuCode ? app(DailyMenu::class)->find($menuCode) : null),
        );
        $now = now();
        DB::table('english_beta_turns')->insert([
            'session_id' => $id, 'speaker' => 'hint',
            'text' => $hint['text'], 'text_zh' => $hint['text_zh'],
            'provider' => $hint['provider'], 'created_at' => $now, 'updated_at' => $now,
        ]);

        return response()->json(['data' => $hint]);
    }

    public function transcribe(Request $request, CurrentContext $context, Settings $settings, VoiceTranscriber $transcriber, int $id): JsonResponse
    {
        $session = $this->session($context, $id);
        abort_if($session->ended_at !== null || $this->expired($session, $settings, $context->familyId()), 409);
        $key = $this->voiceKey($settings, $context->familyId());
        abort_if($key === '', 403);
        $data = $request->validate([
            'audio' => ['required', 'file', 'max:2048'],
            'duration_ms' => ['required', 'integer', 'between:300,15000'],
        ]);
        $file = $data['audio'];
        $mime = $file->getMimeType();
        abort_unless(in_array($mime, ['audio/webm', 'video/webm', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg'], true), 422);
        $audio = file_get_contents($file->getRealPath());
        abort_if($audio === false || strlen($audio) < 32, 422);
        $asrModel = (string) $settings->get('english_beta.asr_model', $context->familyId());
        $transcript = $transcriber->transcribe($audio, $mime === 'video/webm' ? 'audio/webm' : $mime, $key, $asrModel);
        abort_if($transcript === null || preg_match('~https?://|@|\b(?:phone|address|password|secret)\b|\d{7,}~i', $transcript), 422, 'Speech could not be recognized safely.');
        $draftId = (string) Str::uuid();
        DB::table('english_beta_voice_drafts')->where('expires_at', '<', now())->delete();
        DB::table('english_beta_voice_drafts')->insert([
            'id' => $draftId, 'session_id' => $id, 'child_id' => $context->child()->id,
            'transcript' => $transcript, 'expires_at' => now()->addMinutes(2),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['data' => ['text' => $transcript, 'voice_draft_id' => $draftId]]);
    }

    public function finish(Request $request, CurrentContext $context, Settings $settings, SessionScorer $scorer, RewardPolicySeeder $policies, RewardGrantService $rewards, int $id): JsonResponse
    {
        $session = $this->session($context, $id);
        if ($session->ended_at !== null) return response()->json(['data' => $this->finishResult($session)]);

        $familyId = $context->familyId();
        $enabled = (bool) $settings->get('english_beta.enable_scoring', $familyId);
        $hasChildTurn = DB::table('english_beta_turns')->where('session_id', $id)->where('speaker', 'child')->exists();
        $score = $enabled && $hasChildTurn
            ? $scorer->score($id, (string) $settings->get('ai.qwen_api_key', $familyId), (string) $settings->get('english_beta.chat_model', $familyId))
            : null;

        $result = DB::transaction(function () use ($id, $familyId, $enabled, $hasChildTurn, $score, $settings, $policies, $rewards) {
            $locked = DB::table('english_beta_sessions')->where('id', $id)->lockForUpdate()->first();
            if ($locked->ended_at !== null) return $this->finishResult($locked);

            $maxPoints = $score ? (int) $settings->get('english_beta.max_point_reward', $familyId) : 0;
            $maxCoins = $score ? (int) $settings->get('english_beta.max_coin_reward', $familyId) : 0;
            $pointReward = $score ? intdiv($maxPoints * $score['score'] + 50, 100) : 0;
            $coinReward = $score ? intdiv($maxCoins * $score['score'] + 50, 100) : 0;
            $now = now();
            if ($pointReward || $coinReward) {
                $policies->seedForFamily($familyId);
                $grant = $rewards->grant(new RewardIntent(
                    familyId: $familyId, childId: (int) $locked->child_id,
                    policyCode: 'english_beta.session', grantKey: 'english_beta:session:'.$id,
                    sourceModule: SourceModule::English, sourceType: 'english_beta_session', sourceId: $id,
                    occurredAt: CarbonImmutable::instance($now), xpAmount: 0,
                    coinAmount: $coinReward, pointAmount: $pointReward,
                ));
                $pointReward = (int) $grant->point_amount;
                $coinReward = (int) $grant->coin_amount;
            }
            DB::table('english_beta_sessions')->where('id', $id)->update([
                'ended_at' => $now, 'updated_at' => $now,
                'score' => $score['score'] ?? null,
                'score_status' => $score ? 'scored' : ($enabled && $hasChildTurn ? 'unavailable' : 'not_scored'),
                'score_feedback' => $score['feedback'] ?? null,
                'max_point_reward' => $maxPoints, 'max_coin_reward' => $maxCoins,
                'point_reward' => $pointReward, 'coin_reward' => $coinReward,
            ]);
            return $this->finishResult(DB::table('english_beta_sessions')->where('id', $id)->first());
        });
        return response()->json(['data' => $result]);
    }

    private function finishResult(object $session): array
    {
        return ['finished' => true, 'score' => $session->score, 'score_status' => $session->score_status,
            'score_feedback' => $session->score_feedback, 'point_reward' => (int) $session->point_reward,
            'coin_reward' => (int) $session->coin_reward];
    }

    public function report(CurrentContext $context, Settings $settings, SceneMission $mission, WeeklyPlan $plan): JsonResponse
    {
        $familyId = $context->familyId();
        $timezone = $context->family()->timezone;
        $since = CarbonImmutable::now($timezone)->startOfDay()->subDays(6)->utc();
        $recent = DB::table('english_beta_sessions')->where('family_id', $familyId)->where('started_at', '>=', $since)->get();
        $weekly = $recent->groupBy(fn ($row) => CarbonImmutable::parse($row->started_at, 'UTC')->setTimezone($timezone)->toDateString());
        $sceneCounts = DB::table('english_beta_sessions')->where('family_id', $familyId)
            ->selectRaw('scene, COUNT(*) as sessions')->groupBy('scene')->get();
        $transferWords = DB::table('english_beta_word_evidence')->where('family_id', $familyId)
            ->selectRaw('child_id, word, COUNT(DISTINCT scene) as scene_count')
            ->groupBy('child_id', 'word')->havingRaw('COUNT(DISTINCT scene) >= 2')->get();
        $children = DB::table('child_profiles')->where('family_id', $familyId)->get(['id', 'nickname'])
            ->map(fn ($child) => [
                'id' => $child->id,
                'nickname' => $child->nickname,
                'plan' => $plan->forChild($child->id, $timezone, (int) $settings->get('english_beta.days_per_week', $familyId), (int) $settings->get('english_beta.minutes_per_session', $familyId), $mission),
            ]);
        return response()->json(['data' => [
            'settings' => $this->options($settings, $familyId),
            'children' => $children,
            'sessions' => DB::table('english_beta_sessions')->where('family_id', $familyId)->orderByDesc('id')->limit(30)->get(),
            'vocabulary' => DB::table('english_beta_vocabulary')->where('family_id', $familyId)->get(),
            'tasks' => DB::table('english_beta_tasks')->join('english_beta_sessions', 'english_beta_sessions.id', '=', 'english_beta_tasks.session_id')->where('english_beta_sessions.family_id', $familyId)->select('english_beta_tasks.*', 'english_beta_sessions.child_id')->limit(100)->get(),
            'scene_summary' => $sceneCounts,
            'transfer_words' => $transferWords,
            'last_7_days' => $weekly->map(fn ($rows, $day) => [
                'date' => $day,
                'sessions' => $rows->count(),
                'completed' => $rows->whereNotNull('ended_at')->count(),
                'minutes' => (int) round($rows->sum(fn ($row) => min(
                    CarbonImmutable::parse($row->started_at)->diffInMinutes(CarbonImmutable::parse($row->ended_at ?? now()), true),
                    (int) $settings->get('english_beta.minutes_per_session', $familyId),
                ))),
            ])->values(),
        ]]);
    }

    public function settings(Request $request, CurrentContext $context, Settings $settings): JsonResponse
    {
        $data = $request->validate([
            'minutes_per_session' => ['required', 'integer', 'between:5,60'],
            'days_per_week' => ['required', 'integer', 'between:1,7'],
            'provider' => ['required', Rule::in(['qwen', 'doubao', 'mock'])],
            'chat_model' => ['sometimes', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'allow_ai' => ['required', 'boolean'],
            'enable_scoring' => ['sometimes', 'boolean'],
            'max_point_reward' => ['sometimes', 'integer', 'between:0,100'],
            'max_coin_reward' => ['sometimes', 'integer', 'between:0,100'],
            'token_mode' => ['sometimes', Rule::in(['frugal', 'full'])],
            'allow_voice_input' => ['sometimes', 'boolean'],
            'speech_rate_percent' => ['sometimes', 'integer', 'between:70,110'],
            'voice_provider' => ['sometimes', Rule::in(['qwen', 'doubao', 'mock'])],
            'asr_model' => ['sometimes', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);
        foreach ($data as $key => $value) {
            $settings->set('english_beta.'.$key, $value, $context->familyId(), $context->user()->id);
        }
        return response()->json(['data' => $this->options($settings, $context->familyId())]);
    }
}
