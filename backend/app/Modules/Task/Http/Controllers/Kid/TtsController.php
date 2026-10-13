<?php

namespace App\Modules\Task\Http\Controllers\Kid;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Task\Services\QwenTtsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class TtsController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly QwenTtsService $tts,
    ) {}

    public function speak(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:500'],
            'lang' => ['sometimes', 'string', 'in:zh-CN,en-US'],
        ]);

        $child = $this->context->child();
        $throttleKey = 'kid-tts:'.$child->id;
        if (RateLimiter::tooManyAttempts($throttleKey, 40)) {
            throw ApiException::tooManyAttempts(RateLimiter::availableIn($throttleKey));
        }
        RateLimiter::hit($throttleKey, 60);

        $familyId = (int) $child->family_id;
        $result = $this->tts->synthesize((string) $data['text'], $familyId, (string) ($data['lang'] ?? 'zh-CN'));
        if ($result === null) {
            return response()->json([
                'data' => [
                    'available' => false,
                    'audio_base64' => null,
                    'mime' => null,
                    'provider' => null,
                    'qwen_configured' => $this->tts->qwenConfigured($familyId),
                    'hint' => $this->tts->qwenConfigured($familyId)
                        ? 'tts_upstream_failed'
                        : 'configure_dashscope_key',
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'available' => true,
                'audio_base64' => $result['audio_base64'],
                'mime' => $result['mime'],
                'provider' => $result['provider'],
                'qwen_configured' => $this->tts->qwenConfigured($familyId),
            ],
        ]);
    }

    public function warm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'texts' => ['required', 'array', 'max:8'],
            'texts.*' => ['required', 'string', 'max:500'],
            'lang' => ['sometimes', 'string', 'in:zh-CN,en-US'],
        ]);

        $child = $this->context->child();
        $throttleKey = 'kid-tts:'.$child->id;
        if (RateLimiter::tooManyAttempts($throttleKey, 40)) {
            throw ApiException::tooManyAttempts(RateLimiter::availableIn($throttleKey));
        }
        RateLimiter::hit($throttleKey, 60);

        $items = $this->tts->warm($data['texts'], (string) ($data['lang'] ?? 'zh-CN'));

        return response()->json([
            'data' => [
                'items' => $items,
            ],
        ]);
    }
}
