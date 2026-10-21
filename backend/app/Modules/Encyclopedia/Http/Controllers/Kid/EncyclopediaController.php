<?php

namespace App\Modules\Encyclopedia\Http\Controllers\Kid;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Encyclopedia\Services\EncyclopediaCatalog;
use App\Modules\Encyclopedia\Services\EncyclopediaProgress;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;

class EncyclopediaController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly EncyclopediaCatalog $catalog,
        private readonly EncyclopediaProgress $progress,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->catalog->subjectSummary()]);
    }

    public function domain(string $domain): JsonResponse
    {
        $child = $this->context->child();
        $topics = $this->catalog->topicsForDomain($domain, $this->progress->readTopicIds($child));
        if ($topics === []) {
            throw new ApiException(
                'NOT_FOUND',
                BiMsg::of('还没有这个百科分类', 'That encyclopedia section is not available yet'),
                404,
            );
        }

        $meta = null;
        foreach ($this->catalog->subjectSummary()['domains'] as $row) {
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
        $detail = $this->catalog->topicDetail($id, $this->progress->readTopicIds($child));
        if ($detail === null) {
            throw new ApiException(
                'NOT_FOUND',
                BiMsg::of('这篇百科还没开放', 'That encyclopedia article is not available yet'),
                404,
            );
        }

        return response()->json(['data' => $detail]);
    }

    public function markRead(string $id): JsonResponse
    {
        if (! $this->catalog->isPublished($id)) {
            throw new ApiException(
                'NOT_FOUND',
                BiMsg::of('这篇百科还没开放', 'That encyclopedia article is not available yet'),
                404,
            );
        }

        $child = $this->context->child();
        $this->progress->markRead($child, $id);
        $detail = $this->catalog->topicDetail($id, $this->progress->readTopicIds($child));

        return response()->json(['data' => $detail]);
    }
}
