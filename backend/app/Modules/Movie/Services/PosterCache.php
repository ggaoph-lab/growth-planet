<?php

namespace App\Modules\Movie\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads a suggestion poster once, re-encodes it, and serves the local file.
 * The browser never loads the external image host.
 */
class PosterCache
{
    public function ensure(string $key): ?string
    {
        $card = BuiltinMovieSuggestions::find($key);
        if ($card === null) {
            return null;
        }

        $relative = 'posters/'.$key.'.jpg';
        if (Storage::disk('local')->exists($relative)) {
            return Storage::disk('local')->path($relative);
        }

        $bytes = $this->download($card['wiki']);
        if ($bytes === null) {
            return null;
        }

        $jpeg = $this->reencode($bytes);
        if ($jpeg === null) {
            return null;
        }

        Storage::disk('local')->put($relative, $jpeg);

        return Storage::disk('local')->path($relative);
    }

    private function download(string $wikiTitle): ?string
    {
        $summary = Http::withHeaders($this->headers())
            ->timeout(8)
            ->get('https://en.wikipedia.org/api/rest_v1/page/summary/'.rawurlencode($wikiTitle));

        if (! $summary->successful()) {
            return null;
        }

        $source = $summary->json('thumbnail.source');
        if (! is_string($source) || ! $this->allowedImageUrl($source)) {
            return null;
        }

        $image = Http::withHeaders($this->headers())
            ->timeout(8)
            ->withOptions(['allow_redirects' => false])
            ->get($source);

        if (! $image->successful()) {
            return null;
        }

        $body = $image->body();

        return $body !== '' ? $body : null;
    }

    private function allowedImageUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        return ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === 'upload.wikimedia.org';
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['User-Agent' => 'GrowthPlanet/1.0 (local family app)'];
    }

    private function reencode(string $bytes): ?string
    {
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }

        $width = imagesx($src);
        $height = imagesy($src);
        $max = 400;
        $scale = $width > $max ? $max / $width : 1;
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $dst = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($dst === false) {
            imagedestroy($src);

            return null;
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        ob_start();
        imagejpeg($dst, null, 85);
        $jpeg = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        return is_string($jpeg) && $jpeg !== '' ? $jpeg : null;
    }
}
