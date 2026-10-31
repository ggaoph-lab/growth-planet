<?php

namespace App\Modules\Task\Http\Controllers\Kid;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Task\Services\FamilyAiConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

class FamilyHubChatController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly FamilyAiConfig $config,
    ) {}

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:500'],
            'history' => ['sometimes', 'array', 'max:8'],
            'history.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'history.*.content' => ['required', 'string', 'max:1000'],
        ]);
        $child = $this->context->child();
        $throttleKey = 'family-hub-chat:'.$child->id;
        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            throw ApiException::tooManyAttempts(RateLimiter::availableIn($throttleKey));
        }
        RateLimiter::hit($throttleKey, 60);

        $config = $this->config->resolve($this->context->familyId());
        $preferred = $config['provider'];
        $providers = $preferred === 'auto' ? ['qwen', 'openai'] : [$preferred];
        $provider = null;
        foreach ($providers as $candidate) {
            if (in_array($candidate, ['qwen', 'openai'], true) && ! empty($config[$candidate.'_api_key'])) {
                $provider = $candidate;
                break;
            }
        }
        if ($provider === null) {
            return response()->json(['error' => ['code' => 'AI_NOT_CONFIGURED', 'message' => '请家长先配置千问或 OpenAI 模型。']], 503);
        }

        $messages = [[
            'role' => 'system',
            'content' => '你是成长星球的家庭学习助手。用户是儿童。用简短、温和、适龄的中文回答；鼓励独立思考，不索取个人信息，不要求孩子离开家长监督。涉及危险、医疗或财务问题时建议找家长。',
        ]];
        foreach ($data['history'] ?? [] as $item) {
            $messages[] = ['role' => $item['role'], 'content' => $item['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $data['message']];

        $baseUrl = $provider === 'qwen'
            ? (string) config('services.qwen.base_url')
            : (string) $config['openai_base_url'];
        try {
            $response = Http::timeout(25)
                ->withToken((string) $config[$provider.'_api_key'])
                ->acceptJson()
                ->post(rtrim($baseUrl, '/').'/chat/completions', [
                    'model' => (string) $config[$provider.'_model'],
                    'messages' => $messages,
                    'max_tokens' => 300,
                    'temperature' => 0.5,
                ]);
        } catch (ConnectionException) {
            return response()->json(['error' => ['code' => 'AI_UNAVAILABLE', 'message' => 'AI 连接超时，请稍后再试。']], 502);
        }
        if (! $response->successful()) {
            return response()->json(['error' => ['code' => 'AI_UNAVAILABLE', 'message' => 'AI 暂时不可用，请稍后再试。']], 502);
        }
        $reply = data_get($response->json(), 'choices.0.message.content');
        if (! is_string($reply) || trim($reply) === '') {
            return response()->json(['error' => ['code' => 'AI_EMPTY', 'message' => 'AI 没有返回内容，请重试。']], 502);
        }

        return response()->json(['data' => ['reply' => mb_substr(trim($reply), 0, 1500), 'provider' => $provider]]);
    }
}
