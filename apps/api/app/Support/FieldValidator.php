<?php

namespace App\Support;

use DateTime;

/**
 * Format checks and clean-up for registration answers. Each returns [ok, cleaned value, error message].
 */
class FieldValidator
{
    private static function validPlaceOfBirth(int $pb): bool
    {
        return ($pb >= 1 && $pb <= 16) || ($pb >= 21 && $pb <= 59) || ($pb >= 60 && $pb <= 68)
            || in_array($pb, [71, 72, 74, 75, 76, 77, 78, 79, 82, 83, 84, 85, 86, 87, 88, 89, 90, 91, 92, 93, 98, 99], true);
    }

    /**
     * @return array{0: bool, 1: string, 2: string}
     */
    public static function nric(string $value, string $format = 'my'): array
    {
        $raw = strtoupper(trim($value));
        $digits = (string) preg_replace('/[\s\-]/', '', $raw);

        if (preg_match('/^\d{12}$/', $digits)) {
            $yy = (int) substr($digits, 0, 2);
            $mm = (int) substr($digits, 2, 2);
            $dd = (int) substr($digits, 4, 2);
            $pb = (int) substr($digits, 6, 2);
            $valid = checkdate($mm, $dd, 2000 + $yy) || checkdate($mm, $dd, 1900 + $yy);
            if ($valid && self::validPlaceOfBirth($pb)) {
                return [true, substr($digits, 0, 6).'-'.substr($digits, 6, 2).'-'.substr($digits, 8), ''];
            }
            if ($format !== 'any') {
                return [false, $raw, 'Please enter a valid Malaysian NRIC (e.g. 900101-14-5678).'];
            }
        }
        if ($format === 'my') {
            return [false, $raw, 'Please enter a valid 12-digit Malaysian NRIC (e.g. 900101-14-5678).'];
        }
        if ($format === 'my_or_passport') {
            return preg_match('/^[A-Z0-9]{6,12}$/', $digits)
                ? [true, $digits, '']
                : [false, $raw, 'Please enter a valid NRIC or passport number.'];
        }

        return strlen($digits) >= 4 && strlen($digits) <= 30
            ? [true, $raw, '']
            : [false, $raw, 'Please enter a valid ID number.'];
    }

    /**
     * @return array{0: bool, 1: string, 2: string}
     */
    public static function phone(string $value, string $format = 'my'): array
    {
        $raw = trim($value);
        $plus = str_starts_with($raw, '+');
        $digits = (string) preg_replace('/\D/', '', $raw);

        if ($format === 'my') {
            if (str_starts_with($digits, '60')) {
                $digits = substr($digits, 2);
            } elseif (str_starts_with($digits, '0')) {
                $digits = substr($digits, 1);
            }

            return preg_match('/^(1\d{8,9}|[3-9]\d{7,8})$/', $digits)
                ? [true, '+60'.$digits, '']
                : [false, $raw, 'Please enter a valid Malaysian phone number (e.g. 012-345 6789).'];
        }
        if ($format === 'intl') {
            return ($plus || strlen($digits) >= 10) && preg_match('/^[1-9]\d{7,14}$/', $digits)
                ? [true, '+'.$digits, '']
                : [false, $raw, 'Please include the country code (e.g. +60 12-345 6789).'];
        }

        return preg_match('/^\d{6,20}$/', $digits) ? [true, $raw, ''] : [false, $raw, 'Please enter a valid phone number.'];
    }

    /**
     * @return array{0: bool, 1: string, 2: string}
     */
    public static function email(string $value): array
    {
        $v = strtolower(trim($value));

        return filter_var($v, FILTER_VALIDATE_EMAIL) ? [true, $v, ''] : [false, $value, 'Please enter a valid email address.'];
    }

    /**
     * @return array{0: bool, 1: string, 2: string}
     */
    public static function number(string $value): array
    {
        $v = trim($value);

        return is_numeric($v) ? [true, $v, ''] : [false, $v, 'Please enter a number.'];
    }

    /**
     * @return array{0: bool, 1: string, 2: string}
     */
    public static function date(string $value): array
    {
        $v = trim($value);
        $d = DateTime::createFromFormat('Y-m-d', $v);

        return $d && $d->format('Y-m-d') === $v ? [true, $v, ''] : [false, $v, 'Please enter a valid date.'];
    }

    /**
     * Browser-side patterns matching the rules above (instant feedback on the public form).
     *
     * @return array<string, array<string, string>>
     */
    public static function patterns(): array
    {
        return [
            'nric' => [
                'my' => '^\s*\d{6}[\s-]?\d{2}[\s-]?\d{4}\s*$',
                'my_or_passport' => '^\s*([0-9]{6}[\s-]?[0-9]{2}[\s-]?[0-9]{4}|[A-Za-z0-9]{6,12})\s*$',
                'any' => '.{4,30}',
            ],
            'phone' => [
                'my' => '^\s*(\+?6?0)?[\s-]?1\d[\s-]?\d{3,4}[\s-]?\d{4}\s*$|^\s*(\+?6?0)?[3-9][\s-]?\d{3,4}[\s-]?\d{4}\s*$',
                'intl' => '^\s*\+?[1-9][\d\s-]{7,18}\s*$',
                'any' => '^[\d\s+()-]{6,25}$',
            ],
        ];
    }
}
