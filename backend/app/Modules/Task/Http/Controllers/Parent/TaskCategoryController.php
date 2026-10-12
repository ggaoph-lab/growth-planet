<?php

namespace App\Modules\Task\Http\Controllers\Parent;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Task\Models\TaskCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskCategoryController
{
    public function __construct(private readonly CurrentContext $context) {}

    public function index(): JsonResponse
    {
        $categories = TaskCategory::query()
            ->where('family_id', $this->context->familyId())
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateCategory($request);
        $familyId = $this->context->familyId();
        $sort = (int) TaskCategory::query()->where('family_id', $familyId)->max('sort') + 1;

        $category = TaskCategory::query()->create([
            'family_id' => $familyId,
            'name' => $data['name'],
            'sort' => $sort,
        ]);

        return response()->json(['data' => $category], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $category = $this->find($id);
        $data = $this->validateCategory($request, $category->id);
        $category->fill($data);
        $category->save();

        return response()->json(['data' => $category]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->find($id)->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function find(int $id): TaskCategory
    {
        return TaskCategory::query()
            ->where('family_id', $this->context->familyId())
            ->findOrFail($id);
    }

    /**
     * @return array{name: string}
     */
    private function validateCategory(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:32',
                Rule::unique('task_categories', 'name')
                    ->where('family_id', $this->context->familyId())
                    ->ignore($ignoreId),
            ],
        ]);
    }
}
