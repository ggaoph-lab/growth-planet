<?php

namespace App\Modules\Shop\Services;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Shop\Enums\RewardKind;
use App\Modules\Shop\Enums\RewardStatus;
use App\Modules\Shop\Enums\WishStatus;
use App\Modules\Shop\Models\Reward;
use App\Modules\Shop\Models\Wish;
use Illuminate\Support\Facades\DB;

class WishService
{
    public function create(ChildProfile $child, string $name, ?string $description, ?int $coinPrice): Wish
    {
        return Wish::query()->create([
            'family_id' => $child->family_id,
            'child_id' => $child->id,
            'name' => $name,
            'description' => $description,
            'coin_price' => $coinPrice,
            'status' => WishStatus::Pending,
        ]);
    }

    public function reject(Wish $wish): Wish
    {
        return DB::transaction(function () use ($wish) {
            $locked = $this->lockPending($wish);
            $locked->status = WishStatus::Rejected;
            $locked->save();

            return $this->reload($locked);
        });
    }

    public function convert(Wish $wish, int $coinPrice, RewardKind $kind): Wish
    {
        return DB::transaction(function () use ($wish, $coinPrice, $kind) {
            $locked = $this->lockPending($wish);
            $reward = Reward::query()->create([
                'family_id' => $locked->family_id,
                'name' => $locked->name,
                'description' => $locked->description,
                'kind' => $kind,
                'coin_price' => $coinPrice,
                'stock' => null,
                'requires_approval' => true,
                'status' => RewardStatus::OnShelf,
                'version' => 1,
            ]);

            $locked->status = WishStatus::Converted;
            $locked->reward_id = $reward->id;
            $locked->coin_price = $coinPrice;
            $locked->save();

            return $this->reload($locked);
        });
    }

    private function reload(Wish $wish): Wish
    {
        return $wish->fresh(['child:id,nickname', 'reward']) ?? $wish;
    }

    private function lockPending(Wish $wish): Wish
    {
        $locked = Wish::query()->whereKey($wish->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== WishStatus::Pending) {
            throw new ApiException('WISH_NOT_PENDING', BiMsg::of('这个愿望已经处理过了', 'This wish was already handled'));
        }

        return $locked;
    }
}
