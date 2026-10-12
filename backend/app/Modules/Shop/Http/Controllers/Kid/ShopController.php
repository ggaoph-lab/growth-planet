<?php

namespace App\Modules\Shop\Http\Controllers\Kid;

use App\Modules\Core\Models\Attachment;
use App\Modules\Core\Services\ImageAttachmentService;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Shop\Enums\RewardStatus;
use App\Modules\Shop\Http\Resources\RewardResource;
use App\Modules\Shop\Models\Reward;
use App\Modules\Shop\Models\RewardRedemption;
use App\Modules\Shop\Services\RedemptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ShopController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly RedemptionService $redemptions,
        private readonly ImageAttachmentService $images,
    ) {}

    public function index(): JsonResponse
    {
        $rewards = Reward::query()
            ->where('family_id', $this->context->familyId())
            ->where('status', RewardStatus::OnShelf)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $rewards->map(fn (Reward $r) => (new RewardResource($r, 'kid'))->resolve())->values(),
        ]);
    }

    public function image(int $id): BinaryFileResponse
    {
        $reward = Reward::query()
            ->where('family_id', $this->context->familyId())
            ->where('status', RewardStatus::OnShelf)
            ->findOrFail($id);

        $attachment = $reward->image_attachment_id
            ? Attachment::query()
                ->where('family_id', $this->context->familyId())
                ->whereKey($reward->image_attachment_id)
                ->first()
            : null;
        $path = $attachment ? $this->images->absolutePath($attachment) : null;
        if ($path === null) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => $attachment->mime ?: 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate(['reward_id' => ['required', 'integer']]);
        $key = $request->header('Idempotency-Key');
        $child = $this->context->child();
        $reward = Reward::query()
            ->where('family_id', $child->family_id)
            ->findOrFail($data['reward_id']);

        $redemption = $this->redemptions->request(
            $reward,
            $child,
            $this->context->family(),
            $key ?? 'missing-key',
        );

        return response()->json(['data' => $redemption], 201);
    }

    public function cancel(string $ulid): JsonResponse
    {
        $child = $this->context->child();
        $redemption = RewardRedemption::query()
            ->where('family_id', $child->family_id)
            ->where('ulid', $ulid)
            ->firstOrFail();

        return response()->json(['data' => $this->redemptions->cancel($redemption, $child)]);
    }
}
