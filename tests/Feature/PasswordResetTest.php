<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_returns_success_for_existing_email(): void
    {
        Mail::fake();
        $user = User::factory()->admin()->create();

        $this->postJson('/api/v1/password/forgot', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('success', true)
            // One message for every outcome. A different string for "mail
            // sent" versus "unknown address" turns this endpoint into a way
            // to find out who is an admin.
            ->assertJsonPath('message', 'If this email exists in the system, a password reset link has been sent.');
    }

    public function test_forgot_does_not_reveal_unknown_email(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/password/forgot', ['email' => 'hakuna@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'If this email exists in the system, a password reset link has been sent.');
    }

    public function test_forgot_never_returns_a_data_payload(): void
    {
        Mail::fake();
        $user = User::factory()->admin()->create();

        // The endpoint used to return data.reset_url whenever the mail send
        // threw, handing an unauthenticated caller a working password reset
        // token. A broken SMTP block in .env is the easiest way to trigger
        // exactly that.
        $response = $this->postJson('/api/v1/password/forgot', ['email' => $user->email]);

        $this->assertArrayNotHasKey('data', $response->json());
        $this->assertStringNotContainsString('token', $response->getContent());
    }

    public function test_reset_changes_password_and_allows_login(): void
    {
        $user = User::factory()->admin()->create(['password' => 'old-password1']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'New-Password1!',
            'password_confirmation' => 'New-Password1!',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('New-Password1!', $user->fresh()->password));

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'New-Password1!',
        ])->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_reset_rejects_invalid_token(): void
    {
        $user = User::factory()->admin()->create();

        $this->postJson('/api/v1/password/reset', [
            'email' => $user->email,
            'token' => 'invalid-token',
            'password' => 'New-Password1!',
            'password_confirmation' => 'New-Password1!',
        ])->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_RESET_TOKEN');
    }

    public function test_reset_rejects_mismatched_confirmation(): void
    {
        $user = User::factory()->admin()->create();

        $this->postJson('/api/v1/password/reset', [
            'email' => $user->email,
            'token' => 'whatever',
            'password' => 'New-Password1!',
            'password_confirmation' => 'different1',
        ])->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'The password and its confirmation do not match.');
    }
}
