<?php

namespace App\Modules\Task\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * DashScope Qwen-TTS Realtime (WebSocket). Models with a -realtime suffix reject HTTP.
 */
class QwenRealtimeTtsClient
{
    /** Leftover bytes after HTTP upgrade (often contains the first WS frame). */
    private string $readBuf = '';

    /**
     * @return array{audio_base64: string, mime: string, provider: string}|null
     */
    public function synthesize(string $text, string $apiKey, string $model, string $voice, string $lang = 'en-US'): ?array
    {
        $text = trim($text);
        if ($text === '' || $apiKey === '') {
            return null;
        }

        $path = '/api-ws/v1/realtime?model='.rawurlencode($model);
        $hosts = array_values(array_unique([
            'dashscope.aliyuncs.com',
            'dashscope-intl.aliyuncs.com',
        ]));

        foreach ($hosts as $host) {
            try {
                $result = $this->synthesizeOnHost($host, $path, $text, $apiKey, $voice, $lang);
                if ($result !== null) {
                    return $result;
                }
            } catch (\Throwable $e) {
                Log::warning('tts.qwen_realtime_failed', [
                    'host' => $host,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * @return array{audio_base64: string, mime: string, provider: string}|null
     */
    private function synthesizeOnHost(
        string $host,
        string $path,
        string $text,
        string $apiKey,
        string $voice,
        string $lang,
    ): ?array {
        $this->readBuf = '';
        $fp = $this->connect($host);
        stream_set_timeout($fp, 20);

        try {
            $this->handshake($fp, $host, $path, $apiKey);

            $deadline = microtime(true) + 18;
            $this->awaitEvent($fp, 'session.created', $deadline);

            $this->sendJson($fp, [
                'type' => 'session.update',
                'session' => [
                    'voice' => $voice,
                    'mode' => 'commit',
                    'language_type' => str_starts_with(strtolower($lang), 'en') ? 'English' : 'Chinese',
                    'response_format' => 'mp3',
                    'sample_rate' => 24000,
                ],
            ]);
            $this->awaitEvent($fp, 'session.updated', $deadline);

            $this->sendJson($fp, [
                'type' => 'input_text_buffer.append',
                'text' => $text,
            ]);
            $this->sendJson($fp, ['type' => 'input_text_buffer.commit']);

            $chunks = [];
            while (microtime(true) < $deadline) {
                $event = $this->readJsonEvent($fp, $deadline);
                if ($event === null) {
                    break;
                }
                $type = (string) ($event['type'] ?? '');
                if ($type === 'error') {
                    Log::warning('tts.qwen_realtime_error', [
                        'host' => $host,
                        'error' => $event['error'] ?? $event,
                    ]);

                    return null;
                }
                if ($type === 'response.audio.delta') {
                    $delta = (string) ($event['delta'] ?? '');
                    if ($delta !== '') {
                        $bin = base64_decode($delta, true);
                        if (is_string($bin) && $bin !== '') {
                            $chunks[] = $bin;
                        }
                    }
                }
                // Audio stream complete — enough to assemble mp3.
                if ($type === 'response.audio.done' || $type === 'response.done') {
                    if ($chunks !== []) {
                        break;
                    }
                }
                if ($type === 'session.finished') {
                    break;
                }
            }

            try {
                $this->sendJson($fp, ['type' => 'session.finish']);
            } catch (\Throwable) {
                // Connection may already be closing after audio.done.
            }

            if ($chunks === []) {
                return null;
            }

            $audio = implode('', $chunks);
            if (strlen($audio) < 64) {
                return null;
            }

            return [
                'audio_base64' => base64_encode($audio),
                'mime' => 'audio/mpeg',
                'provider' => 'qwen',
            ];
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }

    /**
     * @return resource
     */
    private function connect(string $host)
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = $record['ip'] ?? null;
                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ips[] = $ip;
                }
            }
        }
        if ($ips === []) {
            $ip = gethostbyname($host);
            if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ips[] = $ip;
            }
        }
        if ($ips === []) {
            throw new \RuntimeException('qwen_realtime_dns');
        }

        $context = stream_context_create([
            'socket' => ['bindto' => '0.0.0.0:0'],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
                'SNI_server_name' => $host,
            ],
        ]);

        $errno = 0;
        $errstr = 'connect failed';
        foreach (array_values(array_unique($ips)) as $ip) {
            $fp = @stream_socket_client('ssl://'.$ip.':443', $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $context);
            if ($fp !== false) {
                return $fp;
            }
        }

        throw new \RuntimeException("qwen_realtime_connect: {$errstr}");
    }

