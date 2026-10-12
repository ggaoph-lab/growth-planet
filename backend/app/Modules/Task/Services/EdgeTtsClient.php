<?php

namespace App\Modules\Task\Services;

use Illuminate\Support\Facades\Log;

/**
 * Free Microsoft Edge online neural TTS (no API key).
 *
 * Protocol mirrors the open-source `rany2/edge-tts` client.
 */
class EdgeTtsClient
{
    private const TRUSTED_CLIENT_TOKEN = '6A5AA1D4EAFF4E9FB37E23D68491D6F4';

    private const CHROMIUM_FULL_VERSION = '143.0.3650.75';

    private const WSS_HOST = 'speech.platform.bing.com';

    private const WSS_PATH = '/consumer/speech/synthesize/readaloud/edge/v1';

    private const WIN_EPOCH = 11644473600;

    private static float $clockSkewSeconds = 0.0;

    /**
     * @return array{audio_base64: string, mime: string, provider: string}|null
     */
    public function synthesize(string $text, string $voice = 'zh-CN-XiaoxiaoNeural'): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        foreach ([1, 2] as $attempt) {
            try {
                $audio = $this->synthesizeOnce($text, $voice);
                if ($audio !== null && strlen($audio) >= 64) {
                    return [
                        'audio_base64' => base64_encode($audio),
                        'mime' => 'audio/mpeg',
                        'provider' => 'edge_tts',
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning($attempt === 1 ? 'tts.edge_failed' : 'tts.edge_retry_failed', [
                    'error' => $e->getMessage(),
                    'skew' => self::$clockSkewSeconds,
                ]);
            }
        }

        return null;
    }

    private function synthesizeOnce(string $text, string $voice): ?string
    {
        $connectionId = bin2hex(random_bytes(16));
        $secMsGec = $this->generateSecMsGec();
        $secMsGecVersion = '1-'.self::CHROMIUM_FULL_VERSION;
        $muid = strtoupper(bin2hex(random_bytes(16)));

        // Match edge-tts query encoding (do not over-encode Sec-MS-GEC-Version).
        $path = self::WSS_PATH
            .'?TrustedClientToken='.self::TRUSTED_CLIENT_TOKEN
            .'&ConnectionId='.$connectionId
            .'&Sec-MS-GEC='.$secMsGec
            .'&Sec-MS-GEC-Version='.$secMsGecVersion;
        $key = base64_encode(random_bytes(16));
        $ua = $this->userAgent();

        $fp = @stream_socket_client(
            'ssl://'.self::WSS_HOST.':443',
            $errno,
            $errstr,
            20,
            STREAM_CLIENT_CONNECT,
            stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'peer_name' => self::WSS_HOST,
                ],
            ])
        );

        if ($fp === false) {
            throw new \RuntimeException("edge_tts_connect: {$errstr} ({$errno})");
        }

        stream_set_timeout($fp, 30);

        $major = explode('.', self::CHROMIUM_FULL_VERSION)[0];
        $handshake = "GET {$path} HTTP/1.1\r\n"
            ."Host: ".self::WSS_HOST."\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\n"
            ."Sec-WebSocket-Version: 13\r\n"
            ."Sec-WebSocket-Protocol: synthesize\r\n"
            ."Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits\r\n"
            ."Origin: chrome-extension://jdiccldimpdaibmpdkjnbmckianbfold\r\n"
            ."User-Agent: {$ua}\r\n"
            ."Pragma: no-cache\r\n"
            ."Cache-Control: no-cache\r\n"
            ."Accept-Encoding: gzip, deflate, br\r\n"
            ."Accept-Language: zh-CN,zh;q=0.9,en;q=0.8\r\n"
            ."Sec-CH-UA: \"Microsoft Edge\";v=\"{$major}\", \"Chromium\";v=\"{$major}\", \"Not A(Brand\";v=\"24\"\r\n"
            ."Sec-CH-UA-Mobile: ?0\r\n"
            ."Sec-CH-UA-Platform: \"Windows\"\r\n"
            ."Sec-CH-UA-Full-Version: \"".self::CHROMIUM_FULL_VERSION."\"\r\n"
            ."Cookie: muid={$muid};\r\n"
            ."\r\n";

        fwrite($fp, $handshake);

        $responseHeaders = $this->readHttpHeaders($fp);
        if ($responseHeaders === null) {
            fclose($fp);
            throw new \RuntimeException('edge_tts_handshake_empty');
        }

        if (preg_match('/^Date:\s*(.+)$/mi', $responseHeaders, $m)) {
            $this->adjustClockSkew(trim($m[1]));
        }

        if (! preg_match('#^HTTP/1\.\d\s+101#i', $responseHeaders)) {
            fclose($fp);
            throw new \RuntimeException('edge_tts_handshake: '.preg_replace('/\s+/', ' ', mb_substr($responseHeaders, 0, 240)));
        }

        $ts = $this->dateToString();

        $configMsg = "X-Timestamp:{$ts}\r\n"
            ."Content-Type:application/json; charset=utf-8\r\n"
            ."Path:speech.config\r\n\r\n"
            .'{"context":{"synthesis":{"audio":{"metadataoptions":{'
            .'"sentenceBoundaryEnabled":"false","wordBoundaryEnabled":"false"'
            .'},"outputFormat":"audio-24khz-48kbitrate-mono-mp3"}}}}'."\r\n";

        $this->writeTextFrame($fp, $configMsg);

        $ssml = $this->buildSsml($text, $voice);
        // Trailing Z after timestamp is intentional (Edge service quirk; see edge-tts).
        $ssmlMsg = "X-RequestId:{$connectionId}\r\n"
            ."Content-Type:application/ssml+xml\r\n"
            ."X-Timestamp:{$ts}Z\r\n"
            ."Path:ssml\r\n\r\n"
            .$ssml;

        $this->writeTextFrame($fp, $ssmlMsg);

        $chunks = [];
        $deadline = microtime(true) + 30.0;

        while (microtime(true) < $deadline) {
            $frame = $this->readFrame($fp);
            if ($frame === null) {
                break;
            }

            [$opcode, $payload] = $frame;

            if ($opcode === 0x8) {
                break;
            }

            if ($opcode === 0x1) {
                if (str_contains($payload, 'Path:turn.end')) {
                    break;
                }
                continue;
            }

            if ($opcode === 0x2) {
                if (strlen($payload) < 2) {
                    continue;
                }
                $headerLength = unpack('n', substr($payload, 0, 2))[1];
                if ($headerLength > strlen($payload)) {
                    continue;
                }
                $headerBlob = substr($payload, 2, $headerLength);
                $audio = substr($payload, 2 + $headerLength);
                // Headers are followed by \r\n in the length accounting used by edge-tts:
                // get_headers_and_data uses header_length then +2 for CRLF.
                // Binary layout: [u16 header_len][headers][\r\n][audio]
                // Some clients include CRLF inside header_length; edge-tts does:
                //   parameters, data = get_headers_and_data(data, header_length)
                //   where data = received[2:], and data[header_length+2:] is audio
                // So audio starts at offset 2 + header_length + 2.
                if (strlen($payload) >= 2 + $headerLength + 2) {
                    $audio = substr($payload, 2 + $headerLength + 2);
                }

                $path = null;
                foreach (explode("\r\n", $headerBlob) as $line) {
                    if (str_starts_with($line, 'Path:')) {
                        $path = substr($line, 5);
                        break;
                    }
                }

                if ($path !== 'audio') {
                    continue;
                }

                if ($audio !== '') {
                    $chunks[] = $audio;
                }
            }
        }

        fclose($fp);

        if ($chunks === []) {
            return null;
        }

        return implode('', $chunks);
    }

    private function buildSsml(string $text, string $voice): string
    {
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $voiceEsc = htmlspecialchars($voice, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xml:lang="zh-CN">'
            ."<voice name=\"{$voiceEsc}\">"
            .'<prosody pitch="+0Hz" rate="-5%" volume="+0%">'
            .$escaped
            .'</prosody></voice></speak>';
    }

    private function generateSecMsGec(): string
    {
        $unix = (int) floor(microtime(true) + self::$clockSkewSeconds);
        $ticks = $unix + self::WIN_EPOCH;
        $rounded = $ticks - ($ticks % 300);
        $windowsTicks = $rounded * 10_000_000;
        $data = (string) $windowsTicks.self::TRUSTED_CLIENT_TOKEN;

        return strtoupper(hash('sha256', $data));
    }

    private function adjustClockSkew(string $rfcDate): void
    {
        $server = strtotime($rfcDate.' UTC');
        if ($server === false) {
            $server = strtotime($rfcDate);
        }
        if ($server === false) {
            return;
        }
        self::$clockSkewSeconds += ($server - (microtime(true) + self::$clockSkewSeconds));
    }

    private function dateToString(): string
    {
        return gmdate('D M d Y H:i:s').' GMT+0000 (Coordinated Universal Time)';
    }

    private function userAgent(): string
    {
        $major = explode('.', self::CHROMIUM_FULL_VERSION)[0];

        return "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/{$major}.0.0.0 Safari/537.36 Edg/{$major}.0.0.0";
    }

    private function readHttpHeaders($fp): ?string
    {
        $buf = '';
        while (! feof($fp)) {
            $line = fgets($fp, 4096);
            if ($line === false) {
                break;
            }
            $buf .= $line;
            if (str_ends_with($buf, "\r\n\r\n")) {
                return $buf;
            }
            if (strlen($buf) > 8192) {
                break;
            }
        }

        return $buf !== '' ? $buf : null;
    }

    private function writeTextFrame($fp, string $payload): void
    {
        $this->writeFrame($fp, 0x1, $payload);
    }

    private function writeFrame($fp, int $opcode, string $payload): void
    {
        $len = strlen($payload);
        $header = chr(0x80 | ($opcode & 0x0F));

        if ($len < 126) {
            $header .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $header .= chr(0x80 | 126).pack('n', $len);
        } else {
            // 64-bit length, big-endian
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

    private function readBytes($fp, int $n): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) {
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
