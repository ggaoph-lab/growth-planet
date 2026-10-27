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
            'english_beta.minutes_per_session' => ['type' => 'int', 'default' => 20, 'min' => 5, 'max' => 60, 'label' => 'Beta 每次分钟'],
            'english_beta.days_per_week' => ['type' => 'int', 'default' => 5, 'min' => 1, 'max' => 7, 'label' => 'Beta 每周天数'],
            'english_beta.provider' => ['type' => 'string', 'default' => 'qwen', 'enum' => ['qwen', 'doubao', 'mock'], 'label' => 'Beta 对话服务商'],
            'english_beta.chat_model' => ['type' => 'string', 'default' => 'qwen-flash', 'label' => 'Beta 对话模型'],
            'english_beta.allow_ai' => ['type' => 'bool', 'default' => false, 'label' => '允许 Beta AI 对话'],
            'english_beta.enable_scoring' => ['type' => 'bool', 'default' => false, 'label' => '启用 Beta AI 评分'],
            'english_beta.max_point_reward' => ['type' => 'int', 'default' => 0, 'min' => 0, 'max' => 100, 'label' => 'Beta 满分积分'],
            'english_beta.max_coin_reward' => ['type' => 'int', 'default' => 0, 'min' => 0, 'max' => 100, 'label' => 'Beta 满分金币'],
            'english_beta.token_mode' => ['type' => 'string', 'default' => 'frugal', 'enum' => ['frugal', 'full'], 'label' => 'Beta AI 用量模式'],
            'english_beta.allow_voice_input' => ['type' => 'bool', 'default' => false, 'label' => '允许 Beta 语音转写'],
            'english_beta.speech_rate_percent' => ['type' => 'int', 'default' => 85, 'min' => 70, 'max' => 110, 'label' => 'Beta 伙伴英文语速百分比'],
            'english_beta.voice_provider' => ['type' => 'string', 'default' => 'qwen', 'enum' => ['qwen', 'doubao', 'mock'], 'label' => 'Beta 语音转写服务商'],
            'english_beta.asr_model' => ['type' => 'string', 'default' => 'qwen3-asr-flash', 'label' => 'Beta 语音转写模型'],

            'poetry.allow_ai' => ['type' => 'bool', 'default' => false, 'label' => '诗词星球允许 AI（故事/纠错/生图提示）'],
            'poetry.image_provider' => ['type' => 'string', 'default' => 'mock', 'enum' => ['mock', 'wanx'], 'label' => '诗词星球生图服务商'],
            'poetry.image_model' => ['type' => 'string', 'default' => 'wanx-v1', 'label' => '诗词星球生图模型'],
            'poetry.tts_provider' => ['type' => 'string', 'default' => 'qwen', 'enum' => ['qwen', 'elevenlabs'], 'label' => '诗词朗读服务商'],
            'poetry.tts_api_key' => ['type' => 'secret', 'default' => '', 'label' => '诗词朗读阿里云 API Key'],
            'poetry.tts_workspace_id' => ['type' => 'string', 'default' => '', 'label' => '诗词朗读工作空间 ID'],
            'poetry.tts_region' => ['type' => 'string', 'default' => 'cn-beijing', 'enum' => ['cn-beijing', 'ap-southeast-1'], 'label' => '诗词朗读区域'],
            'poetry.tts_voice' => ['type' => 'string', 'default' => 'wenhuaiqing_v3.1', 'label' => '诗词朗读音色'],
            'poetry.eleven_voice' => ['type' => 'string', 'default' => '', 'label' => '诗词 ElevenLabs 音色 ID'],
            'poetry.tts_rate_percent' => ['type' => 'int', 'default' => 90, 'min' => 50, 'max' => 200, 'label' => '诗词朗读语速百分比'],
            'poetry.tts_style' => ['type' => 'string', 'default' => 'gentle', 'enum' => ['natural', 'gentle', 'expressive'], 'label' => '诗词朗读风格'],
            'poetry.follow_pause_ms' => ['type' => 'int', 'default' => 1200, 'min' => 0, 'max' => 5000, 'label' => '逐句跟读停顿毫秒'],

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
            'ai.openai_api_key' => ['type' => 'secret', 'default' => '', 'label' => 'ChatGPT / 中转站 API Key'],
            'ai.openai_model' => ['type' => 'string', 'default' => 'gpt-4o-mini', 'label' => 'ChatGPT / 中转站模型'],
            'ai.openai_base_url' => [
                'type' => 'string',
                'default' => '',
                'label' => 'OpenAI 兼容接口地址（中转站）',
            ],

            // Kid read-aloud TTS (separate from content AI / word picks).
            'tts.provider' => [
                'type' => 'string',
                'default' => 'auto',
                'enum' => ['auto', 'qwen', 'edge', 'elevenlabs'],
                'label' => '朗读语音引擎',
            ],
            'tts.qwen_api_key' => ['type' => 'secret', 'default' => '', 'label' => '朗读专用通义 API Key'],
            'tts.elevenlabs_api_key' => ['type' => 'secret', 'default' => '', 'label' => 'ElevenLabs API Key'],
            'tts.voice' => ['type' => 'string', 'default' => 'Cherry', 'label' => '中文朗读音色'],
            'tts.voice_en' => ['type' => 'string', 'default' => 'Jennifer', 'label' => '英语朗读音色（美音）'],

            'points.daily_allowance' => ['type' => 'int', 'default' => 10, 'min' => 0, 'max' => 100, 'label' => '每日积分津贴'],

            // 雅思星球 (Word Atlas) rewards: per-day caps and the "mastered" milestone; 0 disables that kind of reward.
            'ielts.rewards_enabled' => ['type' => 'bool', 'default' => true, 'label' => '雅思星球发放奖励'],
            'ielts.points_daily_cap' => ['type' => 'int', 'default' => 20, 'min' => 0, 'max' => 200, 'label' => '雅思星球每日积分上限'],
            'ielts.coins_daily_cap' => ['type' => 'int', 'default' => 10, 'min' => 0, 'max' => 200, 'label' => '雅思星球每日金币上限'],
            'ielts.mastered_points' => ['type' => 'int', 'default' => 2, 'min' => 0, 'max' => 20, 'label' => '雅思星球掌握一词积分'],
            'ielts.mastered_daily_cap' => ['type' => 'int', 'default' => 20, 'min' => 0, 'max' => 200, 'label' => '雅思星球每日掌握积分上限'],
            'xp.max_level' => ['type' => 'int', 'default' => 300, 'min' => 1, 'max' => 300, 'label' => '最高等级'],
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
