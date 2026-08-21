<?php

namespace App\Services;

class PhoneHelper
{
    /**
     * Normalize any local or international phone number to E.164
     * (digits only, without the leading "+").
     *
     * Handles the common Tanzanian formats:
     *  - 07XXXXXXXX / 0XXXXXXXXX  -> 255XXXXXXXXX
     *  - 7XXXXXXXX                -> 255XXXXXXXXX
     *  - 2557XXXXXXXX / +255...   -> 2557XXXXXXXX
     */
    public static function toE164(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if ($digits === '' || $digits === null) {
            return null;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '255')) {
            return $digits;
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            return '255' . substr($digits, 1);
        }

        if (strlen($digits) === 9 && str_starts_with($digits, '7')) {
            return '255' . $digits;
        }

        if (strlen($digits) === 13 && str_starts_with($digits, '255')) {
            return $digits;
        }

        return $digits;
    }

    /**
     * E.164 digits with a leading "+" (e.g. +255712345678).
     */
    public static function toDisplay(string $phone): ?string
    {
        $e164 = self::toE164($phone);

        return $e164 ? '+' . $e164 : null;
    }
}
