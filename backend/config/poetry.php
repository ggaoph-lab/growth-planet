<?php

return [
    'shiquan' => [
        'base_url' => env('SHIQUAN_BASE_URL', 'https://poetry.palemoky.com'),
        'timeout' => (int) env('SHIQUAN_TIMEOUT', 8),
    ],

    /**
     * Image generation: switch provider with poetry.image_provider family setting
     * (or IMAGE_GEN_PROVIDER env default). Adapters live under Modules/Poetry/Image.
     */
    'image' => [
        'default_provider' => env('IMAGE_GEN_PROVIDER', 'mock'),
        'wanx' => [
            'api_key' => env('DASHSCOPE_API_KEY'),
            'base_url' => env('DASHSCOPE_IMAGE_BASE_URL', 'https://dashscope.aliyuncs.com/api/v1'),
            'model' => env('WANX_IMAGE_MODEL', 'wanx-v1'),
            'timeout' => (int) env('WANX_TIMEOUT', 60),
        ],
        'style_suffix' => '中国传统水墨画风格，儿童绘本风，色彩明亮，适合一年级小朋友，高画质，无文字无水印',
    ],

    /** Ebbinghaus-style review offsets in days after first successful recite. */
    'review_intervals_days' => [1, 2, 4, 7, 15],

    /**
     * Permanent expressive read-aloud library.
     * Qwen clips are family-scoped in the runtime library; legacy ElevenLabs seeds remain shared.
     * Assign / weekly recommend warm full+author+lines+meanings; kids stream via /audio/file.
     * Share across deploys: `php artisan poetry:audio-library export|import`.
     */
    'audio' => [
        'qwen_workspace_id' => env('POETRY_QWEN_WORKSPACE_ID', ''),
        'prefer_elevenlabs' => (bool) env('POETRY_TTS_PREFER_ELEVENLABS', true),
        'model' => env('POETRY_TTS_MODEL', 'eleven_multilingual_v2'),
        'voice_zh' => env('POETRY_TTS_VOICE_ZH', env('ELEVENLABS_VOICE_ZH', 'Xb7hH8MSUJpSbSDYk0k2')),
        'timeout' => (int) env('POETRY_TTS_TIMEOUT', 45),
        'stability' => (float) env('POETRY_TTS_STABILITY', 0.32),
        'similarity_boost' => (float) env('POETRY_TTS_SIMILARITY', 0.8),
        'style' => (float) env('POETRY_TTS_STYLE', 0.55),
        'script_version' => 'v4-poem-context',
    ],
];
