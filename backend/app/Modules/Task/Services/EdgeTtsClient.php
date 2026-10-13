<?php

namespace App\Modules\Task\Services;

use Illuminate\Support\Facades\Log;

/**
 * Free Microsoft Edge online neural TTS (no API key).
 *
 * Protocol mirrors the open-source `rany2/edge-tts` client. Synthesis moved to
 * api.msedgeservices.com; the old bing host now answers 403. The new host still
 * accepts the legacy Sec-MS-GEC token and version `1-143` — claiming version 2
 * without a v2 token is rejected.
 */
class EdgeTtsClient
{
    private const TRUSTED_CLIENT_TOKEN = '6A5AA1D4EAFF4E9FB37E23D68491D6F4';

    private const CHROMIUM_FULL_VERSION = '143.0.3650.75';

    /**
     * @var list<array{host: string, path: string, key_param: string}>
     */
    private const ENDPOINTS = [
        [
            'host' => 'api.msedgeservices.com',
            'path' => '/tts/cognitiveservices/websocket/v1',
            'key_param' => 'Ocp-Apim-Subscription-Key',
        ],
        [
            'host' => 'speech.platform.bing.com',
            'path' => '/consumer/speech/synthesize/readaloud/edge/v1',
            'key_param' => 'TrustedClientToken',
        ],
    ];

    private const WIN_EPOCH = 11644473600;

    private static float $clockSkewSeconds = 0.0;

    /**
     * @return array{audio_base64: string, mime: string, provider: string}|null
     */
    public function synthesize(string $text, string $voice = 'zh-CN-XiaoxiaoNeural'): ?array
    {
        $batch = $this->synthesizeMany([$text], $voice);

        return $batch[0] ?? null;
    }

    /**
     * Several lines on one WebSocket. The handshake is the slow part, so a
     * page of tasks should not open a new connection per sentence.
     *
     * @param  list<string>  $texts
     * @param  (callable(int, array{audio_base64: string, mime: string, provider: string}|null): void)|null  $onAudio
     * @return list<array{audio_base64: string, mime: string, provider: string}|null>
     */
    public function synthesizeMany(array $texts, string $voice = 'zh-CN-XiaoxiaoNeural', ?callable $onAudio = null): array
    {
        $texts = array_values(array_filter(array_map(static fn ($text) => trim((string) $text), $texts), static fn ($text) => $text !== ''));
        $results = array_fill(0, count($texts), null);
        if ($texts === []) {
            return [];
        }

        foreach (self::ENDPOINTS as $endpoint) {
            $pending = [];
            foreach ($results as $i => $done) {
                if ($done === null) {
                    $pending[] = $i;
                }
            }
            if ($pending === []) {
                break;
            }

            $tokenBefore = null;
            $connectFailed = false;
            foreach ([1, 2] as $attempt) {
                $tokenNow = $this->generateSecMsGec();
                if ($attempt === 2 && $tokenBefore === $tokenNow && ! $connectFailed) {
                    break;
                }
                $tokenBefore = $tokenNow;
                $connectFailed = false;

                try {
                    $this->synthesizeManyOnce(
                        $endpoint,
                        $voice,
                        $pending,
                        $texts,
                        $results,
                        $onAudio,
                    );
                    break;
                } catch (\Throwable $e) {
                    $connectFailed = str_contains($e->getMessage(), 'edge_tts_connect')
                        || str_contains($e->getMessage(), 'edge_tts_handshake');
                    Log::warning($attempt === 1 ? 'tts.edge_failed' : 'tts.edge_retry_failed', [
                        'host' => $endpoint['host'],
                        'error' => $e->getMessage(),
                        'skew' => self::$clockSkewSeconds,
                    ]);
                    if ($connectFailed && $attempt === 1) {
                        usleep(250_000);
                    }
                }
            }
        }

        return $results;
    }

