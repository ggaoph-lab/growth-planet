<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Enums\ChildStatus;
use App\Modules\Identity\Models\TrustedDevice;
use App\Modules\Identity\Services\DeviceTokens;
use App\Modules\Identity\Services\PinHasher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KidLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_can_pair_another_browser_with_a_single_use_code(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        $this->actingAsParent($parent)
            ->postJson('/api/v1/parent/devices/pairing-code')
            ->assertStatus(423);

        $issued = $this->actingAsParent($parent, sudo: true)
            ->postJson('/api/v1/parent/devices/pairing-code')
            ->assertCreated();
        $code = $issued->json('data.code');
        $this->assertMatchesRegularExpression('/^\d{8}$/', $code);
        $this->assertDatabaseHas('device_pairing_codes', ['family_id' => $family->id, 'code_hash' => hash_hmac('sha256', $code, config('app.key'))]);
        $this->assertDatabaseMissing('device_pairing_codes', ['code_hash' => $code]);

        // Redeem as a guest in a different browser, never with the parent's session.
        Auth::guard('web')->logout();
        $response = $this->postJson('/api/v1/auth/device/pair', ['code' => $code, 'name' => 'iPad Chrome'])
            ->assertOk()
            ->assertJsonPath('data.authorized', true);
        $cookie = $response->getCookie(DeviceTokens::COOKIE, false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $response->assertJsonMissingPath('data.code');

        $token = decrypt($cookie->getValue(), false);
        $token = str_contains($token, '|') ? explode('|', $token, 2)[1] : $token;
        $this->postJson('/api/v1/auth/device/pair', ['code' => $code, 'name' => 'second browser'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_PAIRING_CODE');

        $this->withCookie(DeviceTokens::COOKIE, $token)
            ->postJson('/api/v1/auth/kid/login', ['child_id' => $child->id, 'pin' => '1234'])
            ->assertOk();
    }

    public function test_new_pairing_code_invalidates_old_code_and_expires(): void
    {
        ['parent' => $parent] = $this->makeFamily();
        $this->actingAsParent($parent, sudo: true);
        $old = $this->postJson('/api/v1/parent/devices/pairing-code')->assertCreated()->json('data.code');
        $new = $this->postJson('/api/v1/parent/devices/pairing-code')->assertCreated()->json('data.code');
        $this->assertNotSame($old, $new);

        Auth::guard('web')->logout();
        $this->postJson('/api/v1/auth/device/pair', ['code' => $old, 'name' => 'old'])
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_PAIRING_CODE');
        $this->travelTo(CarbonImmutable::now()->addMinutes(11));
        $this->postJson('/api/v1/auth/device/pair', ['code' => $new, 'name' => 'expired'])
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_PAIRING_CODE');
        $this->assertSame(0, TrustedDevice::query()->count());
    }

    public function test_parent_authorizes_device_and_token_is_only_in_httponly_cookie(): void
    {
        ['parent' => $parent] = $this->makeFamily();
        $this->actingAsParent($parent);

        $this->postJson('/api/v1/parent/devices', ['name' => '客厅 iPad'])->assertStatus(423);

        $this->actingAsParent($parent, sudo: true);
        $response = $this->postJson('/api/v1/parent/devices', ['name' => '客厅 iPad'])->assertCreated();

        $cookie = $response->getCookie(DeviceTokens::COOKIE, false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertStringNotContainsString('token', $response->getContent());

        $token = decrypt($cookie->getValue(), false);
        $token = str_contains($token, '|') ? explode('|', $token, 2)[1] : $token;
        $this->assertSame(DeviceTokens::hash($token), TrustedDevice::sole()->token_hash);
    }

    public function test_kid_profiles_and_login_require_an_authorized_device(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);

        $this->getJson('/api/v1/auth/kid/profiles')->assertStatus(401)->assertJsonPath('error.code', 'DEVICE_NOT_AUTHORIZED');
        $this->postJson('/api/v1/auth/kid/login', ['child_id' => $child->id, 'pin' => '1234'])->assertStatus(401);
    }

    public function test_kid_logs_in_with_pin_on_authorized_device(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [, $token] = $this->makeDevice($family, $parent);

        $this->withCookie(DeviceTokens::COOKIE, $token)
            ->getJson('/api/v1/auth/kid/profiles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nickname', '小星星')
            ->assertJsonPath('data.0.pin_required', true)
            ->assertJsonPath('data.0.pin_length', 4)
            ->assertJsonMissingPath('data.0.birth_date');

        $this->withCookie(DeviceTokens::COOKIE, $token)
            ->postJson('/api/v1/auth/kid/login', ['child_id' => $child->id, 'pin' => '0000'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_PIN');

        $this->withCookie(DeviceTokens::COOKIE, $token)
            ->postJson('/api/v1/auth/kid/login', ['child_id' => $child->id, 'pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'child')
            ->assertJsonPath('data.child.nickname', '小星星')
            ->assertJsonMissingPath('data.child.birth_date');

        $this->assertTrue(str_starts_with((string) $child->member->user->fresh()->pin_hash, 'hmac:'));

        $this->withCookie(DeviceTokens::COOKIE, $token)->getJson('/api/v1/kid/home')->assertOk();
    }

    public function test_legacy_bcrypt_pin_is_accepted_and_upgraded(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $user = $child->member->user;
        $user->forceFill(['pin_hash' => Hash::make('1234')])->save();
        [, $token] = $this->makeDevice($family, $parent);

        $this->withCookie(DeviceTokens::COOKIE, $token)
            ->postJson('/api/v1/auth/kid/login', ['child_id' => $child->id, 'pin' => '1234'])
            ->assertOk();

        $upgraded = (string) $user->fresh()->pin_hash;
        $this->assertTrue(str_starts_with($upgraded, 'hmac:'));
        $this->assertTrue(PinHasher::check('1234', $upgraded));
    }

    public function test_pin_is_throttled_per_device_and_child(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [, $token] = $this->makeDevice($family, $parent);

        for ($i = 0; $i < 5; $i++) {
            $this->withCookie(DeviceTokens::COOKIE, $token)
                ->postJson('/api/v1/auth/kid/login', ['child_id' => $child->id, 'pin' => '000'.$i])
                ->assertStatus(422);
        }

        $this->withCookie(DeviceTokens::COOKIE, $token)
            ->postJson('/api/v1/auth/kid/login', ['child_id' => $child->id, 'pin' => '1234'])
            ->assertStatus(429);
    }

    public function test_child_without_pin_logs_in_with_device_only(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family, ['pin' => null]);
        [, $token] = $this->makeDevice($family, $parent);

        $this->withCookie(DeviceTokens::COOKIE, $token)
            ->postJson('/api/v1/auth/kid/login', ['child_id' => $child->id])
            ->assertOk();
    }

    public function test_device_cannot_log_in_child_from_another_family(): void
    {
        ['family' => $familyA, 'parent' => $parentA] = $this->makeFamily('mama_a');
        ['family' => $familyB] = $this->makeFamily('mama_b');
        $otherChild = $this->makeChild($familyB);
        [, $token] = $this->makeDevice($familyA, $parentA);

        $this->withCookie(DeviceTokens::COOKIE, $token)
            ->postJson('/api/v1/auth/kid/login', ['child_id' => $otherChild->id, 'pin' => '1234'])
            ->assertNotFound();
    }

    public function test_parent_can_rename_active_device(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        [$device] = $this->makeDevice($family, $parent, '旧名称');

        $this->actingAsParent($parent)
            ->patchJson("/api/v1/parent/devices/{$device->id}", ['name' => '客厅平板'])
            ->assertOk()
            ->assertJsonPath('data.name', '客厅平板');

        $this->assertSame('客厅平板', $device->refresh()->name);
    }

    public function test_parent_cannot_rename_revoked_device(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        [$device] = $this->makeDevice($family, $parent);
        $device->forceFill(['revoked_at' => now()])->save();

        $this->actingAsParent($parent)
            ->patchJson("/api/v1/parent/devices/{$device->id}", ['name' => '新名字'])
            ->assertNotFound();
    }

    public function test_parent_can_revoke_multiple_devices_without_sudo(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        [$first] = $this->makeDevice($family, $parent, '设备 A');
        [$second] = $this->makeDevice($family, $parent, '设备 B');

        $this->actingAsParent($parent);
        $this->deleteJson("/api/v1/parent/devices/{$first->id}")->assertOk();
        $this->deleteJson("/api/v1/parent/devices/{$second->id}")->assertOk();

        $this->assertNotNull($first->refresh()->revoked_at);
        $this->assertNotNull($second->refresh()->revoked_at);
    }

    public function test_revoking_device_ends_kid_session_immediately(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $this->actingAsKid($child, $device, $token)->getJson('/api/v1/kid/home')->assertOk();

        $device->forceFill(['revoked_at' => now()])->save();

        $this->actingAsKid($child, $device, $token)->getJson('/api/v1/kid/home')->assertUnauthorized();
    }

    public function test_kid_session_without_device_cookie_is_rejected(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device] = $this->makeDevice($family, $parent);

        $this->actingAs($child->member->user, 'web')
            ->withSession(['kid_device_id' => $device->id])
            ->getJson('/api/v1/kid/home')
            ->assertUnauthorized();
    }

    public function test_paused_child_cannot_log_in_or_keep_session(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $child->status = ChildStatus::Paused;
        $child->save();

        $this->withCookie(DeviceTokens::COOKIE, $token)
            ->postJson('/api/v1/auth/kid/login', ['child_id' => $child->id, 'pin' => '1234'])
            ->assertNotFound();
        $this->actingAsKid($child, $device, $token)->getJson('/api/v1/kid/home')->assertUnauthorized();
    }

    public function test_kid_home_lists_all_planets_without_fake_data(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $planets = $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/home')
            ->assertOk()
            ->assertJsonPath('data.profile.nickname', '小星星')
            ->assertJsonMissingPath('data.profile.birth_date')
            ->json('data.planets');

        $this->assertCount(15, $planets);
        $available = [
            'task_planet', 'wallet', 'shop',
            'bag_planet', 'schedule_planet', 'explore_planet', 'food_diary',
            'movie_planet', 'english_planet', 'english_beta', 'ielts_planet', 'encyclopedia_planet', 'poetry_planet', 'game_planet', 'collection',
        ];
        foreach ($planets as $planet) {
            if (in_array($planet['key'], $available, true)) {
                $this->assertTrue($planet['available'], "{$planet['key']} should be available");
            } else {
                $this->assertFalse($planet['available'], "{$planet['key']} must not be shown as available before its phase");
            }
        }
    }

    public function test_kid_can_toggle_own_animation_and_sound(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $this->actingAsKid($child, $device, $token)
            ->patchJson('/api/v1/kid/preferences', ['animations' => false])
            ->assertOk()
            ->assertExactJson(['data' => ['animations' => false, 'sounds' => true]]);
    }
}
