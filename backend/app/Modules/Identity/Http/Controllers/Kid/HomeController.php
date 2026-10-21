<?php

namespace App\Modules\Identity\Http\Controllers\Kid;

use App\Modules\Identity\Http\Resources\KidProfileResource;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Identity\Support\KidPlanets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeController
{
    public function show(CurrentContext $context): JsonResponse
    {
        return response()->json(['data' => [
            'profile' => (new KidProfileResource($context->child()))->resolve(),
            'planets' => KidPlanets::forFamily($context->familyId()),
        ]]);
    }

    public function updatePreferences(Request $request, CurrentContext $context): JsonResponse
    {
        $data = $request->validate([
            'animations' => ['sometimes', 'boolean'],
            'sounds' => ['sometimes', 'boolean'],
        ]);

        $child = $context->child();
        $child->preferences = array_merge($child->resolvedPreferences(), $data);
        $child->save();

        return response()->json(['data' => $child->resolvedPreferences()]);
    }
}
