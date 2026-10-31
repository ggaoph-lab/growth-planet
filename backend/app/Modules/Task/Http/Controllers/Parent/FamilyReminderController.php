<?php

namespace App\Modules\Task\Http\Controllers\Parent;

use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FamilyReminderController
{
    public function __construct(private readonly CurrentContext $context) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->rows()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $id = DB::table('family_reminders')->insertGetId([
            'family_id' => $this->context->familyId(),
            'title' => $data['title'],
            'weekdays' => json_encode($data['weekdays']),
            'times' => json_encode($data['times']),
            'enabled' => $data['enabled'] ?? true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['data' => $this->row($id)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->row($id);
        $data = $this->validated($request);
        DB::table('family_reminders')->where('family_id', $this->context->familyId())->where('id', $id)->update([
            'title' => $data['title'],
            'weekdays' => json_encode($data['weekdays']),
            'times' => json_encode($data['times']),
            'enabled' => $data['enabled'] ?? true,
            'updated_at' => now(),
        ]);
        return response()->json(['data' => $this->row($id)]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->row($id);
        DB::table('family_reminders')->where('family_id', $this->context->familyId())->where('id', $id)->delete();
        return response()->json(['data' => ['deleted' => true]]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'times' => ['required', 'array', 'min:1', 'max:8'],
            'times.*' => ['required', 'date_format:H:i', 'distinct'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        sort($data['weekdays']); sort($data['times']);
        return $data;
    }

    private function row(int $id): array
    {
        $row = DB::table('family_reminders')->where('family_id', $this->context->familyId())->where('id', $id)->first();
        abort_if($row === null, 404);
        return $this->present($row);
    }

    private function rows(): array
    {
        return DB::table('family_reminders')->where('family_id', $this->context->familyId())
            ->orderBy('id')->get()->map(fn ($row) => $this->present($row))->all();
    }

    private function present(object $row): array
    {
        return ['id' => (int) $row->id, 'title' => $row->title,
            'weekdays' => json_decode($row->weekdays, true), 'times' => json_decode($row->times, true),
            'enabled' => (bool) $row->enabled, 'updated_at' => $row->updated_at];
    }
}
