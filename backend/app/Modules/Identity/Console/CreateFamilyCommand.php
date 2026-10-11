<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Actions\CreateFamilyWithParent;
use App\Modules\Identity\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates a family and its first parent. The password is only ever typed interactively;
 * there is no default account in the codebase or images.
 */
class CreateFamilyCommand extends Command
{
    protected $signature = 'family:create';

    protected $description = '创建家庭与首位家长账号（交互式）';

    public function handle(CreateFamilyWithParent $action): int
    {
        $familyName = $this->askValid('家庭名称', 'required|string|max:64', '我的家');
        $timezone = $this->askValid('时区', ['required', 'timezone:all'], 'Asia/Shanghai');
        $displayName = $this->askValid('家长称呼（如：妈妈、爸爸）', 'required|string|max:32', '家长');
        $username = Str::lower($this->askValid('登录用户名（字母、数字、下划线）', ['required', 'regex:/^[A-Za-z0-9_]{3,32}$/']));

        if (User::query()->where('username', $username)->exists()) {
            $this->error('该用户名已存在');

            return self::FAILURE;
        }

        $password = (string) $this->secret('登录密码（至少 10 位）');
        $confirm = (string) $this->secret('再次输入密码');

        if ($password !== $confirm) {
            $this->error('两次输入的密码不一致');

            return self::FAILURE;
        }

        if (Str::length($password) < 10) {
            $this->error('密码至少需要 10 位');

            return self::FAILURE;
        }

        $result = $action->handle($familyName, $timezone, $displayName, $username, $password);

        $this->info("已创建家庭「{$result['family']->name}」（时区 {$timezone}），家长登录名：{$username}");

        return self::SUCCESS;
    }

    /**
     * @param  string|list<mixed>  $rules
     */
    private function askValid(string $question, string|array $rules, ?string $default = null): string
    {
        while (true) {
            $value = trim((string) $this->ask($question, $default));
            $validator = Validator::make(['value' => $value], ['value' => $rules]);
            if ($validator->passes()) {
                return $value;
            }
            if (! $this->input->isInteractive()) {
                throw new InvalidArgumentException("{$question}：".$validator->errors()->first('value'));
            }
            $this->error($validator->errors()->first('value'));
        }
    }
}
