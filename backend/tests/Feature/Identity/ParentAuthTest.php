<?php

namespace Tests\Feature\Identity;

use App\Modules\Core\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ParentAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_can_log_in_and_see_profile(): void
    {
        ['family' => $family] = $this->makeFamily();

        $this->postJson('/api/v1/auth/login', ['login' => 'MAMA', 'password' => self::PARENT_PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'parent')
            ->assertJsonPath('data.family.id', $family->id)
            ->assertJsonPath('data.family.timezone', 'Asia/Shanghai')
            ->assertJsonPath('data.child', null);

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.display_name', '妈妈');
        $this->assertTrue(AuditLog::where('action', 'auth.login')->exists());
    }

    public function test_wrong_password_and_unknown_user_get_the_same_error(): void
    {
        $this->makeFamily();

        $wrong = $this->postJson('/api/v1/auth/login', ['login' => 'mama', 'password' => 'nope-nope-nope'])
            ->assertStatus(422)->json('error');
        $unknown = $this->postJson('/api/v1/auth/login', ['login' => 'ghost', 'password' => 'nope-nope-nope'])
            ->assertStatus(422)->json('error');

        $this->assertSame($wrong, $unknown);
        $this->assertGuest('web');
    }

    public function test_login_is_throttled_after_five_failures(): void
    {
        $this->makeFamily();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['login' => 'mama', 'password' => 'wrong-'.$i])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', ['login' => 'mama', 'password' => self::PARENT_PASSWORD])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS');
    }

    public function test_account_lock_escalates_and_survives_ip_throttle_reset(): void
    {
        ['parent' => $parent] = $this->makeFamily();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['login' => 'mama', 'password' => 'wrong-'.$i]);
        }
        RateLimiter::clear('parent-login:mama|127.0.0.1');

        $this->assertNotNull($parent->refresh()->locked_until);
        $this->postJson('/api/v1/auth/login', ['login' => 'mama', 'password' => self::PARENT_PASSWORD])->assertStatus(429);

        $this->travel(2)->minutes();
        $this->postJson('/api/v1/auth/login', ['login' => 'mama', 'password' => self::PARENT_PASSWORD])->assertOk();
        $this->assertSame(0, $parent->refresh()->failed_login_count);
    }

    public function test_child_account_cannot_use_password_login(): void
    {
        ['family' => $family] = $this->makeFamily();
        $this->makeChild($family);

        // Children have no username/password at all; any attempt is just invalid credentials.
        $this->postJson('/api/v1/auth/login', ['login' => '小星星', 'password' => '1234'])->assertStatus(422);
    }

    public function test_logout_ends_session(): void
    {
        ['parent' => $parent] = $this->makeFamily();
        $this->actingAsParent($parent);

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertGuest('web');
    }

    public function test_guest_gets_401_json(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
