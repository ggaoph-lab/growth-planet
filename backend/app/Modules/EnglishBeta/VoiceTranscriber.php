<?php

namespace App\Modules\EnglishBeta;

use Illuminate\Support\Facades\Http;

class VoiceTranscriber
{
    public function transcribe(
        string $audio,
        string $mime,
        string $key,
        string $model = 'qwen3-asr-flash',
        string $language = 'en',
        int $maxChars = 240,
    ): ?string {
        $model = trim($model);
        if ($model === '' || ! preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $model)) {
            $model = 'qwen3-asr-flash';
        }
        $language = in_array($language, ['en', 'zh', 'yue', 'ja', 'ko', 'de', 'fr', 'ru'], true) ? $language : 'en';
        $maxChars = max(40, min(800, $maxChars));
        try {
            $response = Http::withToken($key)->timeout(20)->post('https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions', [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => [[
                    'type' => 'input_audio',
                    'input_audio' => ['data' => 'data:'.$mime.';base64,'.base64_encode($audio)],
                ]]]],
                'stream' => false,
                'asr_options' => ['language' => $language, 'enable_itn' => false],
            ]);
            if (! $response->successful()) {
                return null;
            }
            $text = trim((string) $response->json('choices.0.message.content'));

            return $text !== '' && mb_strlen($text) <= $maxChars ? $text : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
