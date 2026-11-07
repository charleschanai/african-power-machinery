<?php

/*
 * Helper: a rate limit that can never end up as 0.
 *
 * An operator who writes API_RATE_LIMIT= with no value after the key gets
 * (int) '' === 0, and Laravel reads perMinute(0) as "no limit at all". A
 * blank line in .env must never quietly remove the protection.
 */
$limit = static function (string $key, int $default): int {
    $value = (int) env($key, $default);

    return $value > 0 ? $value : $default;
};

$minutes = static function (string $key, int $default): int {
    $value = (int) env($key, $default);

    return $value > 0 ? $value : $default;
};

return [

    /*
    |--------------------------------------------------------------------------
    | API rate limits
    |--------------------------------------------------------------------------
    |
    | These live in a config file rather than being read with env() inside
    | the limiter closures. Once `php artisan config:cache` runs — the normal
    | production step — .env is not loaded, so a closure calling env() falls
    | back to its hard-coded default and the limit an operator set is
    | silently ignored. Worse, an operator who writes API_RATE_LIMIT= with no
    | value gets perMinute(0), which Laravel treats as unlimited.
    |
    */

    'api_rate_limit' => $limit('API_RATE_LIMIT', 120),
    'public_api_rate_limit' => $limit('PUBLIC_API_RATE_LIMIT', 60),

    'login_attempts' => $limit('LOGIN_MAX_ATTEMPTS', 5),
    'login_decay_minutes' => $minutes('LOGIN_DECAY_MINUTES', 5),

    // Two-factor code attempts, applied to verify, confirm, disable and
    // regenerate. Only verify() used to be limited, which left the others
    // open to an unhurried 6-digit guess with a stolen session token.
    'two_factor_attempts' => $limit('TWO_FACTOR_MAX_ATTEMPTS', 5),
    'two_factor_decay_minutes' => $minutes('TWO_FACTOR_DECAY_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Proxies and hosts
    |--------------------------------------------------------------------------
    */

    // Comma-separated. "*" trusts every proxy; narrow it to your load
    // balancer's CIDR if you can, because trusting every proxy also means
    // trusting every client-supplied X-Forwarded-For value, and the login
    // rate limiter is keyed on the client IP.
    'trusted_proxies' => env('TRUSTED_PROXIES', '*'),
    'trusted_hosts' => env('TRUSTED_HOSTS', ''),

];
