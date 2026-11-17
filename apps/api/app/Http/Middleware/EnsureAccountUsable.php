<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Audit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every signed-in backoffice request:
 * - signs out inactive (disabled) accounts;
 * - signs out after the idle time from Settings → Security (default 2 hours);
 * - until the password is changed or 2FA is set up (when required), allows only the account screens.
 */
class EnsureAccountUsable
{
    /** Routes allowed while the account still has to change its password or set up 2FA. */
    private const SETUP_ROUTES = ['auth.me', 'auth.logout', 'auth.heartbeat', 'account.password', 'account.two-factor.index', 'account.two-factor.store', 'account.two-factor.confirm', 'account.two-factor.destroy'];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (! $user->isActive()) {
            $this->signOut($request);

            return response()->json(['message' => 'Your account is inactive.', 'reason' => 'inactive'], 401);
        }

        $idle = max(5, (int) setting('security', 'idle_minutes', 120)) * 60;
        $lastSeen = (int) $request->session()->get('last_seen', time());
        if (time() - $lastSeen > $idle) {
            Audit::log('auth.idle_logout', "{$user->name} was signed out after being inactive", "{$user->name} did nothing in the backoffice for ".intdiv($idle, 60).' minutes, so the system signed them out automatically to protect the account.', 'success', $user, [], $user);
            $this->signOut($request);

            return response()->json(['message' => 'You were signed out after a period of inactivity.', 'reason' => 'idle'], 401);
        }
        if (! $request->headers->has('X-Background')) {
            $request->session()->put('last_seen', time());
        }

        $route = (string) $request->route()?->getName();
        if (! in_array($route, self::SETUP_ROUTES, true)) {
            if ($user->must_change_password) {
                return response()->json(['message' => 'Please change your password first.', 'reason' => 'password_change'], 423);
            }
            if (setting('security', 'require_2fa', false) && ! $user->hasTwoFactor()) {
                return response()->json(['message' => 'Please set up two-factor authentication first.', 'reason' => 'two_factor_setup'], 423);
            }
        }

        return $next($request);
    }

    private function signOut(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
