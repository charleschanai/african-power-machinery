<?php

namespace App\Providers;

use App\Listeners\HealthCheckListener;
use App\Models\Card;
use App\Models\Guest;
use App\Policies\CardPolicy;
use App\Policies\GuestPolicy;
use App\Services\QueryMonitor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Single request-scoped instance so the query listener and the
        // request middleware share one set of counters.
        $this->app->singleton(QueryMonitor::class);
    }

    public function boot(): void
    {
        Gate::policy(Guest::class, GuestPolicy::class);
        Gate::policy(Card::class, CardPolicy::class);

        $this->configureRateLimiters();

        // Makes /up actually check the database and cache instead of just
        // confirming that PHP booted.
        Event::listen(
            \Illuminate\Foundation\Events\DiagnosingHealth::class,
            HealthCheckListener::class,
        );

        // Flags slow queries as they run. Attached here rather than in a
        // middleware so that console commands and queue workers are covered
        // too — both are where the slow queries usually are.
        $this->app->make(QueryMonitor::class)->attach();
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $key = $request->user() ? 'user:'.$request->user()->id : 'ip:'.$request->ip();

            return Limit::perMinute((int) config('security.api_rate_limit', 120))->by($key);
        });

        RateLimiter::for('login', function (Request $request) {
            $key = strtolower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinutes(
                (int) config('security.login_decay_minutes', 5),
                (int) config('security.login_attempts', 5),
            )->by($key);
        });

        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute((int) config('security.public_api_rate_limit', 60))->by($request->ip());
        });

        /**
         * Per-account limiter, keyed on the email alone rather than
         * email+IP.
         *
         * The per-IP limiter can be sidestepped by rotating source addresses;
         * this one cannot. It is what turns distributed credential stuffing
         * against a single admin account into a hard stop.
         */
        RateLimiter::for('login-account', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));

            if ($email === '') {
                return Limit::none();
            }

            return Limit::perMinutes(
                (int) config('security.login_decay_minutes', 5),
                (int) config('security.login_attempts', 5),
            )->by('account:'.$email);
        });

        /**
         * Two-factor code attempts. Used by every 2FA endpoint, not just
         * verification, so a stolen session token cannot be used to grind
         * through a 6-digit code at 120 requests a minute.
         */
        RateLimiter::for('two-factor', function (Request $request) {
            $user = $request->user();

            $key = $user ? 'user:'.$user->id : 'ip:'.$request->ip();

            return Limit::perMinutes(
                (int) config('security.two_factor_decay_minutes', 5),
                (int) config('security.two_factor_attempts', 5),
            )->by($key);
        });
    }
}
