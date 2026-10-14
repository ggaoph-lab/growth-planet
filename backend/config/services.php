<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'tmdb' => [
        'api_key' => env('TMDB_API_KEY'),
    ],

    'homework_llm' => [
        // auto = Gemini when configured, else Qwen. The other engine is used only on connection timeout.
        'provider' => env('HOMEWORK_LLM_PROVIDER', 'auto'),
    ],

    // English Lab kid paths (plan / coach tips / probes). Keep short so a slow engine cannot stall the UI.
    'english_lab' => [
        'timeout' => (int) env('ENGLISH_LAB_TIMEOUT', 12),
        // Re-attempt the same engine after a local fallback this many minutes (or sooner if model/provider changes).
        'retry_after_minutes' => (int) env('ENGLISH_LAB_RETRY_AFTER_MINUTES', 10),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 30),
    ],

    'qwen' => [
        'api_key' => env('DASHSCOPE_API_KEY'),
        'base_url' => env('DASHSCOPE_BASE_URL', 'https://dashscope.aliyuncs.com/compatible-mode/v1'),
        'model' => env('QWEN_MODEL', 'qwen-turbo'),
        'timeout' => (int) env('QWEN_TIMEOUT', 30),
        // Natural kid read-aloud. Same DashScope key as homework LLM.
        'tts_base_url' => env('DASHSCOPE_TTS_BASE_URL', 'https://dashscope.aliyuncs.com/api/v1'),
        'tts_model' => env('QWEN_TTS_MODEL', 'qwen3-tts-flash'),
        'tts_voice' => env('QWEN_TTS_VOICE', 'Cherry'),
        'tts_cosy_model' => env('QWEN_TTS_COSY_MODEL', 'cosyvoice-v2'),
        'tts_cosy_voice' => env('QWEN_TTS_COSY_VOICE', 'longxiaochun_v2'),
        'tts_timeout' => (int) env('QWEN_TTS_TIMEOUT', 25),
    ],

    // Kid read-aloud: Edge neural first, then Qwen/CosyVoice, then public fallbacks.
    'tts' => [
        'edge_voice' => env('TTS_EDGE_VOICE', 'zh-CN-XiaoxiaoNeural'),
        'edge_voice_en' => env('TTS_EDGE_VOICE_EN', 'en-US-JennyNeural'),
    ],

];
