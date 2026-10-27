<?php

namespace App\Modules\Poetry\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QwenPoetryTts
{
    /** Shared polyphone cue for Qwen poetry TTS (do not speak pinyin aloud). */
    private const POLYPHONE_HINT = '多音字必须按词义选正确普通话读音，不要读错，也不要把拼音读出来。尤其注意：「还」在「还以为／还有／还是／还没」读 hái，在「归还／还家／往还／一去不复还」读 huán；「行」行走读 xíng、银行或量词一行读 háng；「长」长短读 cháng、成长读 zhǎng；「重」重复读 chóng、重要读 zhòng；「乐」音乐读 yuè、快乐读 lè；「数」数目读 shù、数一数读 shǔ；「着」轻声 zhe／着急 zháo 按词选。结合诗题、作者与全文语境判断。';

    /** @return array{binary:string,mime:string,provider:string,voice:string,model:string}|null */
    public function synthesize(string $text, string $key, string $workspaceId, string $region, string $voice, float $rate, string $style, string $clipKey = 'full', string $poemTitle = '', string $poemAuthor = '', string $poemBody = ''): ?array
    {
        if ($key === '' || ! in_array($region, ['cn-beijing', 'ap-southeast-1'], true)
            || ($workspaceId !== '' && ! preg_match('/^[a-zA-Z0-9_-]+$/', $workspaceId))) {
            return null;
        }

        $base = $workspaceId !== ''
            ? "https://{$workspaceId}.{$region}.maas.aliyuncs.com/api/v1"
            : ($region === 'cn-beijing' ? 'https://dashscope.aliyuncs.com/api/v1' : 'https://dashscope-intl.aliyuncs.com/api/v1');
        $url = $base.'/services/audio/tts/SpeechSynthesizer';
        $input = [
            'text' => $text,
            'voice' => $voice,
            'format' => 'mp3',
            'rate' => max(0.5, min(2.0, $rate)),
            'language_hints' => ['zh'],
        ];
        $tone = match ($style) {
            'gentle' => '语气温柔、亲切',
            'expressive' => '有适度感情起伏，但不要夸张',
            default => '语气自然、平稳',
        };
        $title = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $poemTitle) ?? ''), 0, 60);
        $author = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $poemAuthor) ?? ''), 0, 40);
        $body = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $poemBody) ?? ''), 0, 240);
        $context = $title !== '' ? "这句出自《{$title}》".($author !== '' ? "，作者{$author}" : '').'。' : '';
        if ($body !== '') {
            $context .= "全诗参考：{$body}。";
        }
        $poly = self::POLYPHONE_HINT;
        $input['instruction'] = match (true) {
            str_starts_with($clipKey, 'line-') => "{$context}用标准普通话为一年级孩子示范这一句古诗，{$tone}。{$poly}根据诗意决定轻重和停连，优先按词义分组，五言通常二三字、七言通常四三字；组间轻微换气，句末清楚收住并稍停。只朗读输入的诗句，不读背景提示，不逐字顿读、唱诵或添加文字。",
            $clipKey === 'full' => "用同一位朗读者以标准普通话为一年级孩子朗诵古诗，{$tone}。{$poly}根据诗名和诗句的意思决定轻重与停连；先自然读题目、朝代和作者，稍停后再进入诗文。诗句按词义自然分组，五言通常二三字、七言通常四三字；句中轻微换气，句末短停，每两句之后稍长停。保持稳定节奏，不逐字顿读、不拖长尾音、不唱诵，不添加文字或背景音乐。",
            $clipKey === 'author' => "{$context}用标准普通话为一年级孩子介绍这位诗人，{$tone}。{$poly}口语清楚、像讲小故事；按语义分句，标点处适当停顿。只朗读输入的作者简介，不读背景提示，不添加文字或背景音乐。",
            default => "用标准普通话为一年级孩子自然讲述诗词内容，{$tone}。{$poly}按语义分句，标点处适当停顿，清楚易懂；不要按古诗格律顿读，不添加文字或背景音乐。",
        };

        try {
            $response = Http::withToken($key)->timeout(60)->post($url, [
                'model' => 'qwen-audio-3.1-tts-flash',
                'input' => $input,
            ]);
            if (! $response->successful()) {
                Log::warning('poetry.qwen_tts.status', ['status' => $response->status()]);
                return null;
            }
            $audioUrl = $response->json('output.audio.url');
            if (! is_string($audioUrl) || ! $this->allowedAudioUrl($audioUrl, $region)) {
                Log::warning('poetry.qwen_tts.invalid_audio_url');
                return null;
            }
            $audio = Http::timeout(60)->get($audioUrl);
            if (! $audio->successful() || strlen($audio->body()) < 500 || strlen($audio->body()) > 20_000_000) {
                Log::warning('poetry.qwen_tts.download_failed', ['status' => $audio->status()]);
                return null;
            }
            return [
                'binary' => $audio->body(), 'mime' => 'audio/mpeg', 'provider' => 'qwen-poetry',
                'voice' => $voice, 'model' => 'qwen-audio-3.1-tts-flash',
            ];
        } catch (\Throwable $e) {
            Log::warning('poetry.qwen_tts.failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function allowedAudioUrl(string $url, string $region): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $suffix = $region === 'cn-beijing' ? '.oss-cn-beijing.aliyuncs.com' : '.oss-ap-southeast-1.aliyuncs.com';
        return in_array(($parts['scheme'] ?? ''), ['http', 'https'], true)
            && str_ends_with($host, $suffix) && ! isset($parts['user']) && ! isset($parts['pass']);
    }
}
