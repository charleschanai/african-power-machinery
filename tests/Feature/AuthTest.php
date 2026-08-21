<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login(): void
    {
        User::factory()->admin()->create([
            'email' => 'admin@test.local',
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/api/v1/login', [
            'email' => 'admin@test.local',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_login_with_wrong_password_fails(): void
    {
        User::factory()->create(['email' => 'x@test.local']);

        $this->postJson('/api/v1/login', [
            'email' => 'x@test.local',
            'password' => 'wrong-password',
        ])->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_guests_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/guests')->assertStatus(401);
        $this->getJson('/api/v1/dashboard/statistics')->assertStatus(401);
        $this->postJson('/api/v1/cards/verify', ['qr_token' => 'x'])->assertStatus(401);
    }

    public function test_staff_cannot_access_dashboard_statistics(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']), 'sanctum');

        $this->getJson('/api/v1/dashboard/statistics')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/logout')
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }
}
