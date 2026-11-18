<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Every permission becomes a gate, used as `can:events.manage` on routes. Roles decide who has which.
        foreach (Roles::permissions() as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
        Gate::define('media.manage', fn (User $user) => $user->hasPermission('events.manage') || $user->hasPermission('microsites.manage'));

        // Session lifetime follows Settings → Security → idle minutes.
        config(['session.lifetime' => max(5, (int) setting('security', 'idle_minutes', 120))]);

        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(300)->by((string) $request->header('X-Client-Ip', $request->ip())));
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(10)->by((string) $request->header('X-Client-Ip', $request->ip())));
    }
}
