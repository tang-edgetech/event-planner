<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\PasswordRules;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * First-run setup: creates the first (visible) Super Admin. Closed for good once one exists.
 */
class SetupController extends Controller
{
    public static function needed(): bool
    {
        return ! User::query()->where('is_hidden', false)->exists();
    }

    public function status(): JsonResponse
    {
        return response()->json(['needed' => self::needed()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(self::needed(), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-zA-Z0-9._-]+$/', 'unique:users,username'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRules::make()],
            'site_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = DB::transaction(function () use ($data) {
            abort_unless(self::needed(), 404);

            return User::query()->create([
                'name' => $data['name'],
                'username' => strtolower($data['username']),
                'email' => strtolower($data['email']),
                'password' => $data['password'],
                'role' => 'super_admin',
                'status' => User::STATUS_ACTIVE,
                'password_changed_at' => now(),
            ]);
        });

        if (! empty($data['site_name'])) {
            Settings::put('general', ['site_name' => $data['site_name']]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('last_seen', time());

        Audit::log('user.setup', "First administrator {$user->name} was created", "The system was set up for the first time. {$user->name} ({$user->username}) was created as the first Super Admin and signed in.", 'success', $user, [], $user);

        return response()->json(['ok' => true]);
    }
}