    /** @param  resource  $fp */
    private function handshake($fp, string $host, string $path, string $apiKey): void
    {
        $key = base64_encode(random_bytes(16));
        $req = "GET {$path} HTTP/1.1\r\n"
            ."Host: {$host}\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\n"
            ."Sec-WebSocket-Version: 13\r\n"
            .'Authorization: Bearer '.$apiKey."\r\n"
            ."User-Agent: GrowthPlanet/1.0\r\n"
            ."\r\n";
        fwrite($fp, $req);

        $headers = '';
        while (! str_contains($headers, "\r\n\r\n")) {
            $chunk = fread($fp, 1024);
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('qwen_realtime_handshake_empty');
            }
            $headers .= $chunk;
            if (strlen($headers) > 8192) {
                throw new \RuntimeException('qwen_realtime_handshake_too_large');
            }
        }
        $split = strpos($headers, "\r\n\r\n");
        $head = substr($headers, 0, $split);
        $this->readBuf = substr($headers, $split + 4);
        if (! preg_match('#^HTTP/1\.\d\s+101#i', $head)) {
            throw new \RuntimeException('qwen_realtime_handshake: '.preg_replace('/\s+/', ' ', mb_substr($head, 0, 240)));
        }
    }

    /** @param  resource  $fp */
    private function sendJson($fp, array $payload): void
    {
        if (! isset($payload['event_id'])) {
            $payload['event_id'] = 'event_'.Str::uuid()->toString();
        }
        $this->writeFrame($fp, 0x1, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    /**
     * @param  resource  $fp
     * @return array<string, mixed>
     */
    private function awaitEvent($fp, string $expectedType, float $deadline): array
    {
        while (microtime(true) < $deadline) {
            $event = $this->readJsonEvent($fp, $deadline);
            if ($event === null) {
                break;
            }
            $type = (string) ($event['type'] ?? '');
            if ($type === 'error') {
                throw new \RuntimeException('qwen_realtime_error: '.json_encode($event['error'] ?? $event));
            }
            if ($type === $expectedType) {
                return $event;
            }
        }

        throw new \RuntimeException("qwen_realtime_timeout_waiting_{$expectedType}");
    }

    /**
     * @param  resource  $fp
     * @return array<string, mixed>|null
     */
    private function readJsonEvent($fp, float $deadline): ?array
    {
        while (microtime(true) < $deadline) {
            $frame = $this->readFrame($fp);
            if ($frame === null) {
                return null;
            }
            [$opcode, $payload] = $frame;
            if ($opcode === 0x8) {
                return null;
            }
            if ($opcode !== 0x1) {
                continue;
            }
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /** @param  resource  $fp */
    private function writeFrame($fp, int $opcode, string $payload): void
    {
        $len = strlen($payload);
        $header = chr(0x80 | ($opcode & 0x0F));
        if ($len < 126) {
            $header .= chr(0x80 | $len);
        } elseif ($len <= 0xFFFF) {
            $header .= chr(0x80 | 126).pack('n', $len);
        } else {
            $header .= chr(0x80 | 127).pack('J', $len);
        }
        $mask = random_bytes(4);
        $header .= $mask;
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }
        fwrite($fp, $header.$masked);
    }

    /**
     * @param  resource  $fp
     * @return array{0: int, 1: string}|null
     */
    private function readFrame($fp): ?array
    {
        $hdr = $this->readBytes($fp, 2);
        if ($hdr === null || strlen($hdr) < 2) {
            return null;
        }
        $b0 = ord($hdr[0]);
        $b1 = ord($hdr[1]);
        $opcode = $b0 & 0x0F;
        $masked = ($b1 & 0x80) !== 0;
        $len = $b1 & 0x7F;
        if ($len === 126) {
            $ext = $this->readBytes($fp, 2);
            if ($ext === null) {
                return null;
            }
            $len = unpack('n', $ext)[1];
        } elseif ($len === 127) {
            $ext = $this->readBytes($fp, 8);
            if ($ext === null) {
                return null;
            }
            $len = (int) unpack('J', $ext)[1];
            if ($len > 8_000_000) {
                return null;
            }
        }
        $mask = null;
        if ($masked) {
            $mask = $this->readBytes($fp, 4);
            if ($mask === null) {
                return null;
            }
        }
        $payload = $len > 0 ? $this->readBytes($fp, $len) : '';
        if ($payload === null) {
            return null;
        }
        if ($mask !== null) {
            $out = '';
            for ($i = 0; $i < strlen($payload); $i++) {
                $out .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $out;
        }
        if ($opcode === 0x9) {
            $this->writeFrame($fp, 0xA, $payload);

            return $this->readFrame($fp);
        }

        return [$opcode, $payload];
    }

    /** @param  resource  $fp */
    private function readBytes($fp, int $n): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            if ($this->readBuf !== '') {
                $take = min($n - strlen($buf), strlen($this->readBuf));
                $buf .= substr($this->readBuf, 0, $take);
                $this->readBuf = substr($this->readBuf, $take);
                continue;
            }
            $chunk = fread($fp, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($fp);
                if (! empty($meta['timed_out']) || feof($fp)) {
                    return $buf !== '' ? $buf : null;
                }
                continue;
            }
            $buf .= $chunk;
        }

        return $buf;
    }
}
