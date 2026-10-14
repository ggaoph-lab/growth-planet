<?php

namespace Tests;

use App\Modules\Identity\Actions\CreateChild;
use App\Modules\Identity\Actions\CreateFamilyWithParent;
use App\Modules\Identity\Http\Middleware\EnsureRole;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\Family;
use App\Modules\Identity\Models\TrustedDevice;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\DeviceTokens;
use App\Modules\Identity\Services\Sudo;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\GrowthFixtures;

abstract class TestCase extends BaseTestCase
{
    use GrowthFixtures;

    protected const PARENT_PASSWORD = 'correct-horse-battery';

    /**
     * Docker Compose injects DB_DATABASE=growth_planet into the process environment.
     * PHPUnit's phpunit.xml force attribute does not reliably override that for Laravel's env(),
     * so RefreshDatabase was wiping the development database. Pin the test DB before boot.
     */
    public function createApplication()
    {
        foreach (['DB_DATABASE' => 'growth_planet_test', 'APP_ENV' => 'testing'] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum only treats requests from configured SPA origins as cookie-authenticated.
        $this->withHeaders(['Referer' => 'http://localhost/', 'Accept' => 'application/json']);
        // Browsers send cookies with same-origin fetch; Laravel's JSON test helpers only do so when asked.
        $this->withCredentials();
    }

    /**
     * @return array{family: Family, parent: User}
     */
    protected function makeFamily(string $username = 'mama', string $timezone = 'Asia/Shanghai'): array
    {
        return app(CreateFamilyWithParent::class)->handle('测试家庭', $timezone, '妈妈', $username, self::PARENT_PASSWORD);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeChild(Family $family, array $overrides = []): ChildProfile
    {
        return app(CreateChild::class)->handle($family->id, array_merge([
            'nickname' => '小星星',
            'avatar' => 'astronaut',
            'birth_date' => '2019-09-01',
            'grade' => 1,
            'theme_color' => '#5B8DEF',
            'pin' => '1234',
        ], $overrides))->load(['family', 'member.user']);
    }

    /** Marks today's fairy tale heard so reviews / probes may enter the learning queue. */
    protected function markEnglishFairyHeard(ChildProfile $child, string $date): void
    {
        $exists = DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where('business_date', $date)
            ->where('item_key', 'fairy-tale:heard')
            ->exists();
        if ($exists) {
            return;
        }
        DB::table('english_learning_answers')->insert([
            'child_id' => $child->id,
            'item_key' => 'fairy-tale:heard',
            'business_date' => $date,
            'correct' => true,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array{0: TrustedDevice, 1: string}
     */
    protected function makeDevice(Family $family, User $parent, string $name = '客厅 iPad'): array
    {
        return app(DeviceTokens::class)->issue($family->id, $name, $parent);
    }

    protected function actingAsParent(User $parent, bool $sudo = false): static
    {
        Auth::guard('web')->logout();
        $this->actingAs($parent, 'web');
        if ($sudo) {
            $this->withSession([Sudo::SESSION_KEY => now()->addMinutes(10)->getTimestamp()]);
        }

        return $this;
    }

    /** A child logged in on an authorised device, as the real kid login flow would leave it. */
    protected function actingAsKid(ChildProfile $child, TrustedDevice $device, string $token): static
    {
        $child->loadMissing('member.user');

        return $this->actingAs($child->member->user, 'web')
            ->withCookie(DeviceTokens::COOKIE, $token)
            ->withSession([EnsureRole::SESSION_DEVICE_KEY => $device->id]);
    }

    /** Use after kid HTTP calls so the parent session is re-established via the real login flow. */
    protected function loginParent(User $parent): static
    {
        $this->postJson('/api/v1/auth/logout');
        $this->postJson('/api/v1/auth/login', [
            'login' => $parent->username,
            'password' => self::PARENT_PASSWORD,
        ])->assertOk();

        return $this;
    }

    /** Use after parent HTTP calls so the kid session is established via the real login flow. */
    protected function loginKid(ChildProfile $child, TrustedDevice $device, string $token, ?string $pin = '1234'): static
    {
        $child->loadMissing('member.user');
        Auth::guard('web')->logout();
        $this->postJson('/api/v1/auth/logout');

        $payload = ['child_id' => $child->id];
        if ($pin !== null && $child->member->user->pin_hash !== null) {
            $payload['pin'] = $pin;
        }

        $this->withCookie(DeviceTokens::COOKIE, $token)
            ->postJson('/api/v1/auth/kid/login', $payload)
            ->assertOk();

        return $this;
    }
}
