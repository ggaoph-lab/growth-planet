<?php

namespace App\Modules\Poetry\Services;

use App\Modules\Poetry\Models\PoetryPoem;
use Illuminate\Support\Facades\File;

class PoetryCatalog
{
    /** @var list<array<string, mixed>>|null */
    private static ?array $grade1Rows = null;

    public function __construct(private readonly ShiQuanClient $shiQuan) {}

    /** Ensure the grade-1 builtin set exists; return them. */
    public function ensureBuiltinGrade1(): void
    {
        $rows = $this->grade1SourceRows();
        if ($rows === []) {
            return;
        }
        $expected = 0;
        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['key'] ?? '') !== '') {
                $expected++;
            }
        }
        // One COUNT beats N firstOrCreate round-trips on every hub/detail open.
        if ($expected > 0) {
            $have = PoetryPoem::query()
                ->where('source', 'builtin')
                ->where('grade_tag', 'grade1')
                ->count();
            if ($have >= $expected) {
                return;
            }
        }
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                continue;
            }
            PoetryPoem::query()->firstOrCreate(
                ['source' => 'builtin', 'external_id' => $key],
                [
                    'title' => (string) ($row['title'] ?? ''),
                    'author' => $row['author'] ?? null,
                    'dynasty' => $row['dynasty'] ?? null,
                    'content' => (string) ($row['content'] ?? ''),
                    'grade_tag' => 'grade1',
                ],
            );
        }
    }

    /**
     * @return list<PoetryPoem>
     */
    public function grade1Poems(): array
    {
        $this->ensureBuiltinGrade1();

        return PoetryPoem::query()
            ->where('grade_tag', 'grade1')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Search local catalog first, then 诗泉; upsert remote hits into poetry_poems.
     *
     * @return list<PoetryPoem>
     */
    public function search(string $query, int $limit = 12): array
    {
        $this->ensureBuiltinGrade1();
        $query = trim($query);
        if ($query === '') {
            return array_slice($this->grade1Poems(), 0, $limit);
        }

        $local = PoetryPoem::query()
            ->where(function ($q) use ($query) {
                $q->where('title', 'like', '%'.$query.'%')
                    ->orWhere('author', 'like', '%'.$query.'%')
                    ->orWhere('content', 'like', '%'.$query.'%');
            })
            ->orderByRaw("CASE WHEN grade_tag = 'grade1' THEN 0 ELSE 1 END")
            ->limit($limit)
            ->get();

        $byKey = [];
        foreach ($local as $poem) {
            $byKey[$poem->source.':'.$poem->external_id] = $poem;
        }

        if (count($byKey) < $limit) {
            foreach ($this->shiQuan->search($query, $limit) as $hit) {
                $poem = PoetryPoem::query()->updateOrCreate(
                    ['source' => 'shiquan', 'external_id' => $hit['external_id']],
                    [
                        'title' => $hit['title'],
                        'author' => $hit['author'],
                        'dynasty' => $hit['dynasty'],
                        'content' => $hit['content'],
                        'grade_tag' => null,
                    ],
                );
                $byKey[$poem->source.':'.$poem->external_id] = $poem;
                if (count($byKey) >= $limit) {
                    break;
                }
            }
        }

        return array_values(array_slice($byKey, 0, $limit));
    }

    public function findOrFail(int $id): PoetryPoem
    {
        return PoetryPoem::query()->findOrFail($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function grade1SourceRows(): array
    {
        if (self::$grade1Rows !== null) {
            return self::$grade1Rows;
        }
        $path = base_path('app/Modules/Poetry/data/grade1-poems.json');
        if (! File::exists($path)) {
            return self::$grade1Rows = [];
        }
        $rows = json_decode(File::get($path), true);
        self::$grade1Rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];

        return self::$grade1Rows;
    }
}
