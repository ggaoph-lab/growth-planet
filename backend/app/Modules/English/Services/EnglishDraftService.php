<?php

namespace App\Modules\English\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnglishDraftService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly EnglishAiCoach $coach,
        private readonly EnglishVocabularyService $vocabulary,
        private readonly EnglishContentGate $gate,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(ChildProfile $child, ?string $status = null): array
    {
        $query = DB::table('english_content_drafts')
            ->where('child_id', $child->id)
            ->orderByDesc('id');
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->limit(50)->get()->map(fn ($row) => $this->present($row))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(ChildProfile $child, string $kind): array
    {
        $kind = $kind === 'story' ? 'story' : 'grammar';
        $profile = $this->vocabulary->profile($child);
        $snapshot = [
            'band' => $profile['band'],
            'estimated_size' => $profile['estimated_size'],
            'known_words' => array_slice($profile['known_words'], -20),
            'missed_words' => array_slice($profile['missed_words'], -10),
        ];
        $generated = $this->coach->generateChallengeDraft($child, $kind, $snapshot);
        $id = DB::table('english_content_drafts')->insertGetId([
            'child_id' => $child->id,
            'kind' => $kind,
            'payload' => json_encode($generated['payload'], JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
            'ai_record_id' => $generated['record']->id,
            'published_at' => null,
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);

        $row = DB::table('english_content_drafts')->where('id', $id)->first();
        if ($row === null) {
            throw new \RuntimeException('english_draft_missing');
        }

        return $this->present($row) + ['generation_status' => $generated['status']];
    }

    /**
     * @return array<string, mixed>
     */
    public function publish(ChildProfile $child, int $id): array
    {
        $row = $this->findOwned($child, $id);
        if ($row->status === 'published') {
            return $this->present($row);
        }
        if ($row->status === 'rejected') {
            throw ValidationException::withMessages(['draft' => '已驳回的内容不能发布。']);
        }

        $payload = json_decode((string) $row->payload, true);
        if (! is_array($payload) || $this->gate->challengeDraft((string) $row->kind, $payload, 5) === null) {
            throw ValidationException::withMessages(['draft' => '内容未通过安全检查，无法发布。']);
        }

        DB::table('english_content_drafts')->where('id', $row->id)->update([
            'status' => 'published',
            'published_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);

        return $this->present(DB::table('english_content_drafts')->where('id', $row->id)->first());
    }

    /**
     * @return array<string, mixed>
     */
    public function reject(ChildProfile $child, int $id): array
    {
        $row = $this->findOwned($child, $id);
        DB::table('english_content_drafts')->where('id', $row->id)->update([
            'status' => 'rejected',
            'updated_at' => $this->clock->now(),
        ]);

        return $this->present(DB::table('english_content_drafts')->where('id', $row->id)->first());
    }

    /**
     * Published challenges not yet answered, for the current session slots.
     *
     * @return list<array<string, mixed>>
     */
    public function publishedCards(ChildProfile $child, int $slots, string $date): array
    {
        if ($slots < 1) {
            return [];
        }

        $answered = DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where(function ($query) {
                $query->where('item_key', 'like', 'grammar:%')
                    ->orWhere('item_key', 'like', 'story:%');
            })
            ->pluck('item_key')
            ->all();

        $rows = DB::table('english_content_drafts')
            ->where('child_id', $child->id)
            ->where('status', 'published')
            ->orderBy('published_at')
            ->orderBy('id')
            ->get();

        $cards = [];
        foreach ($rows as $row) {
            $key = $row->kind.':'.$row->id;
            if (in_array($key, $answered, true)) {
                continue;
            }
            $payload = json_decode((string) $row->payload, true);
            if (! is_array($payload)) {
                continue;
            }
            $card = $this->gate->challengeDraft((string) $row->kind, $payload, 5);
            if ($card === null) {
                continue;
            }
            $cards[] = [
                ...$card,
                'key' => $key,
                'options' => $card['options'],
                'text' => (string) ($card['text'] ?? $card['prompt']),
                'meaning' => (string) ($card['question'] ?? $card['meaning'] ?? ''),
                'correct_answer' => (string) $card['answer'],
                'coach_tip' => (string) ($card['tip'] ?? ''),
            ];
            if (count($cards) >= $slots) {
                break;
            }
        }

        return $cards;
    }

    private function findOwned(ChildProfile $child, int $id): object
    {
        $row = DB::table('english_content_drafts')
            ->where('child_id', $child->id)
            ->where('id', $id)
            ->first();
        if ($row === null) {
            throw ValidationException::withMessages(['draft' => '找不到这份内容。']);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(?object $row): array
    {
        if ($row === null) {
            return [];
        }
        $payload = json_decode((string) $row->payload, true);

        return [
            'id' => (int) $row->id,
            'kind' => (string) $row->kind,
            'status' => (string) $row->status,
            'payload' => is_array($payload) ? $payload : [],
            'published_at' => $row->published_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
