<?php

namespace App\Modules\Task\Services;

use App\Modules\Task\Contracts\HomeworkLlmClient;

/**
 * Chooses Gemini / Qwen for homework parsing using family + env config.
 * The selected engine is used alone. The other configured engine runs only
 * after a connection timeout.
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
     * Resolved engine name: explicit request, otherwise the family default (`auto` stays `auto`).
     */
    public function chosenProvider(?string $preferred, ?int $familyId): string
    {
        return $this->resolveChoice($preferred, $this->aiConfig->resolve($familyId));
    }

    /**
     * Primary client only. Timeout fallback is {@see attemptsFor()}.
     *
     * @return list<array{client: HomeworkLlmClient, config: array{api_key: string, model: string}}>
     */
    public function clientsFor(?string $preferred = null, ?int $familyId = null): array
    {
        [$primary] = $this->attemptsFor($preferred, $familyId);

        return $primary === null ? [] : [$primary];
    }

    /**
     * @return array{
     *   0: array{client: HomeworkLlmClient, config: array{api_key: string, model: string}}|null,
     *   1: array{client: HomeworkLlmClient, config: array{api_key: string, model: string}}|null
     * }
     */
    public function attemptsFor(?string $preferred, ?int $familyId): array
    {
        $cfg = $this->aiConfig->resolve($familyId);
        $choice = $this->resolveChoice($preferred, $cfg);
        if ($choice === 'rules') {
            return [null, null];
        }

        $available = $this->availableClients($cfg);
        if ($choice === 'gemini' || $choice === 'qwen') {
            if (! isset($available[$choice])) {
                return [null, null];
            }
            $primaryName = $choice;
        } elseif (isset($available['gemini'])) {
            $primaryName = 'gemini';
        } elseif (isset($available['qwen'])) {
            $primaryName = 'qwen';
        } else {
            return [null, null];
        }

        $fallbackName = $primaryName === 'gemini' ? 'qwen' : 'gemini';

        return [$available[$primaryName], $available[$fallbackName] ?? null];
    }

    /**
     * An explicit gemini/qwen/rules request wins. `auto` or empty follows the family default.
     *
     * @param  array{provider: string}  $cfg
     */
    private function resolveChoice(?string $preferred, array $cfg): string
    {
        $requested = strtolower(trim((string) $preferred));
        if ($requested === '' || $requested === 'auto') {
            $requested = strtolower(trim($cfg['provider'] ?? ''));
        }
        if ($requested === '') {
            return 'auto';
        }

        return $requested;
    }

    /**
     * @param  array{gemini_configured: bool, gemini_api_key: string, gemini_model: string, qwen_configured: bool, qwen_api_key: string, qwen_model: string}  $cfg
     * @return array<string, array{client: HomeworkLlmClient, config: array{api_key: string, model: string}}>
     */
    private function availableClients(array $cfg): array
    {
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

        return $available;
    }
}
