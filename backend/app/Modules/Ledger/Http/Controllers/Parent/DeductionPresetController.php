<?php

namespace App\Modules\Ledger\Http\Controllers\Parent;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Ledger\Models\DeductionPreset;
use App\Modules\Ledger\Services\DeductionPresetSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeductionPresetController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly DeductionPresetSeeder $seeder,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        $familyId = $this->context->familyId();
        $this->seeder->ensureDefaults($familyId);

        $rows = DeductionPreset::query()
            ->where('family_id', $familyId)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (DeductionPreset $p) => $this->payload($p))
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $familyId = $this->context->familyId();
        $data = $this->validated($request);
        if ($data['point_amount'] === 0 && $data['coin_amount'] === 0) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('请至少填写积分或金币数量', 'Enter points or coins'), 422);
        }

        $maxSort = (int) DeductionPreset::query()->where('family_id', $familyId)->max('sort');
        $preset = DeductionPreset::query()->create([
            'family_id' => $familyId,
            'title' => $data['title'],
            'point_amount' => $data['point_amount'],
            'coin_amount' => $data['coin_amount'],
            'sort' => $data['sort'] ?? ($maxSort + 10),
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->audit->log('ledger.deduction_preset_created', $familyId, $preset, [
            'title' => $preset->title,
            'point_amount' => $preset->point_amount,
            'coin_amount' => $preset->coin_amount,
        ]);

        return response()->json(['data' => $this->payload($preset)], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $preset = $this->findOwned($id);
        $data = $this->validated($request, partial: true);
        if (
            array_key_exists('point_amount', $data)
            || array_key_exists('coin_amount', $data)
        ) {
            $point = (int) ($data['point_amount'] ?? $preset->point_amount);
            $coin = (int) ($data['coin_amount'] ?? $preset->coin_amount);
            if ($point === 0 && $coin === 0) {
                throw new ApiException('VALIDATION_FAILED', BiMsg::of('请至少填写积分或金币数量', 'Enter points or coins'), 422);
            }
        }

        $preset->fill($data)->save();

        $this->audit->log('ledger.deduction_preset_updated', $this->context->familyId(), $preset, $data);

        return response()->json(['data' => $this->payload($preset->fresh())]);
    }

    public function destroy(int $id): JsonResponse
    {
        $preset = $this->findOwned($id);
        $snapshot = $this->payload($preset);
        $preset->delete();

        $this->audit->log('ledger.deduction_preset_deleted', $this->context->familyId(), null, $snapshot);

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * @return array{title: string, point_amount?: int, coin_amount?: int, sort?: int, is_active?: bool}
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $rules = [
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'min:1', 'max:128'],
            'point_amount' => [$partial ? 'sometimes' : 'required', 'integer', 'min:0', 'max:50'],
            'coin_amount' => [$partial ? 'sometimes' : 'required', 'integer', 'min:0', 'max:50'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        /** @var array{title?: string, point_amount?: int, coin_amount?: int, sort?: int, is_active?: bool} $data */
        $data = $request->validate($rules);
        if (isset($data['title'])) {
            $data['title'] = trim($data['title']);
        }

        return $data;
    }

    private function findOwned(int $id): DeductionPreset
    {
        $preset = DeductionPreset::query()
            ->where('family_id', $this->context->familyId())
            ->whereKey($id)
            ->first();
        if ($preset === null) {
            throw new ApiException('NOT_FOUND', BiMsg::of('扣分项不存在', 'Deduction preset not found'), 404);
        }

        return $preset;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DeductionPreset $preset): array
    {
        return [
            'id' => $preset->id,
            'title' => $preset->title,
            'point_amount' => $preset->point_amount,
            'coin_amount' => $preset->coin_amount,
            'sort' => $preset->sort,
            'is_active' => $preset->is_active,
        ];
    }
}
