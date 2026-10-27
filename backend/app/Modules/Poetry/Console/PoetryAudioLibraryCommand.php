<?php

namespace App\Modules\Poetry\Console;

use App\Modules\Identity\Models\Family;
use App\Modules\Poetry\Models\PoetryPoem;
use App\Modules\Poetry\Services\PoetryAudioLibrary;
use App\Modules\Poetry\Services\PoetryAudioService;
use App\Modules\Poetry\Services\PoetryCatalog;
use Illuminate\Console\Command;
use Throwable;

/**
 * Manage the permanent poetry read-aloud asset library.
 *
 *   sync    — copy shippable seeds into the runtime volume
 *   warm    — synthesize missing clips (ElevenLabs/TTS) into the library
 *   export  — zip the runtime library for other deploys
 *   import  — unzip a pack into runtime (+ seeds when writable)
 */
class PoetryAudioLibraryCommand extends Command
{
    protected $signature = 'poetry:audio-library
        {action : sync|warm|export|import}
        {--poem= : Poem external_id or numeric id (warm)}
        {--grade1 : Warm every builtin grade-1 poem}
        {--family= : Family id used for TTS credentials (warm)}
        {--file= : Archive path (export/import)}
        {--seeds : Also refresh Modules/Poetry/data/audio on import}';

    protected $description = 'Sync, warm, export, or import the permanent poetry audio library';

    public function handle(
        PoetryAudioLibrary $library,
        PoetryAudioService $audio,
        PoetryCatalog $catalog,
    ): int {
        $action = strtolower((string) $this->argument('action'));

        return match ($action) {
            'sync' => $this->sync($library),
            'warm' => $this->warm($library, $audio, $catalog),
            'export' => $this->export($library),
            'import' => $this->import($library),
            default => $this->invalidAction($action),
        };
    }

    private function sync(PoetryAudioLibrary $library): int
    {
        $result = $library->syncSeedsToRuntime();
        $this->info("Synced seeds → runtime (copied={$result['copied']}, skipped={$result['skipped']}).");

        return self::SUCCESS;
    }

    private function warm(PoetryAudioLibrary $library, PoetryAudioService $audio, PoetryCatalog $catalog): int
    {
        $library->syncSeedsToRuntime();
        $catalog->ensureBuiltinGrade1();

        $familyId = (int) ($this->option('family') ?: 0);
        if ($familyId <= 0) {
            $familyId = (int) (Family::query()->orderBy('id')->value('id') ?: 0);
        }
        if ($familyId <= 0) {
            $this->error('No family found. Pass --family=ID so TTS credentials can be resolved.');

            return self::FAILURE;
        }

        $poems = [];
        if ($this->option('grade1')) {
            $poems = $catalog->grade1Poems();
        } elseif ($this->option('poem')) {
            $raw = (string) $this->option('poem');
            $poem = ctype_digit($raw)
                ? PoetryPoem::query()->find((int) $raw)
                : PoetryPoem::query()->where('external_id', $raw)->first();
            if ($poem === null) {
                $this->error('Poem not found: '.$raw);

                return self::FAILURE;
            }
            $poems = [$poem];
        } else {
            $this->error('Pass --grade1 or --poem=key');

            return self::FAILURE;
        }

        $failed = false;
        foreach ($poems as $poem) {
            try {
                $items = $audio->warmLibrary((int) $poem->id, $familyId);
                $ready = count(array_filter($items, fn ($i) => ($i['status'] ?? '') === 'ready'));
                $this->info(sprintf(
                    '%s (%s): %d/%d clips ready',
                    $poem->title,
                    $library->libraryKey($poem),
                    $ready,
                    count($items),
                ));
                if ($ready < count($items)) {
                    $failed = true;
                }
            } catch (Throwable $e) {
                $failed = true;
                $this->warn("Warm failed for poem {$poem->id}: ".$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function export(PoetryAudioLibrary $library): int
    {
        $file = (string) ($this->option('file') ?: storage_path('app/poetry-audio-library.zip'));
        $result = $library->exportArchive($file);
        $this->info("Exported {$result['files']} files ({$result['bytes']} bytes) → {$result['path']}");

        return self::SUCCESS;
    }

    private function import(PoetryAudioLibrary $library): int
    {
        $file = (string) $this->option('file');
        if ($file === '') {
            $this->error('Pass --file=/path/to/poetry-audio-library.zip');

            return self::FAILURE;
        }
        $result = $library->importArchive($file, (bool) $this->option('seeds'));
        $this->info("Imported {$result['imported']} files into the poetry audio library.");

        return self::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        $this->error("Unknown action [{$action}]. Use sync|warm|export|import.");

        return self::FAILURE;
    }
}
