<?php

namespace App\Providers;

use App\Services\DanaPaymentGateway;
use App\Services\PaymentGatewayInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, DanaPaymentGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('merchant-api', function (Request $request): Limit {
            return Limit::perMinute(60)->by(hash('sha256', (string) $request->bearerToken()));
        });

        RateLimiter::for('internal-service', function (Request $request): Limit {
            return Limit::perMinute(20)->by('internal-service:'.$request->ip());
        });

        RateLimiter::for('internal-merchant-management', function (Request $request): Limit {
            return Limit::perMinute(10)->by('internal-merchant-management:'.$request->ip());
        });
    }
}
