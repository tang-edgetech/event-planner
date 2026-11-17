<?php

namespace App\Support;

use App\Models\TwoFactorDevice;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * Time-based one-time codes (authenticator apps such as Google Authenticator or Microsoft Authenticator).
 */
class TwoFactor
{
    public static function engine(): Google2FA
    {
        $engine = new Google2FA;
        $engine->setWindow(1); // accept the previous and next 30-second code too (clock drift)

        return $engine;
    }

    public static function newSecret(): string
    {
        return self::engine()->generateSecretKey(32);
    }

    public static function qrSvg(User $user, string $secret): string
    {
        $issuer = (string) setting('general', 'site_name', 'Event Plus');
        $uri = self::engine()->getQRCodeUrl($issuer, $user->username, $secret);
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd));

        return $writer->writeString($uri);
    }

    /**
     * Check a code against the user's confirmed devices. Returns the matching device.
     */
    public static function verifyUser(User $user, string $code): ?TwoFactorDevice
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return null;
        }
        foreach ($user->twoFactorDevices()->whereNotNull('confirmed_at')->get() as $device) {
            if (self::engine()->verifyKey($device->secret, $code)) {
                $device->forceFill(['last_used_at' => now()])->save();

                return $device;
            }
        }

        return null;
    }

    public static function verifyDevice(TwoFactorDevice $device, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        return strlen($code) === 6 && self::engine()->verifyKey($device->secret, $code);
    }
}
