<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Bootstrap site admins who can issue family invite codes from the parent UI.
 */
class GrantSiteAdminCommand extends Command
{
    protected $signature = 'site-admin:grant {username : Parent username to promote}';

    protected $description = '授予站点管理员（可在家长后台管理家庭邀请码）';

    public function handle(): int
    {
        $username = Str::lower(trim((string) $this->argument('username')));
        $user = User::query()->where('username', $username)->first();

        if ($user === null) {
            $this->error("用户「{$username}」不存在");

            return self::FAILURE;
        }

        if (! $user->isParent()) {
            $this->error('只能将家长账号设为站点管理员');

            return self::FAILURE;
        }

        if ($user->is_site_admin) {
            $this->info("「{$username}」已是站点管理员");

            return self::SUCCESS;
        }

        $user->is_site_admin = true;
        $user->save();

        $this->info("已授予「{$username}」站点管理员");

        return self::SUCCESS;
    }
}
