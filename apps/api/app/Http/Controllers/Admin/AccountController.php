<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TwoFactorDevice;
use App\Models\User;
use App\Support\Audit;
use App\Support\PasswordRules;
use App\Support\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in person's own password and authenticator devices.
 */
class AccountController extends Controller
{
    public function password(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', PasswordRules::make()],
        ], [
            'password.different' => 'The new password must be different from the current one.',
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            Audit::error('auth.password_change_failed', "{$user->name} entered a wrong current password", "{$user->name} tried to change their password but the current password they typed was wrong, so nothing changed.", $user);

            throw ValidationException::withMessages(['current_password' => 'The current password is not correct.']);
        }

        $user->forceFill([
            'password' => $data['password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();
        $ended = $user->endAllSessions($request->session()->getId());

        Audit::log('auth.password_changed', "{$user->name} changed their password", "{$user->name} changed their own password.".($ended ? " Their {$ended} other signed-in session(s) were signed out for safety." : ''), 'success', $user);

        return response()->json(['ok' => true]);
    }

    public function twoFactorIndex(Request $request): JsonResponse
    {
        return response()->json(['devices' => $this->devices($request->user())]);
    }

    /** Start adding an authenticator device: returns the secret and QR code to scan. */
    public function twoFactorStore(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $user->twoFactorDevices()->whereNull('confirmed_at')->delete();
        if ($user->twoFactorDevices()->count() >= User::MAX_TWO_FACTOR_DEVICES) {
            throw ValidationException::withMessages(['name' => 'You can have up to '.User::MAX_TWO_FACTOR_DEVICES.' devices. Remove one first.']);
        }

        $secret = TwoFactor::newSecret();
        $device = $user->twoFactorDevices()->create(['name' => $data['name'], 'secret' => $secret]);

        return response()->json([
            'device_id' => $device->id,
            'secret' => $secret,
            'qr_svg' => TwoFactor::qrSvg($user, $secret),
        ]);
    }

    public function twoFactorConfirm(Request $request, TwoFactorDevice $device): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($device->user_id === $user->id, 404);
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        if (! TwoFactor::verifyDevice($device, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is not correct. Make sure the time on your phone is set automatically.']);
        }

        $device->forceFill(['confirmed_at' => now(), 'last_used_at' => now()])->save();
        Audit::log('auth.two_factor_added', "{$user->name} added a 2FA device \"{$device->name}\"", "{$user->name} linked the authenticator app on \"{$device->name}\". From now on, signing in also needs a code from that app.", 'success', $user);

        return response()->json(['devices' => $this->devices($user)]);
    }

    public function twoFactorDestroy(Request $request, TwoFactorDevice $device): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($device->user_id === $user->id, 404);
        $device->delete();
        if ($device->confirmed_at) {
            Audit::log('auth.two_factor_removed', "{$user->name} removed the 2FA device \"{$device->name}\"", "{$user->name} removed the authenticator app on \"{$device->name}\" from their account.", 'success', $user);
        }

        return response()->json(['devices' => $this->devices($user)]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function devices(User $user): array
    {
        return $user->twoFactorDevices()->whereNotNull('confirmed_at')->orderBy('id')->get()
            ->map(fn (TwoFactorDevice $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'confirmed_at' => $d->confirmed_at?->toIso8601String(),
                'last_used_at' => $d->last_used_at?->toIso8601String(),
            ])->all();
    }
}
