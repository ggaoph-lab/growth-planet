<?php

namespace App\Providers;

use App\Modules\Approval\Services\ApprovalHandlerRegistry;
use App\Modules\Core\Support\Clock;
use App\Modules\English\Services\EnglishLogApprovalHandler;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Shop\Services\RedemptionApprovalHandler;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class);
        $this->app->singleton(ApprovalHandlerRegistry::class);
        $this->app->scoped(CurrentContext::class);
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);
        Model::shouldBeStrict(! $this->app->isProduction());

        $registry = $this->app->make(ApprovalHandlerRegistry::class);
        $registry->register($this->app->make(RedemptionApprovalHandler::class));
        $registry->register($this->app->make(EnglishLogApprovalHandler::class));

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ? 'u:'.$request->user()->id : 'ip:'.$request->ip());
        });
    }
}
