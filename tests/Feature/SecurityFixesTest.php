<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Regression tests for the security fixes.
 *
 * These exist because each of the underlying bugs was silent: a working
 * password reset, a 200 from the webhook, a plausible-looking admin UI. None
 * of them produced an error, which is exactly why they survived.
 */
class SecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ */
    /*  Password reset must never hand out a token                        */
    /* ------------------------------------------------------------------ */

    public function test_forgot_password_never_returns_a_reset_token(): void
    {
        // Point the mailer at a transport that cannot work, rather than
        // mocking the Mail facade. Mockery's facade mock is brittle here
        // because the controller also reaches for Log and AlertService in
        // the same catch block.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

        $user = User::factory()->create(['email' => 'admin@example.test']);

        $response = $this->postJson('/api/v1/password/forgot', ['email' => 'admin@example.test']);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        // The whole response is inspected, not just the data key. The token
        // used to appear in data.reset_url, and the endpoint is
        // unauthenticated, so that was a full account takeover for anyone who
        // could make the mail server fail.
        $body = $response->getContent();

        $this->assertStringNotContainsString('reset_url', $body);
        $this->assertStringNotContainsString('/reset-password?token=', $body);
        $this->assertStringNotContainsString('token=', $body);
        $this->assertArrayNotHasKey('data', $response->json());
    }

    public function test_forgot_password_gives_the_same_answer_for_known_and_unknown_emails(): void
    {
        Mail::fake();

        User::factory()->create(['email' => 'known@example.test']);

        $known = $this->postJson('/api/v1/password/forgot', ['email' => 'known@example.test']);
        $unknown = $this->postJson('/api/v1/password/forgot', ['email' => 'nobody@example.test']);

        // Identical status and identical body. Anything else turns this
        // endpoint into an admin-enumeration oracle.
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_forgot_password_never_leaks_whether_mail_was_sent(): void
    {
        User::factory()->create(['email' => 'admin@example.test']);

        // Mail is broken.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
        $failing = $this->postJson('/api/v1/password/forgot', ['email' => 'admin@example.test']);

        // Mail works.
        config(['mail.default' => 'array']);
        $ok = $this->postJson('/api/v1/password/forgot', ['email' => 'admin@example.test']);

        $this->assertSame($failing->json(), $ok->json());
        $this->assertArrayNotHasKey('data', $failing->json());
    }

    /* ------------------------------------------------------------------ */
    /*  HTTP status codes must survive                                     */
    /* ------------------------------------------------------------------ */

    public function test_rate_limiting_returns_429_not_500(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test', 'password' => 'correct-password']);

        $statuses = [];

        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $this->postJson('/api/v1/login', [
                'email' => 'admin@example.test',
                'password' => 'wrong-password',
            ])->getStatusCode();
        }

        // The catch-all exception renderer used to convert a throttle into a
        // 500 with no Retry-After, so clients could not back off and the
        // monitoring dashboard counted rate limits as server faults.
        $this->assertContains(429, $statuses, 'Rate limiting did not return 429.');
        $this->assertNotContains(500, $statuses, 'Rate limiting was converted into a 500.');
    }

    public function test_a_policy_denial_returns_403_not_500(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff, 'sanctum');

        $response = $this->getJson('/api/v1/dashboard/statistics');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('FORBIDDEN', $response->json('error_code'));
    }

    public function test_a_missing_model_returns_404(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum');

        $response = $this->getJson('/api/v1/guests/99999999');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('NOT_FOUND', $response->json('error_code'));
    }

    public function test_an_unknown_endpoint_returns_404(): void
    {
        $this->getJson('/api/v1/definitely-not-a-route')->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /*  Entry logs are not readable by staff                               */
    /* ------------------------------------------------------------------ */

    public function test_staff_cannot_read_or_export_entry_logs(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff, 'sanctum');

        // Entry logs name every guest and the staff member who scanned them.
        $this->getJson('/api/v1/entry-logs')->assertForbidden();
        $this->getJson('/api/v1/entry-logs/export')->assertForbidden();
    }

    public function test_admin_can_read_entry_logs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum');

        $this->getJson('/api/v1/entry-logs')->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /*  Webhook fails closed                                               */
    /* ------------------------------------------------------------------ */

    public function test_webhook_rejects_requests_when_the_app_secret_is_missing(): void
    {
        config(['services.whatsapp.app_secret' => null]);

        // This used to pass `if (filled($secret) && ...)` which meant an unset
        // secret — the state .env.example ships — disabled verification
        // entirely, turning this into an open write endpoint that could
        // trigger paid LLM calls and message real guests.
        $this->postJson('/whatsapp/webhook', ['entry' => []])
            ->assertStatus(503);
    }

    public function test_webhook_rejects_an_unsigned_request(): void
    {
        config(['services.whatsapp.app_secret' => 'a-real-secret']);

        $this->postJson('/whatsapp/webhook', ['entry' => []])
            ->assertForbidden();
    }

    public function test_webhook_rejects_a_tampered_body(): void
    {
        config(['services.whatsapp.app_secret' => 'a-real-secret']);

        $body = ['entry' => []];

        // The signature is computed over a different body than the one sent,
        // which is exactly what an attacker intercepting and editing the
        // payload would produce.
        $signature = 'sha256='.hash_hmac('sha256', json_encode(['entry' => ['tampered' => true]]), 'a-real-secret');

        $this->call(
            'POST',
            '/whatsapp/webhook',
            [],
            [],
            [],
            [
                'HTTP_X-Hub-Signature-256' => $signature,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            json_encode($body),
        )->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /*  Permissions policy must permit the camera                          */
    /* ------------------------------------------------------------------ */

    public function test_the_camera_is_permitted_so_the_qr_scanner_works(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum');

        $response = $this->getJson('/api/v1/me');

        $policy = $response->headers->get('Permissions-Policy');

        $this->assertNotNull($policy, 'Permissions-Policy header is missing.');

        // The whole entry system depends on getUserMedia. camera=() killed
        // the QR scanner in production while every other header looked
        // perfectly secure.
        $this->assertStringContainsString('camera=(self)', $policy);
        $this->assertStringNotContainsString('camera=()', $policy);

        // Everything the app does not use stays switched off.
        $this->assertStringContainsString('geolocation=()', $policy);
        $this->assertStringContainsString('microphone=()', $policy);
    }

    /* ------------------------------------------------------------------ */
    /*  Rate limit configuration survives config:cache                     */
    /* ------------------------------------------------------------------ */

    public function test_rate_limits_are_read_from_config_not_env(): void
    {
        // The limiter closures read config('security.*'), not env().
        //
        // env() inside a closure silently reverts to its hard-coded default
        // once config:cache runs, because .env is no longer loaded. An
        // operator who set API_RATE_LIMIT=20 was actually getting 120, with
        // no error and no log line.
        config(['security.api_rate_limit' => 20, 'security.public_api_rate_limit' => 7]);

        $this->assertSame(20, config('security.api_rate_limit'));

        $api = \Illuminate\Support\Facades\RateLimiter::limiter('api');
        $limit = $api(\Illuminate\Http\Request::create('/api/v1/cards', 'GET'));

        $this->assertSame(20, $limit->maxAttempts);

        $public = \Illuminate\Support\Facades\RateLimiter::limiter('public');
        $publicLimit = $public(\Illuminate\Http\Request::create('/api/v1/health', 'GET'));

        $this->assertSame(7, $publicLimit->maxAttempts);
    }

    public function test_rate_limit_config_never_resolves_to_unlimited(): void
    {
        // An operator who writes API_RATE_LIMIT= with no value gets 0, and
        // Laravel treats perMinute(0) as "no limit at all". The config file
        // has to guard against that.
        $this->assertGreaterThan(0, (int) config('security.api_rate_limit', 0));
        $this->assertGreaterThan(0, (int) config('security.public_api_rate_limit', 0));
        $this->assertGreaterThan(0, (int) config('security.login_attempts', 0));
        $this->assertGreaterThan(0, (int) config('security.two_factor_attempts', 0));
    }
}
