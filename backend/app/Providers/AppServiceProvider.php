<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    /**
     * Named rate limiters — see DECISIONS.md "Redis usage". Backed by
     * CACHE_STORE=redis; a Redis outage is handled by FailOpenThrottle,
     * not here.
     */
    public function boot(): void
    {
        // Laravel no longer auto-registers this default (the RouteServiceProvider
        // boilerplate that used to define it was removed in Laravel 11), but
        // $middleware->throttleApi() in bootstrap/app.php still applies it to
        // every /api/* route as the general safety net from DECISIONS.md #8.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by('login:'.strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('interactions', function (Request $request) {
            return Limit::perMinute(30)->by('interactions:'.$request->user()?->id);
        });
    }
}
