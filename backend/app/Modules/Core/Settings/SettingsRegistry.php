<?php

namespace App\Modules\Core\Settings;

use InvalidArgumentException;

/**
 * Single source of truth for configurable settings: key, type, default and bounds.
 * Values are stored in system_settings (family override > system override > default here).
 */
class SettingsRegistry
{
    /**
     * @return array<string, array{type: string, default: mixed, min?: int, max?: int, label: string, enum?: list<string>}>
     */
    public static function definitions(): array
    {
        $planets = [];
        foreach (\App\Modules\Identity\Support\KidPlanets::all() as $planet) {
            $planets['planets.'.$planet['key']] = ['type' => 'bool', 'default' => true, 'label' => $planet['title']];
        }

        return $planets + [
            'auth.sudo_ttl_minutes' => ['type' => 'int', 'default' => 10, 'min' => 1, 'max' => 60, 'label' => '敏感操作确认有效期（分钟）'],
            'auth.parent_session_idle_minutes' => ['type' => 'int', 'default' => 120, 'min' => 15, 'max' => 1440, 'label' => '家长会话空闲超时（分钟）'],

            'ai.provider' => [
                'type' => 'string',
                'default' => 'auto',
                'enum' => ['auto', 'gemini', 'qwen', 'openai', 'rules'],
                'label' => '默认 AI 引擎',
            ],
            'ai.gemini_api_key' => ['type' => 'secret', 'default' => '', 'label' => 'Gemini API Key'],
            'ai.gemini_model' => ['type' => 'string', 'default' => 'gemini-3.1-flash-lite', 'label' => 'Gemini 模型'],
            'ai.qwen_api_key' => ['type' => 'secret', 'default' => '', 'label' => '通义千问 API Key'],
            'ai.qwen_model' => ['type' => 'string', 'default' => 'qwen-turbo', 'label' => '通义千问模型'],
            'ai.openai_api_key' => ['type' => 'secret', 'default' => '', 'label' => 'ChatGPT API Key'],
            'ai.openai_model' => ['type' => 'string', 'default' => 'gpt-4o-mini', 'label' => 'ChatGPT 模型'],
        ];
    }

    /**
     * @return array{type: string, default: mixed, min?: int, max?: int, label: string, enum?: list<string>}
     */
    public static function definition(string $key): array
    {
        return self::definitions()[$key] ?? throw new InvalidArgumentException("Unknown setting [{$key}]");
    }

    public static function cast(string $key, mixed $value): mixed
    {
        $def = self::definition($key);

        return match ($def['type']) {
            'int' => max($def['min'] ?? PHP_INT_MIN, min($def['max'] ?? PHP_INT_MAX, (int) $value)),
            'bool' => (bool) $value,
            'secret', 'string' => self::castString($def, $value),
            default => $value,
        };
    }

    /**
     * @param  array{type: string, default: mixed, enum?: list<string>}  $def
     */
    private static function castString(array $def, mixed $value): string
    {
        $string = is_string($value) ? trim($value) : (is_scalar($value) ? trim((string) $value) : '');
        if (isset($def['enum']) && $string !== '' && ! in_array($string, $def['enum'], true)) {
            return (string) $def['default'];
        }

        return mb_substr($string, 0, 512);
    }
}
