<?php

use App\Modules\Core\Console\PreflightCommand;
use App\Modules\Core\Http\ApiExceptionRenderer;
use App\Modules\Core\Http\Middleware\AssignRequestId;
use App\Modules\Core\Http\Middleware\RequireIdempotencyKey;
use App\Modules\Encyclopedia\Console\PrepareEncyclopediaZhCommand;
use App\Modules\English\Console\DiagnoseEnglishAiCommand;
use App\Modules\English\Console\PrepareEnglishDayCommand;
use App\Modules\English\Console\PruneEnglishSpeakAttemptsCommand;
use App\Modules\Identity\Console\CreateFamilyCommand;
use App\Modules\Ledger\Console\GrantDailyPointsCommand;
use App\Modules\Identity\Http\Middleware\EnsureRole;
use App\Modules\Identity\Http\Middleware\RequireSudo;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->throttleApi();
        // Only nginx on the compose network talks to php-fpm; trust X-Forwarded-* from it.
        $middleware->trustProxies(at: '*');
        $middleware->prepend(AssignRequestId::class);
        $middleware->alias([
            'role' => EnsureRole::class,
            'sudo' => RequireSudo::class,
            'idempotent' => RequireIdempotencyKey::class,
        ]);
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withCommands([
        CreateFamilyCommand::class,
        PreflightCommand::class,
        PruneEnglishSpeakAttemptsCommand::class,
        DiagnoseEnglishAiCommand::class,
        PrepareEnglishDayCommand::class,
        PrepareEncyclopediaZhCommand::class,
        GrantDailyPointsCommand::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionRenderer::register($exceptions);
    })->create();
