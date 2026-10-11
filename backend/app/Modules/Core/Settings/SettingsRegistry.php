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
     * @return array<string, array{type: string, default: mixed, min?: int, max?: int, label: string}>
     */
    public static function definitions(): array
    {
        return [
            'auth.sudo_ttl_minutes' => ['type' => 'int', 'default' => 10, 'min' => 1, 'max' => 60, 'label' => '敏感操作确认有效期（分钟）'],
            'auth.parent_session_idle_minutes' => ['type' => 'int', 'default' => 120, 'min' => 15, 'max' => 1440, 'label' => '家长会话空闲超时（分钟）'],
        ];
    }

    /**
     * @return array{type: string, default: mixed, min?: int, max?: int, label: string}
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
            default => $value,
        };
    }
}
