<?php

namespace App\Modules\Identity\Http\Controllers\Parent;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\FamilyInvite;
use App\Modules\Identity\Support\CurrentContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FamilyInviteController
{
    private const DEFAULT_EXPIRES_DAYS = 14;

    private const CODE_LENGTH = 12;

    public function __construct(
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
    ) {}

    public function index(CurrentContext $context): JsonResponse
    {
        $now = $this->clock->now();
        $tz = $context->family()->timezone;

        $items = FamilyInvite::query()
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (FamilyInvite $invite) => $this->present($invite, $now, $tz));

        return response()->json(['data' => $items]);
    }

    public function store(Request $request, CurrentContext $context): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:64'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $plain = $this->generateCode();
        $expiresDays = $data['expires_in_days'] ?? self::DEFAULT_EXPIRES_DAYS;
        $now = $this->clock->now();

        $invite = new FamilyInvite;
        $invite->code_hash = FamilyInvite::hashCode($plain);
        $invite->code_prefix = substr($plain, 0, 4);
        $invite->note = isset($data['note']) ? trim((string) $data['note']) ?: null : null;
        $invite->created_by = $context->user()->id;
        $invite->expires_at = $now->addDays((int) $expiresDays);
        $invite->save();

        $this->audit->log(
            'invite.created',
            $context->familyId(),
            $invite,
            [
                'invite_id' => $invite->id,
                'code_prefix' => $invite->code_prefix,
                'note' => $invite->note,
                'expires_at' => Clock::iso($invite->expires_at, $context->family()->timezone),
            ],
        );

        return response()->json([
            'data' => array_merge(
                $this->present($invite, $now, $context->family()->timezone),
                ['code' => $plain],
            ),
        ], 201);
    }

    public function destroy(int $id, CurrentContext $context): JsonResponse
    {
        $invite = FamilyInvite::query()->findOrFail($id);
        $now = $this->clock->now();

        if ($invite->used_at !== null) {
            throw new ApiException(
                'INVALID_STATE',
                BiMsg::of('邀请码已使用，无法吊销', 'Invite code has already been used'),
                409,
            );
        }

        if ($invite->revoked_at !== null) {
            throw new ApiException(
                'INVALID_STATE',
                BiMsg::of('邀请码已吊销', 'Invite code is already revoked'),
                409,
            );
        }

        $invite->revoked_at = $now;
        $invite->save();

        $this->audit->log(
            'invite.revoked',
            $context->familyId(),
            $invite,
            ['invite_id' => $invite->id, 'code_prefix' => $invite->code_prefix],
        );

        return response()->json([
            'data' => $this->present($invite->fresh(), $now, $context->family()->timezone),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(FamilyInvite $invite, CarbonImmutable $now, string $timezone): array
    {
        return [
            'id' => $invite->id,
            'code_prefix' => $invite->code_prefix,
            'note' => $invite->note,
            'status' => $invite->status($now),
            'expires_at' => Clock::iso($invite->expires_at, $timezone),
            'used_at' => Clock::iso($invite->used_at, $timezone),
            'used_by_family_id' => $invite->used_by_family_id,
            'revoked_at' => Clock::iso($invite->revoked_at, $timezone),
            'created_at' => Clock::iso($invite->created_at?->toImmutable(), $timezone),
        ];
    }

    private function generateCode(): string
    {
        // Crockford-ish alphabet without ambiguous 0/O/1/I.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }
}
