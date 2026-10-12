<?php

namespace App\Modules\Identity\Http\Controllers\Parent;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Identity\Enums\MemberRole;
use App\Modules\Identity\Enums\MemberStatus;
use App\Modules\Identity\Models\FamilyMember;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ParentAccountController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        $members = FamilyMember::query()
            ->with('user:id,username,email,last_login_at')
            ->where('family_id', $this->context->familyId())
            ->where('role', MemberRole::Parent)
            ->where('status', MemberStatus::Active)
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $members->map(fn (FamilyMember $m) => $this->present($m))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:1', 'max:32'],
            'username' => ['required', 'string', 'min:3', 'max:32', 'alpha_dash', 'unique:users,username'],
            'password' => ['required', 'string', 'min:10', 'max:255'],
            'email' => ['nullable', 'email', 'max:191', 'unique:users,email'],
        ]);

        $username = Str::lower(trim($data['username']));

        $member = DB::transaction(function () use ($data, $username) {
            $user = User::query()->create([
                'username' => $username,
                'email' => $data['email'] ?? null,
                'password' => $data['password'],
            ]);

            $member = new FamilyMember(['display_name' => $data['display_name']]);
            $member->family_id = $this->context->familyId();
            $member->user_id = $user->id;
            $member->role = MemberRole::Parent;
            $member->status = MemberStatus::Active;
            $member->save();

            return $member->load('user:id,username,email,last_login_at');
        });

        $this->audit->log('parent.created', $this->context->familyId(), $member, [
            'username' => $username,
            'display_name' => $data['display_name'],
        ]);

        return response()->json(['data' => $this->present($member)], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $member = FamilyMember::query()
            ->with('user')
            ->where('family_id', $this->context->familyId())
            ->where('role', MemberRole::Parent)
            ->whereKey($id)
            ->firstOrFail();

        if ($member->user_id === $this->context->user()->id) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('不能删除当前登录的家长账号', 'You cannot delete the account you are signed in with'), 422);
        }

        $activeParents = FamilyMember::query()
            ->where('family_id', $this->context->familyId())
            ->where('role', MemberRole::Parent)
            ->where('status', MemberStatus::Active)
            ->count();

        if ($activeParents <= 1) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('至少保留一位家长账号', 'Keep at least one parent account'), 422);
        }

        $username = $member->user?->username;
        DB::transaction(function () use ($member) {
            $user = $member->user;
            $member->status = MemberStatus::Disabled;
            $member->save();
            if ($user !== null) {
                // Free unique login fields so the username can be reused; keep the row for FK history.
                $user->forceFill([
                    'username' => null,
                    'email' => null,
                    'password' => null,
                ])->save();
            }
        });

        $this->audit->log('parent.deleted', $this->context->familyId(), $member, [
            'member_id' => $id,
            'username' => $username,
        ]);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:10', 'max:255', 'confirmed'],
        ]);

        $user = $this->context->user();
        if (! Hash::check($data['current_password'], (string) $user->password)) {
            throw new ApiException('INVALID_CREDENTIALS', BiMsg::of('当前密码不正确', 'Current password is incorrect'), 422);
        }

        $user->password = $data['password'];
        $user->save();

        $this->audit->log('parent.password_changed', $this->context->familyId(), $user);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['sometimes', 'string', 'min:1', 'max:32'],
            'email' => ['nullable', 'email', 'max:191', Rule::unique('users', 'email')->ignore($this->context->user()->id)],
        ]);

        $member = $this->context->member();
        $user = $this->context->user();

        if (isset($data['display_name'])) {
            $member->display_name = $data['display_name'];
            $member->save();
        }
        if (array_key_exists('email', $data)) {
            $user->email = $data['email'];
            $user->save();
        }

        $this->audit->log('parent.updated', $this->context->familyId(), $member, $data);

        return response()->json(['data' => $this->present($member->fresh('user'))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(FamilyMember $member): array
    {
        return [
            'id' => $member->id,
            'user_id' => $member->user_id,
            'display_name' => $member->display_name,
            'username' => $member->user?->username,
            'email' => $member->user?->email,
            'status' => $member->status->value,
            'is_self' => $member->user_id === $this->context->user()->id,
            'last_login_at' => $member->user?->last_login_at?->toIso8601String(),
        ];
    }
}
