<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TwoFactorService
    {
        return app(TwoFactorService::class);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create([
            'password' => Hash::make('password'),
        ]);
    }

    public function test_enable_requires_correct_password(): void
    {
        $user = $this->admin();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/two-factor/enable', ['password' => 'wrong'])
            ->assertStatus(422);

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_full_enable_confirm_and_login_flow(): void
    {
        $user = $this->admin();

        $enable = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/two-factor/enable', ['password' => 'password'])
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('secret', $enable);
        $this->assertArrayHasKey('provisioning_uri', $enable);
        $this->assertCount(10, $enable['recovery_codes']);
        $this->assertStringStartsWith('otpauth://totp/', $enable['provisioning_uri']);

        // Not confirmed yet.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/two-factor')
            ->assertJsonPath('data.enabled', false);

        $code = $this->service()->currentCode($enable['secret']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/two-factor/confirm', ['code' => $code])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/two-factor')
            ->assertJsonPath('data.enabled', true);

        // Login now returns a two-factor challenge instead of a token.
        $login = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('two_factor_required', true);

        $this->assertArrayNotHasKey('token', $login->json('data'));

        // Wrong code rejected.
        $this->postJson('/api/v1/auth/two-factor/verify', [
            'two_factor_token' => $login->json('data.two_factor_token'),
            'code' => '000000',
        ])->assertStatus(422);

        // Correct code completes login.
        $this->postJson('/api/v1/auth/two-factor/verify', [
            'two_factor_token' => $login->json('data.two_factor_token'),
            'code' => $this->service()->currentCode($enable['secret']),
        ])->assertOk()
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_verify_token_is_single_use(): void
    {
        $user = $this->admin();

        $secret = $this->service()->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ])->save();

        $login = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $token = $login->json('data.two_factor_token');

        $this->postJson('/api/v1/auth/two-factor/verify', [
            'two_factor_token' => $token,
            'code' => $this->service()->currentCode($secret),
        ])->assertOk();

        // Second use must fail (token consumed).
        $this->postJson('/api/v1/auth/two-factor/verify', [
            'two_factor_token' => $token,
            'code' => $this->service()->currentCode($secret),
        ])->assertStatus(422);
    }

    public function test_recovery_code_completes_login_and_is_consumed(): void
    {
        $user = $this->admin();

        $enable = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/two-factor/enable', ['password' => 'password'])
            ->json('data');

        $code = $this->service()->currentCode($enable['secret']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/two-factor/confirm', ['code' => $code])
            ->assertOk();

        $recovery = $enable['recovery_codes'][0];

        $login = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->postJson('/api/v1/auth/two-factor/verify', [
            'two_factor_token' => $login->json('data.two_factor_token'),
            'code' => $recovery,
        ])->assertOk();

        // Recovery code is now consumed.
        $this->postJson('/api/v1/auth/two-factor/verify', [
            'two_factor_token' => $this->newChallenge($user),
            'code' => $recovery,
        ])->assertStatus(422);
    }

    public function test_recovery_codes_can_be_regenerated(): void
    {
        $user = $this->admin();

        $enable = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/two-factor/enable', ['password' => 'password'])
            ->json('data');

        $code = $this->service()->currentCode($enable['secret']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/two-factor/confirm', ['code' => $code])
            ->assertOk();

        $regenerated = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/two-factor/regenerate', ['code' => $code])
            ->assertOk()
            ->json('data');

        $this->assertCount(10, $regenerated['recovery_codes']);
        $this->assertNotContains($enable['recovery_codes'][0], $regenerated['recovery_codes']);
    }

    public function test_disable_requires_valid_code(): void
    {
        $user = $this->admin();

        $enable = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/two-factor/enable', ['password' => 'password'])
            ->json('data');

        $code = $this->service()->currentCode($enable['secret']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/two-factor/confirm', ['code' => $code])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/two-factor/disable', ['code' => '000000'])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/two-factor/disable', ['code' => $code])
            ->assertOk();

        $this->assertNull($user->fresh()->two_factor_confirmed_at);
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_2fa_columns_are_hidden_from_user_payload(): void
    {
        $user = $this->admin();
        $user->forceFill([
            'two_factor_secret' => $this->service()->generateSecret(),
            'two_factor_recovery_codes' => ['abc'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonMissingPath('data.user.two_factor_secret')
            ->assertJsonMissingPath('data.user.two_factor_recovery_codes');
    }

    public function test_login_and_me_expose_two_factor_enabled_flag(): void
    {
        $user = $this->admin();

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user.two_factor_enabled', false);

        $enable = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/two-factor/enable', ['password' => 'password'])
            ->json('data');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/two-factor/confirm', ['code' => $this->service()->currentCode($enable['secret'])])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.two_factor_enabled', true);
    }

    private function newChallenge(User $user): string
    {
        $login = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        return $login->json('data.two_factor_token');
    }
}
