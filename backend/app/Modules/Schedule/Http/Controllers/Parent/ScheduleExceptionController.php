<?php

namespace App\Modules\Schedule\Http\Controllers\Parent;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Schedule\Enums\ScheduleExceptionType;
use App\Modules\Schedule\Models\ScheduleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ScheduleExceptionController
{
    public function __construct(private readonly CurrentContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = ScheduleException::query()->where('family_id', $this->context->familyId());

        if (isset($data['child_id'])) {
            $child = $this->context->findChild($data['child_id']);
            $query->where(function ($q) use ($child) {
                $q->where('child_id', $child->id)->orWhereNull('child_id');
            });
        }

        if (isset($data['from'])) {
            $query->whereDate('exception_date', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->whereDate('exception_date', '<=', $data['to']);
        }

        return response()->json([
            'data' => $query->orderBy('exception_date')->get()->map(fn (ScheduleException $e) => $this->serialize($e)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $exception = ScheduleException::query()->create([
            'family_id' => $this->context->familyId(),
            'child_id' => $data['child_id'] ?? null,
            'exception_date' => $data['exception_date'],
            'type' => $data['type'],
            'follow_weekday' => $data['follow_weekday'] ?? null,
            'custom_periods' => $data['custom_periods'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        return response()->json(['data' => $this->serialize($exception)], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $exception = ScheduleException::query()
            ->where('family_id', $this->context->familyId())
            ->findOrFail($id);
        $data = $this->validated($request, updating: true);
        $exception->fill([
            'child_id' => array_key_exists('child_id', $data) ? $data['child_id'] : $exception->child_id,
            'exception_date' => $data['exception_date'] ?? $exception->exception_date,
            'type' => $data['type'] ?? $exception->type,
            'follow_weekday' => array_key_exists('follow_weekday', $data) ? $data['follow_weekday'] : $exception->follow_weekday,
            'custom_periods' => array_key_exists('custom_periods', $data) ? $data['custom_periods'] : $exception->custom_periods,
            'note' => array_key_exists('note', $data) ? $data['note'] : $exception->note,
        ]);
        $this->assertTypeFields($exception->type instanceof ScheduleExceptionType ? $exception->type : ScheduleExceptionType::from((string) $exception->type), $exception->follow_weekday, $exception->custom_periods);
        $exception->save();

        return response()->json(['data' => $this->serialize($exception)]);
    }

    public function destroy(int $id): JsonResponse
    {
        $exception = ScheduleException::query()
            ->where('family_id', $this->context->familyId())
            ->findOrFail($id);
        $exception->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $updating = false): array
    {
        $rules = [
            'child_id' => [$updating ? 'sometimes' : 'nullable', 'integer', 'nullable'],
            'exception_date' => [$updating ? 'sometimes' : 'required', 'date'],
            'type' => [$updating ? 'sometimes' : 'required', Rule::enum(ScheduleExceptionType::class)],
            'follow_weekday' => ['nullable', 'integer', 'min:1', 'max:7'],
            'custom_periods' => ['nullable', 'array'],
            'custom_periods.*.period_index' => ['nullable', 'integer', 'min:1', 'max:12'],
            'custom_periods.*.subject' => ['required_with:custom_periods', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
        $data = $request->validate($rules);

        if (isset($data['child_id'])) {
            $this->context->findChild($data['child_id']);
        }

        if (isset($data['type'])) {
            $type = $data['type'] instanceof ScheduleExceptionType
                ? $data['type']
                : ScheduleExceptionType::from((string) $data['type']);
            $this->assertTypeFields($type, $data['follow_weekday'] ?? null, $data['custom_periods'] ?? null);
        }

        return $data;
    }

    /**
     * @param  list<mixed>|null  $custom
     */
    private function assertTypeFields(ScheduleExceptionType $type, ?int $followWeekday, ?array $custom): void
    {
        if ($type === ScheduleExceptionType::MakeupDay && ($followWeekday === null || $followWeekday < 1 || $followWeekday > 7)) {
            throw ValidationException::withMessages(['follow_weekday' => ['调休需指定 follow_weekday']]);
        }
        if ($type === ScheduleExceptionType::Custom && ($custom === null || $custom === [])) {
            throw ValidationException::withMessages(['custom_periods' => ['临时课表需提供 custom_periods']]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ScheduleException $e): array
    {
        return [
            'id' => $e->id,
            'family_id' => $e->family_id,
            'child_id' => $e->child_id,
            'exception_date' => $e->exception_date?->toDateString(),
            'type' => $e->type->value,
            'follow_weekday' => $e->follow_weekday,
            'custom_periods' => $e->custom_periods,
            'note' => $e->note,
        ];
    }
}
