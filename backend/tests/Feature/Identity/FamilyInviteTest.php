<?php

namespace Tests\Feature\Identity;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Identity\Actions\RegisterFamilyWithInvite;
use App\Modules\Identity\Models\Family;
use App\Modules\Identity\Models\FamilyInvite;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_admin_can_create_list_and_revoke_invites(): void
    {
        ['parent' => $admin] = $this->makeFamily('admin');
        $admin->forceFill(['is_site_admin' => true])->save();

        $create = $this->actingAsParent($admin, sudo: true)
            ->postJson('/api/v1/parent/invites', ['note' => '二姨', 'expires_in_days' => 7])
            ->assertCreated()
            ->assertJsonPath('data.note', '二姨')
            ->assertJsonPath('data.status', 'available')
            ->assertJsonStructure(['data' => ['id', 'code', 'code_prefix', 'expires_at']]);

        $code = $create->json('data.code');
        $id = (int) $create->json('data.id');
        $this->assertSame(12, strlen((string) $code));
        $this->assertTrue(AuditLog::where('action', 'invite.created')->exists());

        $this->actingAsParent($admin)
            ->getJson('/api/v1/parent/invites')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonMissingPath('data.0.code');

        $this->actingAsParent($admin, sudo: true)
            ->deleteJson("/api/v1/parent/invites/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');

        $this->assertTrue(AuditLog::where('action', 'invite.revoked')->exists());
    }

    public function test_non_admin_cannot_manage_invites(): void
    {
        ['parent' => $parent] = $this->makeFamily('parent');

        $this->actingAsParent($parent)
            ->getJson('/api/v1/parent/invites')
            ->assertForbidden();

        $this->actingAsParent($parent, sudo: true)
            ->postJson('/api/v1/parent/invites', [])
            ->assertForbidden();
    }

    public function test_create_invite_requires_sudo(): void
    {
        ['parent' => $admin] = $this->makeFamily('admin');
        $admin->forceFill(['is_site_admin' => true])->save();

        $this->actingAsParent($admin)
            ->postJson('/api/v1/parent/invites', [])
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'SUDO_REQUIRED');
    }

    public function test_register_consumes_invite_once(): void
    {
        ['parent' => $admin] = $this->makeFamily('admin');
        $admin->forceFill(['is_site_admin' => true])->save();

        $code = $this->actingAsParent($admin, sudo: true)
            ->postJson('/api/v1/parent/invites', ['note' => 'uncle'])
            ->assertCreated()
            ->json('data.code');

        $this->postJson('/api/v1/auth/logout');

        $this->postJson('/api/v1/auth/register', [
            'invite_code' => $code,
            'family_name' => '亲戚之家',
            'timezone' => 'Asia/Shanghai',
            'display_name' => '叔叔',
            'username' => 'uncle_01',
            'password' => 'a-long-password',
        ])
            ->assertCreated()
            ->assertJsonPath('data.username', 'uncle_01');

        $invite = FamilyInvite::query()->sole();
        $this->assertNotNull($invite->used_at);
        $this->assertNotNull($invite->used_by_family_id);
        $this->assertSame('亲戚之家', Family::query()->findOrFail($invite->used_by_family_id)->name);
        $this->assertTrue(User::query()->where('username', 'uncle_01')->exists());
        $this->assertTrue(AuditLog::where('action', 'invite.consumed')->exists());
        $this->assertTrue(AuditLog::where('action', 'family.registered')->exists());

        $this->postJson('/api/v1/auth/register', [
            'invite_code' => $code,
            'family_name' => '再来一家',
            'timezone' => 'Asia/Shanghai',
            'display_name' => '别人',
            'username' => 'uncle_02',
            'password' => 'a-long-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_INVITE');
    }

    public function test_register_rejects_expired_and_revoked_codes(): void
    {
        ['parent' => $admin] = $this->makeFamily('admin');
        $admin->forceFill(['is_site_admin' => true])->save();

        $expiredCode = $this->actingAsParent($admin, sudo: true)
            ->postJson('/api/v1/parent/invites', [])
            ->json('data.code');
        FamilyInvite::query()->where('code_hash', FamilyInvite::hashCode($expiredCode))
            ->update(['expires_at' => now('UTC')->subMinute()]);

        $revoked = $this->actingAsParent($admin, sudo: true)
            ->postJson('/api/v1/parent/invites', [])
            ->json('data');
        $this->actingAsParent($admin, sudo: true)
            ->deleteJson('/api/v1/parent/invites/'.$revoked['id'])
            ->assertOk();

        $this->postJson('/api/v1/auth/logout');

        foreach ([$expiredCode, $revoked['code']] as $code) {
            $this->postJson('/api/v1/auth/register', [
                'invite_code' => $code,
                'family_name' => '失败之家',
                'timezone' => 'Asia/Shanghai',
                'display_name' => '家长',
                'username' => 'fail_'.substr(FamilyInvite::hashCode($code), 0, 6),
                'password' => 'a-long-password',
            ])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'INVALID_INVITE');
        }
    }

    public function test_register_locks_invite_row_against_double_spend(): void
    {
        ['parent' => $admin] = $this->makeFamily('admin');
        $admin->forceFill(['is_site_admin' => true])->save();

        $code = $this->actingAsParent($admin, sudo: true)
            ->postJson('/api/v1/parent/invites', [])
            ->json('data.code');

        $action = app(RegisterFamilyWithInvite::class);
        $first = $action->handle($code, '家庭甲', 'Asia/Shanghai', '甲', 'family_a', 'a-long-password');
        $this->assertGreaterThan(0, $first['family_id']);

        try {
            $action->handle($code, '家庭乙', 'Asia/Shanghai', '乙', 'family_b', 'a-long-password');
            $this->fail('Expected INVALID_INVITE on second consume');
        } catch (ApiException $e) {
            $this->assertSame('INVALID_INVITE', $e->errorCode);
        }

        $this->assertSame(1, FamilyInvite::query()->whereNotNull('used_at')->count());
        $this->assertSame(2, Family::query()->count()); // admin family + one new
        $this->assertFalse(User::query()->where('username', 'family_b')->exists());
    }

    public function test_site_admin_grant_command(): void
    {
        ['parent' => $parent] = $this->makeFamily('mama');
        $this->assertFalse($parent->fresh()->is_site_admin);

        $this->artisan('site-admin:grant', ['username' => 'mama'])->assertSuccessful();
        $this->assertTrue($parent->fresh()->is_site_admin);

        $this->artisan('site-admin:grant', ['username' => 'mama'])->assertSuccessful();
        $this->artisan('site-admin:grant', ['username' => 'missing'])->assertFailed();
    }

    public function test_me_includes_is_site_admin(): void
    {
        ['parent' => $admin] = $this->makeFamily('admin');
        $admin->forceFill(['is_site_admin' => true])->save();

        $this->actingAsParent($admin)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.is_site_admin', true);
    }
}
