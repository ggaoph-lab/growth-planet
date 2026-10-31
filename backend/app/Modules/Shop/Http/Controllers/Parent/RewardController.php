<?php

namespace App\Modules\Shop\Http\Controllers\Parent;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Models\Attachment;
use App\Modules\Core\Services\ImageAttachmentService;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Shop\Enums\RewardKind;
use App\Modules\Shop\Enums\RewardStatus;
use App\Modules\Shop\Http\Resources\RewardResource;
use App\Modules\Shop\Models\Reward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RewardController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly ImageAttachmentService $images,
    ) {}

    public function index(): JsonResponse
    {
        $rewards = Reward::query()
            ->where('family_id', $this->context->familyId())
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $rewards->map(fn (Reward $r) => (new RewardResource($r, 'parent'))->resolve())->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, creating: true);

        $reward = DB::transaction(function () use ($request, $data) {
            $kind = RewardKind::from($data['kind']);
            [$coinPrice, $pointPrice] = $this->resolvePrices($data);
            $reward = Reward::query()->create([
                'family_id' => $this->context->familyId(),
                'name' => $data['name'],
                'game_minutes' => $data['game_minutes'] ?? null,
                'description' => $data['description'] ?? null,
                'kind' => $kind,
                'coin_price' => $coinPrice,
                'point_price' => $pointPrice,
                'stock' => $data['stock'] ?? null,
                'requires_approval' => $data['requires_approval'] ?? true,
                'status' => $data['status'] ?? RewardStatus::Draft,
                'version' => 1,
            ]);

            if ($request->hasFile('image')) {
                $this->attachImage($reward, $request);
            }

            return $reward->fresh();
        });

        return response()->json(['data' => (new RewardResource($reward, 'parent'))->resolve()], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $reward = $this->find($id);
        $data = $this->validated($request, creating: false);
        $kind = isset($data['kind']) ? RewardKind::from($data['kind']) : $reward->kind;
        if (isset($data['kind']) || array_key_exists('coin_price', $data) || array_key_exists('point_price', $data)) {
            [$coinPrice, $pointPrice] = $this->resolvePrices([
                'coin_price' => $data['coin_price'] ?? $reward->coin_price,
                'point_price' => array_key_exists('point_price', $data) ? $data['point_price'] : $reward->point_price,
            ]);
            $data['kind'] = $kind;
            $data['coin_price'] = $coinPrice;
            $data['point_price'] = $pointPrice;
        }
        $reward->fill($data);
        $reward->version++;
        $reward->save();

        return response()->json(['data' => (new RewardResource($reward->fresh(), 'parent'))->resolve()]);
    }

    public function uploadImage(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'max:10240'],
        ]);

        $reward = DB::transaction(function () use ($id, $request) {
            $reward = $this->find($id);
            $this->replaceImage($reward, $request);
            $reward->version++;
            $reward->save();

            return $reward->fresh();
        });

        return response()->json(['data' => (new RewardResource($reward, 'parent'))->resolve()]);
    }

    public function destroyImage(int $id): JsonResponse
    {
        $reward = DB::transaction(function () use ($id) {
            $reward = $this->find($id);
            $this->clearImage($reward);
            $reward->version++;
            $reward->save();

            return $reward->fresh();
        });

        return response()->json(['data' => (new RewardResource($reward, 'parent'))->resolve()]);
    }

    public function destroy(int $id): JsonResponse
    {
        DB::transaction(function () use ($id) {
            $reward = $this->find($id);
            $this->clearImage($reward);
            if ($reward->isDirty()) {
                $reward->save();
            }
            $reward->delete();
        });

        return response()->json(['data' => ['ok' => true]]);
    }

    public function image(int $id): BinaryFileResponse
    {
        $reward = $this->find($id);
        $attachment = $this->attachmentFor($reward);
        $path = $attachment ? $this->images->absolutePath($attachment) : null;
        if ($path === null) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => $attachment->mime ?: 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function find(int $id): Reward
    {
        return Reward::query()
            ->where('family_id', $this->context->familyId())
            ->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'kind' => [$creating ? 'required' : 'sometimes', Rule::enum(RewardKind::class)],
            'coin_price' => ['nullable', 'integer', 'min:0'],
            'point_price' => ['nullable', 'integer', 'min:0'],
            'game_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'requires_approval' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::enum(RewardStatus::class)],
            'image' => ['nullable', 'file', 'max:10240'],
        ]);
    }

    /**
     * Currency is chosen by the parent: point_price >= 1 → 积分, else 成长币.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: int|null}
     */
    private function resolvePrices(array $data): array
    {
        $point = (int) ($data['point_price'] ?? 0);
        if ($point >= 1) {
            return [0, $point];
        }

        $coin = (int) ($data['coin_price'] ?? 0);
        if ($coin < 1) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('请填写价格', 'Enter a price'), 422);
        }

        return [$coin, null];
    }

    private function attachImage(Reward $reward, Request $request): void
    {
        $attachment = $this->images->storeImage(
            $request->file('image'),
            $this->context->familyId(),
            $this->context->user()->id,
            Reward::class,
            $reward->id,
            'rewards',
        );
        $reward->image_attachment_id = $attachment->id;
        $reward->save();
    }

    private function replaceImage(Reward $reward, Request $request): void
    {
        $this->clearImage($reward);
        $this->attachImage($reward, $request);
    }

    private function clearImage(Reward $reward): void
    {
        $attachment = $this->attachmentFor($reward);
        if ($attachment === null) {
            $reward->image_attachment_id = null;

            return;
        }
        $this->images->delete($attachment);
        $reward->image_attachment_id = null;
    }

    private function attachmentFor(Reward $reward): ?Attachment
    {
        if ($reward->image_attachment_id === null) {
            return null;
        }

        return Attachment::query()
            ->where('family_id', $this->context->familyId())
            ->whereKey($reward->image_attachment_id)
            ->first();
    }
}
