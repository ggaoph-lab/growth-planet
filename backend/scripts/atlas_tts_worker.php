<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$tts = $app->make(App\Modules\Task\Services\QwenTtsService::class);

while (($line = fgets(STDIN)) !== false) {
    try {
        $job = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $text = trim((string) ($job['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > 400) {
            throw new RuntimeException('Invalid text length');
        }
        $result = $tts->preview((int) ($job['family_id'] ?? 1), 'Cherry', 'zh-CN', $text);
        if ($result === null || ($result['provider'] ?? '') !== 'qwen') {
            throw new RuntimeException('Mandarin synthesis unavailable');
        }
        echo json_encode(['audio_base64' => $result['audio_base64']], JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR)."\n";
    }
    flush();
}
