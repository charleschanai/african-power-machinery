<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Mail;

class TwoFactorService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const DIGITS = 6;

    private const PERIOD = 30;

    public function generateSecret(): string
    {
        $secret = '';

        for ($i = 0; $i < 32; $i++) {
            $secret .= self::BASE32_ALPHABET[random_int(0, 31)];
        }

        return $secret;
    }

    public function provisioningUri(User $user, string $secret): string
    {
        $issuer = 'ECard System';
        $account = $issuer.':'.$user->email;

        return 'otpauth://totp/'.rawurlencode($account)
            .'?secret='.rawurlencode($secret)
            .'&issuer='.rawurlencode($issuer)
            .'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::PERIOD;
    }

    public function verify(string $secret, string $code): bool
    {
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return false;
        }

        $now = time();

        for ($offset = -1; $offset <= 1; $offset++) {
            if (hash_equals($this->code($secret, $now + ($offset * self::PERIOD)), $code)) {
                return true;
            }
        }

        return false;
    }

    public function currentCode(string $secret, int $offset = 0): string
    {
        return $this->code($secret, time() + ($offset * self::PERIOD));
    }

    public function generateRecoveryCodes(int $count = 10): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $group = fn () => strtoupper(bin2hex(random_bytes(2)));
            $codes[] = implode('-', [$group(), $group(), $group()]);
        }

        return $codes;
    }

    public function generateEmailOtp(): string
    {
        return str_pad((string) random_int(0, 999999), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public function sendEmailOtp(User $user, string $code): void
    {
        Mail::raw(
            'Your ECard System verification code is: '.$code.PHP_EOL.PHP_EOL
            .'This code is valid for the next 5 minutes.'.PHP_EOL
            .'If you did not attempt to sign in, you can safely ignore this email.',
            function ($message) use ($user) {
                $message->to($user->email)
                    ->subject('ECard System - Your Verification Code');
            }
        );
    }

    public function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/\s+/', '', $code)));
    }

    public function verifyRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $normalized = $this->hashRecoveryCode($code);

        foreach ($codes as $index => $stored) {
            if (hash_equals($stored, $normalized)) {
                unset($codes[$index]);
                $user->two_factor_recovery_codes = array_values($codes);
                $user->save();

                return true;
            }
        }

        return false;
    }

    private function code(string $secret, int $timestamp): string
    {
        $counter = intdiv($timestamp, self::PERIOD);
        $key = $this->base32Decode($secret);
        $binary = hex2bin(str_pad(dechex($counter), 16, '0', STR_PAD_LEFT));
        $hash = hash_hmac('sha1', $binary, $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $data): string
    {
        $data = strtoupper(trim($data));
        $buffer = 0;
        $bits = 0;
        $output = '';

        foreach (str_split($data) as $char) {
            $position = strpos(self::BASE32_ALPHABET, $char);

            if ($position === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $position;
            $bits += 5;

            if ($bits >= 8) {
                $output .= chr(($buffer >> ($bits - 8)) & 0xFF);
                $bits -= 8;
            }
        }

        return $output;
    }
}
