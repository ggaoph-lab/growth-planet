<?php

namespace App\Modules\Identity\Http\Controllers\Parent;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Identity\Services\Sudo;
use App\Modules\Identity\Support\CurrentContext;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FamilyController
{
    public function show(CurrentContext $context): JsonResponse
    {
        return response()->json(['data' => $this->present($context)]);
    }

    /** Changing the timezone shifts every day/week boundary, so it requires sudo; renaming does not. */
    public function update(Request $request, CurrentContext $context, AuditLogger $audit, Sudo $sudo): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:64'],
            'timezone' => ['sometimes', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ]);

        $family = $context->family();
        if (isset($data['timezone']) && $data['timezone'] !== $family->timezone && ! $sudo->active($request->session())) {
            throw ApiException::sudoRequired();
        }

        $before = $family->only(array_keys($data));
        $family->fill($data)->save();

        $audit->log('family.updated', $family->id, $family, ['before' => $before, 'after' => $data]);

        return response()->json(['data' => $this->present($context)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CurrentContext $context): array
    {
        $family = $context->family()->refresh();

        return [
            'id' => $family->id,
            'name' => $family->name,
            'timezone' => $family->timezone,
            'locale' => $family->locale,
            'week_starts_on' => $family->week_starts_on,
        ];
    }
}
