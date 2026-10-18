<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Configuration is available here (not yet during console middleware setup).
        TrustProxies::at(config('signing.trusted_proxies', []));
        foreach (['signing-public' => 60, 'signing-otp' => 5, 'signing-verify' => 15] as $name => $max) {
            RateLimiter::for($name, fn (Request $request) => [
                Limit::perMinute($max)->by($name.'|'.$request->ip()),
                Limit::perMinute($max)->by($name.'|'.hash('sha256', (string) ($request->route('token') ?? $request->user()?->id))),
            ]);
        }
    }
}
