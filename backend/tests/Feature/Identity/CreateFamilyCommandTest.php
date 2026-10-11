<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateFamilyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_family_and_parent_interactively(): void
    {
        $this->artisan('family:create')
            ->expectsQuestion('家庭名称', '星星之家')
            ->expectsQuestion('时区', 'Asia/Shanghai')
            ->expectsQuestion('家长称呼（如：妈妈、爸爸）', '爸爸')
            ->expectsQuestion('登录用户名（字母、数字、下划线）', 'Baba_01')
            ->expectsQuestion('登录密码（至少 10 位）', 'a-long-password')
            ->expectsQuestion('再次输入密码', 'a-long-password')
            ->assertSuccessful();

        $user = User::where('username', 'baba_01')->sole();
        $this->assertTrue(Hash::check('a-long-password', $user->password));
        $this->assertTrue($user->isParent());
        $this->assertSame('星星之家', $user->member->family->name);
    }

    public function test_rejects_short_password(): void
    {
        $this->artisan('family:create')
            ->expectsQuestion('家庭名称', '星星之家')
            ->expectsQuestion('时区', 'Asia/Shanghai')
            ->expectsQuestion('家长称呼（如：妈妈、爸爸）', '爸爸')
            ->expectsQuestion('登录用户名（字母、数字、下划线）', 'baba')
            ->expectsQuestion('登录密码（至少 10 位）', 'short')
            ->expectsQuestion('再次输入密码', 'short')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_demo_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectExceptionMessage('DemoSeeder 不能在生产环境运行');
        $this->app->call([new DemoSeeder, 'run']);
    }
}
