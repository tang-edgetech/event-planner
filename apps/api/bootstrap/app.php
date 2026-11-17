<?php

use App\Http\Middleware\EnsureAccountUsable;
use App\Http\Middleware\InternalOnly;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Backoffice routes (api/admin/*) use the "web" group: cookie session + CSRF (see routes/api.php).
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        // There is no sign-in page on the API: signed-out requests get the JSON "not found" answer below.
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->alias([
            'account' => EnsureAccountUsable::class,
            'internal' => InternalOnly::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Signed-out requests look exactly like unknown addresses: plain 404, nothing that hints at a backoffice.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'Not found.', 'reason' => 'unauthenticated'], 401);
        });
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            return response()->json(['message' => $e->getMessage() ?: 'You do not have permission to do this.'], 403);
        });
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Not found.'], 404);
            }
        });
    })->create();
