<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\ApplicantController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\MicrositeController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SetupController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\PublicController;
use Illuminate\Support\Facades\Route;

/*
| Public site endpoints: only the public site's server may call them (X-Internal-Key). Everyone else gets 404.
*/
Route::prefix('public')->middleware(['internal', 'throttle:public'])->group(function () {
    Route::get('site', [PublicController::class, 'site']);
    Route::get('events/{slug}', [PublicController::class, 'event']);
    Route::post('events/{slug}/register', [PublicController::class, 'register'])->middleware('throttle:register');
    Route::get('microsites/{slug}', [PublicController::class, 'microsite']);
});

/*
| Backoffice endpoints: reached through the backoffice's own server (same origin), cookie session + CSRF.
*/
Route::prefix('admin')->middleware('web')->group(function () {
    Route::get('auth/config', [AuthController::class, 'config'])->name('auth.config');
    Route::get('setup/status', [SetupController::class, 'status']);
    Route::post('setup', [SetupController::class, 'store'])->middleware('throttle:5,1');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1')->name('auth.login');
    Route::post('auth/two-factor', [AuthController::class, 'twoFactor'])->middleware('throttle:20,1')->name('auth.two-factor');

    Route::middleware(['auth:sanctum', 'account'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/heartbeat', [AuthController::class, 'heartbeat'])->name('auth.heartbeat');

        Route::put('account/password', [AccountController::class, 'password'])->name('account.password');
        Route::get('account/two-factor', [AccountController::class, 'twoFactorIndex'])->name('account.two-factor.index');
        Route::post('account/two-factor', [AccountController::class, 'twoFactorStore'])->name('account.two-factor.store');
        Route::post('account/two-factor/{device}/confirm', [AccountController::class, 'twoFactorConfirm'])->name('account.two-factor.confirm');
        Route::delete('account/two-factor/{device}', [AccountController::class, 'twoFactorDestroy'])->name('account.two-factor.destroy');

        Route::get('dashboard', [ReportController::class, 'dashboard'])->middleware('can:dashboard.view');

        // Users (own account is always reachable; others need users.view / users.manage, checked in the controller)
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::get('users/{user}', [UserController::class, 'show']);
        Route::put('users/{user}', [UserController::class, 'update']);
        Route::patch('users/{user}/status', [UserController::class, 'status']);
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::put('users/{user}/password', [UserController::class, 'setPassword']);
        Route::post('users/{user}/reset-two-factor', [UserController::class, 'resetTwoFactor']);
        Route::get('users/{user}/two-factor', [UserController::class, 'twoFactorDevices']);
        Route::delete('users/{user}/two-factor/{device}', [UserController::class, 'removeTwoFactorDevice']);
        Route::post('users/{user}/force-logout', [UserController::class, 'forceLogout']);
        Route::delete('users/{user}', [UserController::class, 'destroy']);

        // Events
        Route::middleware('can:events.view')->group(function () {
            Route::get('events', [EventController::class, 'index']);
            Route::get('events/options', [EventController::class, 'options']);
            Route::get('events/builder', [EventController::class, 'builder']);
            Route::get('events/{event}', [EventController::class, 'show']);
        });
        Route::middleware('can:events.manage')->group(function () {
            Route::post('events', [EventController::class, 'store']);
            Route::put('events/{event}', [EventController::class, 'update']);
            Route::post('events/{event}/duplicate', [EventController::class, 'duplicate']);
            Route::delete('events/{event}', [EventController::class, 'destroy']);
        });

        // Applicants
        Route::middleware('can:applicants.view')->group(function () {
            Route::get('applicants', [ApplicantController::class, 'index']);
            Route::get('applicants/{applicant}', [ApplicantController::class, 'show']);
        });
        Route::post('applicants/export', [ApplicantController::class, 'export'])->middleware('can:applicants.export');
        Route::middleware('can:applicants.manage')->group(function () {
            Route::put('applicants/{applicant}', [ApplicantController::class, 'update']);
            Route::patch('applicants/{applicant}/status', [ApplicantController::class, 'status']);
            Route::post('applicants/bulk-status', [ApplicantController::class, 'bulkStatus']);
            Route::post('applicants/{applicant}/resend', [ApplicantController::class, 'resend']);
            Route::delete('applicants/{applicant}', [ApplicantController::class, 'destroy']);
        });

        // Microsites
        Route::middleware('can:microsites.view')->group(function () {
            Route::get('microsites', [MicrositeController::class, 'index']);
            Route::get('microsites/{microsite}', [MicrositeController::class, 'show']);
        });
        Route::middleware('can:microsites.manage')->group(function () {
            Route::post('microsites', [MicrositeController::class, 'store']);
            Route::put('microsites/{microsite}', [MicrositeController::class, 'update']);
            Route::post('microsites/{microsite}/preview', [MicrositeController::class, 'preview']);
            Route::post('microsites/{microsite}/duplicate', [MicrositeController::class, 'duplicate']);
            Route::delete('microsites/{microsite}', [MicrositeController::class, 'destroy']);
        });

        // Media library (event banners and microsite images)
        Route::middleware('can:media.manage')->group(function () {
            Route::get('media', [MediaController::class, 'index']);
            Route::post('media', [MediaController::class, 'store']);
            Route::delete('media/{media}', [MediaController::class, 'destroy']);
        });

        // Reports
        Route::middleware('can:reports.view')->prefix('reports')->group(function () {
            Route::get('overview', [ReportController::class, 'overview']);
            Route::get('events', [ReportController::class, 'events']);
            Route::get('sources', [ReportController::class, 'sources']);
        });

        // Audit logs
        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('can:audit.view');
        Route::post('audit-logs/export', [AuditLogController::class, 'export'])->middleware('can:audit.export');

        // Settings
        Route::middleware('can:settings.manage')->group(function () {
            Route::post('settings/email/test', [SettingsController::class, 'testEmail']);
            Route::get('settings/{group}', [SettingsController::class, 'show']);
            Route::put('settings/{group}', [SettingsController::class, 'update']);
        });
    });
});
