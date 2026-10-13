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
        private readonly OpenAiClient $openai,
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
        if ($cfg['openai_configured']) {
            $names[] = 'openai';
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
        $order = ['gemini', 'qwen', 'openai'];
        if (in_array($choice, $order, true)) {
            if (! isset($available[$choice])) {
                return [null, null];
            }
            $primaryName = $choice;
        } else {
            $primaryName = null;
            foreach ($order as $name) {
                if (isset($available[$name])) {
                    $primaryName = $name;
                    break;
                }
            }
            if ($primaryName === null) {
                return [null, null];
            }
        }

        $fallbackName = null;
        $seenPrimary = false;
        foreach ($order as $name) {
            if ($name === $primaryName) {
                $seenPrimary = true;

                continue;
            }
            if ($seenPrimary && isset($available[$name])) {
                $fallbackName = $name;
                break;
            }
        }
        if ($fallbackName === null) {
            foreach ($order as $name) {
                if ($name !== $primaryName && isset($available[$name])) {
                    $fallbackName = $name;
                    break;
                }
            }
        }

        return [$available[$primaryName], $fallbackName === null ? null : $available[$fallbackName]];
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
        if ($cfg['openai_configured']) {
            $available['openai'] = [
                'client' => $this->openai,
                'config' => [
                    'api_key' => $cfg['openai_api_key'],
                    'model' => $cfg['openai_model'],
                    'base_url' => $cfg['openai_base_url'],
                ],
            ];
        }

        return $available;
    }
}
