<?php

namespace App\Modules\Identity\Http\Controllers\Parent;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Settings\Settings;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Identity\Support\KidPlanets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanetSettingsController
{
    public function index(CurrentContext $context): JsonResponse
    {
        return response()->json(['data' => array_map(fn ($planet) => $planet + [
            'enabled' => KidPlanets::enabled($planet['key'], $context->familyId()),
        ], KidPlanets::all())]);
    }

    public function update(Request $request, CurrentContext $context, Settings $settings, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', Rule::in(array_column(KidPlanets::all(), 'key'))],
            'enabled' => ['required', 'boolean'],
        ]);
        $settings->set('planets.'.$data['key'], (bool) $data['enabled'], $context->familyId(), $context->user()->id);
        $audit->log('planet.updated', $context->familyId(), $context->family(), $data);

        return $this->index($context);
    }
}
