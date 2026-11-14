<?php

namespace App\Providers;

use Illuminate\Auth\RequestGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Guard;

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
        Auth::extend('dashboard-read-only', function ($app) {
            $guard = new RequestGuard(new Guard($app['auth'], config('sanctum.expiration'), null, false), $app['request']);
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });
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
