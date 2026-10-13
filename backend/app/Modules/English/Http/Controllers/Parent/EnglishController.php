<?php

namespace App\Modules\English\Http\Controllers\Parent;

use App\Modules\English\Models\EnglishShow;
use App\Modules\English\Models\EnglishWatchLog;
use App\Modules\English\Services\EnglishDraftService;
use App\Modules\English\Services\EnglishLearningService;
use App\Modules\English\Services\EnglishSpeakService;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EnglishController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly EnglishLearningService $learning,
        private readonly EnglishDraftService $drafts,
        private readonly EnglishSpeakService $speak,
    ) {}

    public function indexShows(): JsonResponse
    {
        $shows = EnglishShow::query()
            ->where('family_id', $this->context->familyId())
            ->orderBy('sort')
            ->orderBy('title')
            ->get();

        return response()->json(['data' => $shows]);
    }

    public function storeShow(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:128'],
            'emoji' => ['nullable', 'string', 'max:16'],
            'note' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'integer'],
        ]);

        $show = EnglishShow::query()->create([
            'family_id' => $this->context->familyId(),
            'title' => $data['title'],
            'emoji' => $data['emoji'] ?? '📺',
            'note' => $data['note'] ?? null,
            'active' => true,
            'sort' => $data['sort'] ?? 0,
        ]);

        return response()->json(['data' => $show], 201);
    }

    public function updateShow(Request $request, int $id): JsonResponse
    {
        $show = EnglishShow::query()->where('family_id', $this->context->familyId())->findOrFail($id);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:128'],
            'emoji' => ['nullable', 'string', 'max:16'],
            'note' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'sort' => ['nullable', 'integer'],
        ]);

        $show->fill($data);
        $show->save();

        return response()->json(['data' => $show]);
    }

    public function indexLogs(Request $request): JsonResponse
    {
        $childId = $request->query('child_id');
        $query = EnglishWatchLog::query()
            ->where('family_id', $this->context->familyId())
            ->with('show')
            ->orderByDesc('id');

        if ($childId) {
            $this->context->findChild((int) $childId);
            $query->where('child_id', (int) $childId);
        }
        if ($from = $request->query('from')) {
            $query->where('watch_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('watch_date', '<=', $to);
        }

        return response()->json(['data' => $query->limit(200)->get()]);
    }

    public function learning(Request $request): JsonResponse
    {
        $data = $request->validate(['child_id' => ['required', 'integer']]);
        $child = $this->context->findChild((int) $data['child_id']);

        return response()->json(['data' => $this->learning->parentProgress($child)]);
    }

    public function learningSummary(Request $request): JsonResponse
    {
        $data = $request->validate(['child_id' => ['required', 'integer']]);
        $child = $this->context->findChild((int) $data['child_id']);

        return response()->json(['data' => $this->learning->parentSummary($child)]);
    }

    public function indexDrafts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'status' => ['nullable', 'string', 'in:pending,published,rejected'],
        ]);
        $child = $this->context->findChild((int) $data['child_id']);

        return response()->json(['data' => $this->drafts->list($child, $data['status'] ?? null)]);
    }

    public function generateDraft(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'kind' => ['required', 'string', 'in:grammar,story'],
        ]);
        $child = $this->context->findChild((int) $data['child_id']);

        return response()->json(['data' => $this->drafts->generate($child, $data['kind'])], 201);
    }

    public function publishDraft(int $id): JsonResponse
    {
        $childId = (int) request()->validate(['child_id' => ['required', 'integer']])['child_id'];
        $child = $this->context->findChild($childId);

        return response()->json(['data' => $this->drafts->publish($child, $id)]);
    }

    public function rejectDraft(int $id): JsonResponse
    {
        $childId = (int) request()->validate(['child_id' => ['required', 'integer']])['child_id'];
        $child = $this->context->findChild($childId);

        return response()->json(['data' => $this->drafts->reject($child, $id)]);
    }

    public function indexSpeaks(Request $request): JsonResponse
    {
        $data = $request->validate(['child_id' => ['required', 'integer']]);
        $child = $this->context->findChild((int) $data['child_id']);

        return response()->json(['data' => $this->speak->listForParent($child)]);
    }

    public function speakAudio(Request $request, int $id): BinaryFileResponse
    {
        $data = $request->validate(['child_id' => ['required', 'integer']]);
        $child = $this->context->findChild((int) $data['child_id']);
        $row = $this->speak->findForParent($child, $id);
        $path = $this->speak->absolutePath($row);

        return response()->file($path, [
            'Content-Type' => (string) $row->mime,
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
