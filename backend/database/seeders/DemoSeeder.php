<?php

namespace Database\Seeders;

use App\Modules\Identity\Actions\CreateChild;
use App\Modules\Identity\Actions\CreateFamilyWithParent;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 测试数据（虚构）。仅用于本地开发与演示，生产环境拒绝执行。
 * Passwords and PINs are generated at random and printed once; nothing is hard-coded.
 */
class DemoSeeder extends Seeder
{
    public function run(CreateFamilyWithParent $createFamily, CreateChild $createChild): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder 不能在生产环境运行');
        }

        if (User::query()->where('username', 'demo_parent')->exists()) {
            $this->command?->warn('测试数据已存在（demo_parent），跳过');

            return;
        }

        $password = Str::password(14, symbols: false);
        $pin = (string) random_int(1000, 9999);

        $result = $createFamily->handle('演示家庭（测试数据）', 'Asia/Shanghai', '演示家长', 'demo_parent', $password);

        $createChild->handle($result['family']->id, [
            'nickname' => '小星星（测试）',
            'avatar' => 'astronaut',
            'birth_date' => now('Asia/Shanghai')->subYears(6)->subMonths(5)->toDateString(),
            'grade' => 1,
            'theme_color' => '#5B8DEF',
            'pin' => $pin,
        ], $result['parent']);

        $this->command?->info('已创建测试数据：');
        $this->command?->line("  家长登录名：demo_parent  密码：{$password}");
        $this->command?->line("  孩子：小星星（测试）  PIN：{$pin}");
        $this->command?->warn('  以上为随机生成的测试凭据，只显示这一次。');
    }
}
