<?php

namespace App\Modules\Encyclopedia\Http\Controllers\Kid;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Encyclopedia\Services\EncyclopediaCatalog;
use App\Modules\Encyclopedia\Services\EncyclopediaProgress;
use App\Modules\English\Services\EnglishVocabularyService;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EncyclopediaController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly EncyclopediaCatalog $catalog,
        private readonly EncyclopediaProgress $progress,
        private readonly EnglishVocabularyService $vocabulary,
        private readonly Clock $clock,
    ) {}

    public function index(): JsonResponse
    {
        $child = $this->context->child();

        return response()->json(['data' => $this->catalog->subjectSummary($child)]);
    }

    public function domain(string $domain): JsonResponse
    {
        $child = $this->context->child();
        $readIds = $this->progress->readTopicIds($child);
        $topics = $this->catalog->topicsForDomain($domain, $readIds, $child);
        if ($topics === []) {
            throw new ApiException(
                'NOT_FOUND',
                BiMsg::of('还没有这个百科分类', 'That encyclopedia section is not available yet'),
                404,
            );
        }

        $meta = null;
        foreach ($this->catalog->subjectSummary($child)['domains'] as $row) {
            if (($row['key'] ?? '') === ($topics[0]['domain_key'] ?? '')) {
                $meta = $row;
                break;
            }
        }

        return response()->json([
            'data' => [
                'key' => $topics[0]['domain_key'],
                'title' => $meta['title'] ?? $domain,
                'emoji' => $meta['emoji'] ?? ($topics[0]['emoji'] ?? '📘'),
                'topics' => $topics,
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $child = $this->context->child();
        $readIds = $this->progress->readTopicIds($child);
        $detail = $this->catalog->topicDetail($id, $readIds, $child);
        if ($detail === null) {
            throw new ApiException(
                'NOT_FOUND',
                BiMsg::of('这篇百科还没开放', 'That encyclopedia article is not available yet'),
                404,
            );
        }
        if (($detail['locked'] ?? false) === true) {
            $hint = $detail['unlock_hint']['name_zh'] ?? null;
            throw new ApiException(
                'TOPIC_LOCKED',
                BiMsg::of(
                    $hint !== null ? "先读完「{$hint}」再来看这篇吧" : '先读完前面的小百科，再来看这篇吧',
                    $hint !== null ? "Read «{$hint}» first" : 'Finish the earlier articles first',
                ),
                403,
                ['unlock_hint' => $detail['unlock_hint']],
            );
        }

        return response()->json(['data' => $detail]);
    }

    public function markRead(string $id): JsonResponse
    {
        $child = $this->context->child();
        if (! $this->catalog->isVisibleToChild($id, $child)) {
            throw new ApiException(
                'NOT_FOUND',
                BiMsg::of('这篇百科还没开放', 'That encyclopedia article is not available yet'),
                404,
            );
        }
        $readIds = $this->progress->readTopicIds($child);
        if ($this->catalog->isLockedForChild($id, $child, $readIds)) {
            throw new ApiException(
                'TOPIC_LOCKED',
                BiMsg::of('先读完前面的小百科，再来标记读过吧', 'Finish the earlier articles before marking this one read'),
                403,
            );
        }

        $this->progress->markRead($child, $id);
        $detail = $this->catalog->topicDetail($id, $this->progress->readTopicIds($child), $child);

        return response()->json(['data' => $detail]);
    }

    public function collectWord(Request $request, string $id): JsonResponse
    {
        $child = $this->context->child();
        if (! $this->catalog->isVisibleToChild($id, $child)) {
            throw new ApiException(
                'NOT_FOUND',
                BiMsg::of('这篇百科还没开放', 'That encyclopedia article is not available yet'),
                404,
            );
        }
        $readIds = $this->progress->readTopicIds($child);
        if ($this->catalog->isLockedForChild($id, $child, $readIds)) {
            throw new ApiException(
                'TOPIC_LOCKED',
                BiMsg::of('先读完前面的小百科，再来收集生词吧', 'Finish the earlier articles before collecting words'),
                403,
            );
        }

        $word = (string) $request->input('word', '');
        if (! $this->catalog->topicContainsWord($id, $word)) {
            throw ValidationException::withMessages([
                'word' => BiMsg::of('这个词不在这篇百科里', 'That word is not in this article'),
            ]);
        }

        $child->loadMissing('family');
        $today = $this->clock->today((string) $child->family->timezone)->toDateString();
        $result = $this->vocabulary->appendCollectedEncyclopediaWord(
            $child,
            $today,
            $word,
            $this->catalog->meaningForWord($id, $word),
        );

        return response()->json(['data' => $result]);
    }
}
