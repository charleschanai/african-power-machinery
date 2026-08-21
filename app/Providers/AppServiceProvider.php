<?php

namespace App\Providers;

use App\Models\Card;
use App\Models\Guest;
use App\Policies\CardPolicy;
use App\Policies\GuestPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Guest::class, GuestPolicy::class);
        Gate::policy(Card::class, CardPolicy::class);

        $this->configureRateLimiters();
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $key = $request->user() ? 'user:'.$request->user()->id : 'ip:'.$request->ip();

            return Limit::perMinute((int) env('API_RATE_LIMIT', 120))->by($key);
        });

        RateLimiter::for('login', function (Request $request) {
            $key = strtolower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute((int) env('PUBLIC_API_RATE_LIMIT', 60))->by($request->ip());
        });
    }
}
