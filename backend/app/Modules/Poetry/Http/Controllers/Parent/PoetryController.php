<?php

namespace App\Modules\Poetry\Http\Controllers\Parent;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Settings\Settings;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Poetry\Models\PoetryAssignment;
use App\Modules\Poetry\Models\PoetryRecitationRecord;
use App\Modules\Poetry\Models\PoetryReviewPlan;
use App\Modules\Poetry\Services\PoetryAudioService;
use App\Modules\Poetry\Services\PoetryCatalog;
use App\Modules\Poetry\Services\QwenPoetryTts;
use App\Modules\Task\Services\FamilyAiConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PoetryController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly Settings $settings,
        private readonly PoetryCatalog $catalog,
    ) {}

    public function index(): JsonResponse
    {
        $familyId = $this->context->familyId();
        $this->catalog->ensureBuiltinGrade1();

        $children = ChildProfile::query()
            ->where('family_id', $familyId)
            ->orderBy('id')
            ->get(['id', 'nickname']);

        $assignments = PoetryAssignment::query()
            ->with('poem')
            ->where('family_id', $familyId)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (PoetryAssignment $a) => [
                'id' => $a->id,
                'child_id' => $a->child_id,
                'due_date' => $a->due_date?->toDateString(),
                'poem' => [
                    'id' => $a->poem->id,
                    'title' => $a->poem->title,
                    'author' => $a->poem->author,
                ],
            ]);

        $recent = PoetryRecitationRecord::query()
            ->with('poem')
            ->where('family_id', $familyId)
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (PoetryRecitationRecord $r) => [
                'id' => $r->id,
                'child_id' => $r->child_id,
                'kind' => $r->kind,
                'passed' => $r->passed,
                'accuracy_percent' => $r->accuracy_percent,
                'feedback' => $r->feedback,
                'points_awarded' => $r->points_awarded,
                'created_at' => $r->created_at?->toIso8601String(),
                'poem' => [
                    'id' => $r->poem->id,
                    'title' => $r->poem->title,
                ],
            ]);

        $plans = PoetryReviewPlan::query()
            ->with('poem')
            ->where('family_id', $familyId)
            ->orderBy('next_review_date')
            ->limit(50)
            ->get()
            ->map(fn (PoetryReviewPlan $p) => [
                'id' => $p->id,
                'child_id' => $p->child_id,
                'status' => $p->status,
                'step_index' => $p->step_index,
                'next_review_date' => $p->next_review_date?->toDateString(),
                'poem' => [
                    'id' => $p->poem->id,
                    'title' => $p->poem->title,
                ],
            ]);

        return response()->json([
            'data' => [
                'settings' => $this->settingsPayload($familyId),
                'children' => $children,
                'assignments' => $assignments,
                'recent_records' => $recent,
                'review_plans' => $plans,
                'grade1' => array_map(fn ($p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'author' => $p->author,
                    'dynasty' => $p->dynasty,
                ], $this->catalog->grade1Poems()),
                'audio_cache' => app(PoetryAudioService::class)->familyCacheStats($familyId),
            ],
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        $familyId = $this->context->familyId();
        $data = $request->validate([
            'allow_ai' => ['sometimes', 'boolean'],
            'image_provider' => ['sometimes', 'string', 'in:mock,wanx'],
            'image_model' => ['sometimes', 'string', 'max:64'],
            'tts_provider' => ['sometimes', 'string', 'in:qwen,elevenlabs'],
            'tts_api_key' => ['sometimes', 'nullable', 'string', 'max:512'],
            'clear_tts_api_key' => ['sometimes', 'boolean'],
            'tts_workspace_id' => ['sometimes', 'nullable', 'string', 'regex:/^[a-zA-Z0-9_-]*$/', 'max:128'],
            'tts_region' => ['sometimes', 'string', 'in:cn-beijing,ap-southeast-1'],
            'tts_voice' => ['sometimes', 'string', 'in:wenhuaiqing_v3.1'],
            'eleven_voice' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9_-]*$/', 'max:64'],
            'tts_rate_percent' => ['sometimes', 'integer', 'between:50,200'],
            'tts_style' => ['sometimes', 'string', 'in:natural,gentle,expressive'],
            'follow_pause_ms' => ['sometimes', 'integer', 'between:0,5000'],
        ]);

        if (array_key_exists('allow_ai', $data)) {
            $this->settings->set('poetry.allow_ai', (bool) $data['allow_ai'], $familyId, $this->context->user()->id);
        }
        if (array_key_exists('image_provider', $data)) {
            $this->settings->set('poetry.image_provider', (string) $data['image_provider'], $familyId, $this->context->user()->id);
        }
        if (array_key_exists('image_model', $data)) {
            $this->settings->set('poetry.image_model', (string) $data['image_model'], $familyId, $this->context->user()->id);
        }
        foreach (['tts_provider', 'tts_workspace_id', 'tts_region', 'tts_voice', 'eleven_voice', 'tts_rate_percent', 'tts_style', 'follow_pause_ms'] as $field) {
            if (array_key_exists($field, $data)) {
                $this->settings->set('poetry.'.$field, $data[$field], $familyId, $this->context->user()->id);
            }
        }
        if (! empty($data['clear_tts_api_key'])) {
            $this->settings->set('poetry.tts_api_key', '', $familyId, $this->context->user()->id);
        } elseif (trim((string) ($data['tts_api_key'] ?? '')) !== '') {
            $this->settings->set('poetry.tts_api_key', trim($data['tts_api_key']), $familyId, $this->context->user()->id);
        }

        return response()->json(['data' => $this->settingsPayload($familyId)]);
    }

    public function previewAudio(Request $request, QwenPoetryTts $tts, FamilyAiConfig $ai, PoetryAudioService $poetryAudio): JsonResponse
    {
        $data = $request->validate(['text' => ['sometimes', 'string', 'max:120']]);
        $familyId = $this->context->familyId();
        if ($this->settings->get('poetry.tts_provider', $familyId) === 'elevenlabs') {
            $audio = $poetryAudio->previewElevenLabs($familyId);
            return response()->json(['data' => $audio === null
                ? ['available' => false, 'error' => 'ElevenLabs 试听失败，请检查家庭朗读设置中的 API Key 与音色 ID']
                : ['available' => true, 'audio_base64' => base64_encode($audio['binary']), 'mime' => $audio['mime']]]);
        }
        $key = trim((string) $this->settings->get('poetry.tts_api_key', $familyId))
            ?: (string) $ai->resolve($familyId)['tts_qwen_api_key'];
        $workspace = trim((string) $this->settings->get('poetry.tts_workspace_id', $familyId))
            ?: (string) config('poetry.audio.qwen_workspace_id', '');
        $audio = $tts->synthesize(
            (string) ($data['text'] ?? '床前明月光，疑是地上霜。'), $key, $workspace,
            (string) $this->settings->get('poetry.tts_region', $familyId),
            (string) $this->settings->get('poetry.tts_voice', $familyId),
            (int) $this->settings->get('poetry.tts_rate_percent', $familyId) / 100,
            (string) $this->settings->get('poetry.tts_style', $familyId),
        );
        return response()->json(['data' => $audio === null
            ? ['available' => false, 'error' => $key === '' ? '请先配置阿里云 API Key' : '阿里云朗读失败，请检查 API Key 所属区域、模型权限和余额']
            : ['available' => true, 'audio_base64' => base64_encode($audio['binary']), 'mime' => $audio['mime']]]);
    }

    public function clearAudioCache(PoetryAudioService $audio): JsonResponse
    {
        return response()->json(['data' => $audio->clearFamilyCache($this->context->familyId())]);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
        ]);
        $poems = $this->catalog->search((string) ($data['q'] ?? ''), 16);

        return response()->json([
            'data' => array_map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'author' => $p->author,
                'dynasty' => $p->dynasty,
                'preview' => $p->lines()[0] ?? '',
                'source' => $p->source,
            ], $poems),
        ]);
    }

    public function assign(Request $request): JsonResponse
    {
        $familyId = $this->context->familyId();
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'poem_id' => ['required', 'integer'],
            'due_date' => ['nullable', 'date'],
        ]);

        $child = ChildProfile::query()
            ->where('family_id', $familyId)
            ->where('id', (int) $data['child_id'])
            ->first();
        if ($child === null) {
            throw new ApiException('NOT_FOUND', BiMsg::of('找不到这个孩子', 'Child not found'), 404);
        }

        $poem = $this->catalog->findOrFail((int) $data['poem_id']);

        $assignment = PoetryAssignment::query()->updateOrCreate(
            [
                'child_id' => $child->id,
                'poem_id' => $poem->id,
            ],
            [
                'family_id' => $familyId,
                'assigned_by' => $this->context->user()->id,
                'due_date' => $data['due_date'] ?? null,
                'status' => 'active',
            ],
        );

        // Prefetch static practice pack (full + lines + meanings) onto the server.
        app(PoetryAudioService::class)->scheduleWarmLibrary((int) $poem->id, $familyId, 'assign');

        return response()->json([
            'data' => [
                'id' => $assignment->id,
                'child_id' => $assignment->child_id,
                'poem_id' => $poem->id,
                'due_date' => $assignment->due_date?->toDateString(),
                'status' => $assignment->status,
                'audio_warming' => true,
            ],
        ]);
    }

    public function cancelAssignment(int $id): JsonResponse
    {
        $assignment = PoetryAssignment::query()
            ->where('family_id', $this->context->familyId())
            ->where('id', $id)
            ->first();
        if ($assignment === null) {
            throw new ApiException('NOT_FOUND', BiMsg::of('找不到这个背诵任务', 'Assignment not found'), 404);
        }
        $assignment->status = 'cancelled';
        $assignment->save();

        return response()->json(['data' => ['id' => $assignment->id, 'status' => 'cancelled']]);
    }

    /** @return array<string, mixed> */
    private function settingsPayload(int $familyId): array
    {
        $workspace = trim((string) $this->settings->get('poetry.tts_workspace_id', $familyId))
            ?: trim((string) config('poetry.audio.qwen_workspace_id', ''));
        $key = trim((string) $this->settings->get('poetry.tts_api_key', $familyId))
            ?: trim((string) app(FamilyAiConfig::class)->resolve($familyId)['tts_qwen_api_key']);
        return [
            'allow_ai' => (bool) $this->settings->get('poetry.allow_ai', $familyId),
            'image_provider' => (string) $this->settings->get('poetry.image_provider', $familyId),
            'image_model' => (string) $this->settings->get('poetry.image_model', $familyId),
            'tts_provider' => (string) $this->settings->get('poetry.tts_provider', $familyId),
            'tts_workspace_id' => (string) $this->settings->get('poetry.tts_workspace_id', $familyId),
            'tts_region' => (string) $this->settings->get('poetry.tts_region', $familyId),
            'tts_voice' => (string) $this->settings->get('poetry.tts_voice', $familyId),
            'eleven_voice' => (string) $this->settings->get('poetry.eleven_voice', $familyId),
            'tts_rate_percent' => (int) $this->settings->get('poetry.tts_rate_percent', $familyId),
            'tts_style' => (string) $this->settings->get('poetry.tts_style', $familyId),
            'follow_pause_ms' => (int) $this->settings->get('poetry.follow_pause_ms', $familyId),
            'tts_key_configured' => $key !== '',
            'tts_workspace_configured' => $workspace !== '',
            'qwen_ready' => $key !== '',
            'review_intervals_days' => config('poetry.review_intervals_days', [1, 2, 4, 7, 15]),
        ];
    }
}
