<?php

namespace App\Modules\Poetry\Services;

use App\Modules\Poetry\Models\PoetryPoem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Permanent poem read-aloud asset library (shared across families/deploys).
 *
 * Layout (keyed by stable poem external_id):
 *   Modules/Poetry/data/audio/{key}/{clip}.mp3   — shippable seeds (git / image)
 *   storage/app/poetry-library/audio/{key}/…     — durable runtime copy (volume)
 *
 * Kid playback streams via API; nginx SPA has no /storage mount.
 * `poetry:audio-library export` packs the runtime library for other deploys.
 */
class PoetryAudioLibrary
{
    public function libraryKey(PoetryPoem $poem): string
    {
        $external = trim((string) ($poem->external_id ?? ''));
        if ($external !== '' && preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/i', $external)) {
            return strtolower($external);
        }

        $fingerprint = strtolower(trim(($poem->title ?? '').'|'.($poem->author ?? '').'|'.($poem->dynasty ?? '')));

        return 'poem-'.substr(hash('sha256', $fingerprint !== '|' ? $fingerprint : 'id:'.$poem->id), 0, 16);
    }

    /** Shippable seeds baked into the PHP image / repo. */
    public function seedDirectory(): string
    {
        return dirname(__DIR__).'/data/audio';
    }

    /** Durable runtime library on the app-storage volume. */
    public function runtimeDirectory(): string
    {
        return storage_path('app/poetry-library/audio');
    }

    public function seedPath(string $key, string $clipKey): string
    {
        return $this->seedDirectory().'/'.$key.'/'.$clipKey.'.mp3';
    }

    public function runtimePath(string $key, string $clipKey): string
    {
        return $this->runtimeDirectory().'/'.$key.'/'.$clipKey.'.mp3';
    }

    public function metaPath(string $key, string $clipKey, string $root): string
    {
        return rtrim($root, '/').'/'.$key.'/'.$clipKey.'.json';
    }

    /**
     * Absolute MP3 path if the clip already exists in runtime or seed library.
     * Copies seed → runtime on first hit so the volume holds a complete set.
     */
    public function absoluteMp3(PoetryPoem $poem, string $clipKey): ?string
    {
        $key = $this->libraryKey($poem);
        $runtime = $this->runtimePath($key, $clipKey);
        if (is_file($runtime) && filesize($runtime) >= 500) {
            return $runtime;
        }

        $seed = $this->seedPath($key, $clipKey);
        if (is_file($seed) && filesize($seed) >= 500) {
            $this->copyIntoRuntime($seed, $runtime, $this->readMeta($this->metaPath($key, $clipKey, $this->seedDirectory())));

            return is_file($runtime) ? $runtime : $seed;
        }

        return null;
    }

    /**
     * @return array{provider:?string,voice:?string,model:?string,script_hash:?string,bytes:int}|null
     */
    public function metaFor(PoetryPoem $poem, string $clipKey): ?array
    {
        $key = $this->libraryKey($poem);
        foreach ([$this->runtimeDirectory(), $this->seedDirectory()] as $root) {
            $meta = $this->readMeta($this->metaPath($key, $clipKey, $root));
            if ($meta !== null) {
                return $meta;
            }
        }
        $path = $this->absoluteMp3($poem, $clipKey);
        if ($path === null) {
            return null;
        }

        return [
            'provider' => 'library',
            'voice' => null,
            'model' => null,
            'script_hash' => null,
            'bytes' => (int) filesize($path),
        ];
    }

    /**
     * Persist binary into the permanent library (runtime always; seed when writable).
     *
     * @param  array{provider:string,voice:string,model:string,script_hash:string}  $meta
     */
    public function put(PoetryPoem $poem, string $clipKey, string $binary, array $meta): string
    {
        $key = $this->libraryKey($poem);
        $runtime = $this->runtimePath($key, $clipKey);
        $this->writeMp3($runtime, $binary);
        $this->writeMeta($this->metaPath($key, $clipKey, $this->runtimeDirectory()), array_merge($meta, [
            'bytes' => strlen($binary),
            'clip' => $clipKey,
            'key' => $key,
        ]));

        // Also refresh shippable seeds outside unit tests (dev / warm --grade1).
        if (! app()->runningUnitTests() && ! str_contains($clipKey, '--family-')) {
            $seed = $this->seedPath($key, $clipKey);
            $seedDir = dirname($seed);
            if ($this->isWritableDir($seedDir) || $this->canCreate($seedDir)) {
                try {
                    $this->writeMp3($seed, $binary);
                    $this->writeMeta($this->metaPath($key, $clipKey, $this->seedDirectory()), array_merge($meta, [
                        'bytes' => strlen($binary),
                        'clip' => $clipKey,
                        'key' => $key,
                    ]));
                } catch (\Throwable $e) {
                    Log::info('poetry.audio.seed_write_skipped', ['error' => $e->getMessage()]);
                }
            }
        }

        return $runtime;
    }

