<?php

namespace App\Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Production readiness checks. Safe to run repeatedly; does not mutate data.
 */
class PreflightCommand extends Command
{
    protected $signature = 'app:preflight {--quick : Only verify APP_KEY and database connectivity}';

    protected $description = '检查生产环境关键配置与数据库连通性';

    public function handle(): int
    {
        $failed = 0;

        $failed += $this->check('APP_KEY is set', fn () => filled(config('app.key')), 'Set APP_KEY in deploy/compose/.env.prod');

        if ($this->option('quick')) {
            $failed += $this->check('Database accepts connections', function () {
                DB::select('SELECT 1');

                return true;
            }, 'Check MYSQL_* credentials and that the mysql service is healthy');

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        $failed += $this->check(
            'APP_ENV is production',
            fn () => config('app.env') === 'production',
            'Set APP_ENV=production'
        );

        $failed += $this->check(
            'APP_DEBUG is false',
            fn () => config('app.debug') === false,
            'Set APP_DEBUG=false'
        );

        $failed += $this->check(
            'Database password is not empty',
            fn () => filled(config('database.connections.mysql.password')),
            'Set MYSQL_PASSWORD to a strong random value'
        );

        $appUrl = (string) config('app.url');
        $secureCookie = (bool) config('session.secure');
        if (str_starts_with($appUrl, 'https://')) {
            $failed += $this->check(
                'SESSION_SECURE_COOKIE is true for HTTPS APP_URL',
                fn () => $secureCookie === true,
                'Set SESSION_SECURE_COOKIE=true'
            );
        } else {
            $this->warn('APP_URL is not HTTPS. Prefer TLS in production (PWA / Secure cookies).');
        }

        $failed += $this->check('Database accepts connections', function () {
            DB::select('SELECT 1');

            return true;
        }, 'Check MYSQL_* credentials and that the mysql service is healthy');

        $failed += $this->check('Migrations table is present', function () {
            return DB::getSchemaBuilder()->hasTable('migrations');
        }, 'Run: make prod-migrate');

        if ($failed === 0) {
            $this->info('Preflight passed.');

            return self::SUCCESS;
        }

        $this->error("Preflight failed with {$failed} issue(s).");

        return self::FAILURE;
    }

    /**
     * @param  callable(): bool  $assertion
     */
    private function check(string $label, callable $assertion, string $hint): int
    {
        try {
            if ($assertion()) {
                $this->line("<info>PASS</info>  {$label}");

                return 0;
            }
        } catch (Throwable $e) {
            $this->line("<error>FAIL</error>  {$label}: {$e->getMessage()}");
            $this->line("       hint: {$hint}");

            return 1;
        }

        $this->line("<error>FAIL</error>  {$label}");
        $this->line("       hint: {$hint}");

        return 1;
    }
}