    /**
     * @param  array{host: string, path: string, key_param: string}  $endpoint
     * @param  list<int>  $pending
     * @param  list<string>  $texts
     * @param  list<array{audio_base64: string, mime: string, provider: string}|null>  $results
     * @param  (callable(int, array{audio_base64: string, mime: string, provider: string}|null): void)|null  $onAudio
     */
    private function synthesizeManyOnce(array $endpoint, string $voice, array $pending, array $texts, array &$results, ?callable $onAudio): void
    {
        $connectionId = bin2hex(random_bytes(16));
        $secMsGec = $this->generateSecMsGec();
        $secMsGecVersion = '1-'.self::CHROMIUM_FULL_VERSION;
        $muid = strtoupper(bin2hex(random_bytes(16)));
        $host = $endpoint['host'];

        // Match edge-tts query encoding (do not over-encode Sec-MS-GEC-Version).
        $path = $endpoint['path']
            .'?'.$endpoint['key_param'].'='.self::TRUSTED_CLIENT_TOKEN
            .'&ConnectionId='.$connectionId
            .'&Sec-MS-GEC='.$secMsGec
            .'&Sec-MS-GEC-Version='.$secMsGecVersion;
        $key = base64_encode(random_bytes(16));
        $ua = $this->userAgent();

        $fp = $this->connect($host);

        stream_set_timeout($fp, 30);

        $major = explode('.', self::CHROMIUM_FULL_VERSION)[0];
        // No permessage-deflate: this client cannot inflate frames.
        // No "synthesize" subprotocol: the current Edge client does not send one.
        $handshake = "GET {$path} HTTP/1.1\r\n"
            ."Host: {$host}\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\n"
            ."Sec-WebSocket-Version: 13\r\n"
            ."Origin: chrome-extension://jdiccldimpdaibmpdkjnbmckianbfold\r\n"
            ."User-Agent: {$ua}\r\n"
            ."Pragma: no-cache\r\n"
            ."Cache-Control: no-cache\r\n"
            ."Accept-Encoding: gzip, deflate, br\r\n"
            ."Accept-Language: zh-CN,zh;q=0.9,en;q=0.8\r\n"
            ."Sec-CH-UA: \"Microsoft Edge\";v=\"{$major}\", \"Chromium\";v=\"{$major}\", \"Not A(Brand\";v=\"24\"\r\n"
            ."Sec-CH-UA-Mobile: ?0\r\n"
            ."Sec-CH-UA-Platform: \"Windows\"\r\n"
            .'Sec-CH-UA-Full-Version: "'.self::CHROMIUM_FULL_VERSION."\"\r\n"
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

        try {
            foreach ($pending as $index) {
                if (($results[$index] ?? null) !== null) {
                    continue;
                }
                $audio = $this->readTurn($fp, $this->ssmlMessage($texts[$index], $voice, bin2hex(random_bytes(16))));
                $packed = null;
                if ($audio !== null && strlen($audio) >= 64 && $this->looksLikeMp3($audio)) {
                    $packed = [
                        'audio_base64' => base64_encode($audio),
                        'mime' => 'audio/mpeg',
                        'provider' => 'edge_tts',
                    ];
                    $results[$index] = $packed;
                } else {
                    Log::warning('tts.edge_empty', [
                        'host' => $endpoint['host'],
                        'bytes' => is_string($audio) ? strlen($audio) : 0,
                        'head' => is_string($audio) ? bin2hex(substr((string) $audio, 0, 24)) : '',
                    ]);
                }
                if ($onAudio !== null) {
                    $onAudio($index, $packed);
                }
                if ($packed === null) {
                    break;
                }
            }
        } finally {
            fclose($fp);
        }
    }

    private function ssmlMessage(string $text, string $voice, string $connectionId): string
    {
        $ts = $this->dateToString();
        // Trailing Z after timestamp is intentional (Edge service quirk; see edge-tts).

        return "X-RequestId:{$connectionId}\r\n"
            ."Content-Type:application/ssml+xml\r\n"
            ."X-Timestamp:{$ts}Z\r\n"
            ."Path:ssml\r\n\r\n"
            .$this->buildSsml($text, $voice);
    }

    /**
     * @return string|null raw mp3 bytes
     */
    private function readTurn($fp, string $ssmlMsg): ?string
    {
        $this->writeTextFrame($fp, $ssmlMsg);

        $chunks = [];
        $deadline = microtime(true) + 12.0;

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
                if ($headerLength + 2 > strlen($payload)) {
                    continue;
                }
                // Same split as edge-tts get_headers_and_data(data, header_length):
                // headers occupy data[:header_length], audio starts at header_length + 2.
                $headerBlob = substr($payload, 0, $headerLength);
                $audio = substr($payload, $headerLength + 2);

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

        if ($chunks === []) {
            return null;
        }

        return implode('', $chunks);
    }

    private function buildSsml(string $text, string $voice): string
    {
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $voiceEsc = htmlspecialchars($voice, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $lang = 'zh-CN';
        if (preg_match('/^([a-z]{2}-[A-Z]{2})/', $voice, $match) === 1) {
            $lang = $match[1];
        }
        $rate = str_starts_with($lang, 'en') ? '-18%' : '-5%';

        return '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xml:lang="'.$lang.'">'
            ."<voice name=\"{$voiceEsc}\">"
            .'<prosody pitch="+0Hz" rate="'.$rate.'" volume="+0%">'
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

    /**
     * Docker's resolver sometimes hands back an unreachable IPv6 address.
     * Pin the socket to IPv4 and keep the TLS name on the original host.
     *
     * @return resource
     */
    private function connect(string $host)
    {
        $ips = $this->resolveIpv4($host);
        if ($ips === []) {
            throw new \RuntimeException("edge_tts_connect: php_network_getaddresses: getaddrinfo for {$host} failed");
        }

        $context = stream_context_create([
            'socket' => [
                'bindto' => '0.0.0.0:0',
            ],
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
        foreach ($ips as $ip) {
            $fp = @stream_socket_client(
                'ssl://'.$ip.':443',
                $errno,
                $errstr,
                8,
                STREAM_CLIENT_CONNECT,
                $context
            );
            if ($fp !== false) {
                return $fp;
            }
        }

        throw new \RuntimeException("edge_tts_connect: {$errstr} ({$errno})");
    }

    /**
     * @return list<string>
     */
    private function resolveIpv4(string $host): array
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

        return array_values(array_unique($ips));
    }

    private function looksLikeMp3(string $body): bool
    {
        if (strlen($body) < 4) {
            return false;
        }

        return str_starts_with($body, 'ID3') || (ord($body[0]) === 0xFF && (ord($body[1]) & 0xE0) === 0xE0);
    }
}