    /** Copy every seed MP3 into the runtime volume (safe to re-run on deploy). */
    public function syncSeedsToRuntime(): array
    {
        $seedRoot = $this->seedDirectory();
        if (! is_dir($seedRoot)) {
            return ['copied' => 0, 'skipped' => 0];
        }

        $copied = 0;
        $skipped = 0;
        foreach (File::allFiles($seedRoot) as $file) {
            if (strtolower($file->getExtension()) !== 'mp3') {
                continue;
            }
            $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($seedRoot))), '/');
            $dest = $this->runtimeDirectory().'/'.$rel;
            if (is_file($dest) && filesize($dest) >= 500) {
                $skipped++;
                continue;
            }
            $metaRel = preg_replace('/\.mp3$/i', '.json', $rel) ?? ($rel.'.json');
            $this->copyIntoRuntime(
                $file->getPathname(),
                $dest,
                $this->readMeta($seedRoot.'/'.$metaRel),
            );
            $copied++;
        }

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /**
     * Pack runtime library into a zip for other deploys / git seed refresh.
     *
     * @return array{path:string,bytes:int,files:int}
     */
    public function exportArchive(string $destination = ''): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive extension is required to export the poetry audio library.');
        }

        $root = $this->runtimeDirectory();
        File::ensureDirectoryExists($root);

        $destination = $destination !== '' ? $destination : storage_path('app/poetry-audio-library.zip');
        if (! str_ends_with(strtolower($destination), '.zip')) {
            $destination .= '.zip';
        }
        File::ensureDirectoryExists(dirname($destination));
        if (is_file($destination)) {
            @unlink($destination);
        }

        $zip = new \ZipArchive;
        if ($zip->open($destination, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Unable to create archive: '.$destination);
        }

        $count = 0;
        foreach (File::allFiles($root) as $file) {
            $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            if ($rel === '') {
                continue;
            }
            $zip->addFile($file->getPathname(), $rel);
            $count++;
        }
        $zip->close();

        return [
            'path' => $destination,
            'bytes' => is_file($destination) ? (int) filesize($destination) : 0,
            'files' => $count,
        ];
    }

    /**
     * Extract a zip into the runtime library (and optionally refresh shippable seeds).
     *
     * @return array{imported:int}
     */
    public function importArchive(string $archivePath, bool $alsoSeeds = true): array
    {
        if (! is_file($archivePath)) {
            throw new \InvalidArgumentException('Archive not found: '.$archivePath);
        }
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive extension is required to import the poetry audio library.');
        }

        File::ensureDirectoryExists($this->runtimeDirectory());
        $tmp = storage_path('app/tmp-poetry-audio-import-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($tmp);

        try {
            $zip = new \ZipArchive;
            if ($zip->open($archivePath) !== true) {
                throw new \RuntimeException('Unable to open archive: '.$archivePath);
            }
            $zip->extractTo($tmp);
            $zip->close();

            $imported = 0;
            foreach (File::allFiles($tmp) as $file) {
                $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($tmp))), '/');
                if ($rel === '' || str_contains($rel, '..')) {
                    continue;
                }
                $dest = $this->runtimeDirectory().'/'.$rel;
                File::ensureDirectoryExists(dirname($dest));
                File::copy($file->getPathname(), $dest);
                if ($alsoSeeds && str_ends_with(strtolower($rel), '.mp3')) {
                    $seedDest = $this->seedDirectory().'/'.$rel;
                    if ($this->canCreate(dirname($seedDest))) {
                        File::ensureDirectoryExists(dirname($seedDest));
                        File::copy($file->getPathname(), $seedDest);
                    }
                }
                $imported++;
            }

            return ['imported' => $imported];
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    /**
     * @param  array{provider:?string,voice:?string,model:?string,script_hash:?string,bytes?:int}|null  $meta
     */
    private function copyIntoRuntime(string $source, string $dest, ?array $meta): void
    {
        File::ensureDirectoryExists(dirname($dest));
        if (! @copy($source, $dest)) {
            Log::warning('poetry.audio.runtime_copy_failed', ['from' => $source, 'to' => $dest]);

            return;
        }
        if ($meta !== null) {
            $keyClip = basename(dirname($dest)).'/'.basename($dest, '.mp3');
            [$key, $clip] = array_pad(explode('/', $keyClip, 2), 2, '');
            $this->writeMeta($this->metaPath($key, $clip, $this->runtimeDirectory()), array_merge($meta, [
                'bytes' => (int) filesize($dest),
                'key' => $key,
                'clip' => $clip,
            ]));
        }
    }

    private function writeMp3(string $path, string $binary): void
    {
        File::ensureDirectoryExists(dirname($path));
        if (file_put_contents($path, $binary) === false) {
            throw new \RuntimeException('Unable to write poetry audio: '.$path);
        }
    }

    /** @param  array<string, mixed>  $meta */
    private function writeMeta(string $path, array $meta): void
    {
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");
    }

    /**
     * @return array{provider:?string,voice:?string,model:?string,script_hash:?string,bytes:int}|null
     */
    private function readMeta(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json)) {
            return null;
        }

        return [
            'provider' => isset($json['provider']) ? (string) $json['provider'] : null,
            'voice' => isset($json['voice']) ? (string) $json['voice'] : null,
            'model' => isset($json['model']) ? (string) $json['model'] : null,
            'script_hash' => isset($json['script_hash']) ? (string) $json['script_hash'] : null,
            'bytes' => (int) ($json['bytes'] ?? 0),
        ];
    }

    private function isWritableDir(string $dir): bool
    {
        return is_dir($dir) && is_writable($dir);
    }

    private function canCreate(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }
        $parent = dirname($dir);
        while ($parent !== '' && $parent !== '/' && ! is_dir($parent)) {
            $parent = dirname($parent);
        }

        return is_dir($parent) && is_writable($parent);
    }
}
