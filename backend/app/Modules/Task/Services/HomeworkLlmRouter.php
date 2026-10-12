<?php

namespace App\Modules\Task\Services;

use App\Modules\Task\Contracts\HomeworkLlmClient;

/**
 * Chooses Gemini / Qwen for homework parsing using family + env config.
 * Default `auto` prefers Gemini (good for Singapore hosts), then Qwen.
 */
class HomeworkLlmRouter
{
    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly QwenClient $qwen,
        private readonly FamilyAiConfig $aiConfig,
    ) {}

    /**
     * @return list<string>
     */
    public function configuredProviders(?int $familyId = null): array
    {
        $cfg = $this->aiConfig->resolve($familyId);
        $names = [];
        if ($cfg['gemini_configured']) {
            $names[] = 'gemini';
        }
        if ($cfg['qwen_configured']) {
            $names[] = 'qwen';
        }

        return $names;
    }

    public function isAiConfigured(?int $familyId = null): bool
    {
        return $this->configuredProviders($familyId) !== [];
    }

    /**
     * @return list<array{client: HomeworkLlmClient, config: array{api_key: string, model: string}}>
     */
    public function clientsFor(?string $preferred = null, ?int $familyId = null): array
    {
        $cfg = $this->aiConfig->resolve($familyId);
        $preferred = strtolower(trim($preferred ?: $cfg['provider'] ?: 'auto'));

        if ($preferred === 'rules') {
            return [];
        }

        $available = [];
        if ($cfg['gemini_configured']) {
            $available['gemini'] = [
                'client' => $this->gemini,
                'config' => [
                    'api_key' => $cfg['gemini_api_key'],
                    'model' => $cfg['gemini_model'],
                ],
            ];
        }
        if ($cfg['qwen_configured']) {
            $available['qwen'] = [
                'client' => $this->qwen,
                'config' => [
                    'api_key' => $cfg['qwen_api_key'],
                    'model' => $cfg['qwen_model'],
                ],
            ];
        }

        if ($available === []) {
            return [];
        }

        if (isset($available[$preferred])) {
            $ordered = [$available[$preferred]];
            foreach ($available as $name => $entry) {
                if ($name !== $preferred) {
                    $ordered[] = $entry;
                }
            }

            return $ordered;
        }

        $ordered = [];
        foreach (['gemini', 'qwen'] as $name) {
            if (isset($available[$name])) {
                $ordered[] = $available[$name];
            }
        }

        return $ordered;
    }
}
