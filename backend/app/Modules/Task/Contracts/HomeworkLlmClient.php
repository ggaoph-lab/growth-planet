<?php

namespace App\Modules\Task\Contracts;

interface HomeworkLlmClient
{
    /** Provider id used in API responses (`gemini`, `qwen`, …). */
    public function name(): string;

    /**
     * @param  array{api_key?: string, model?: string, base_url?: string, timeout?: int}|null  $config
     */
    public function isConfigured(?array $config = null): bool;

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array{api_key?: string, model?: string, base_url?: string, timeout?: int}|null  $config
     * @return array{content: string, model: string}|null
     */
    public function chatJson(array $messages, ?array $config = null): ?array;
}
