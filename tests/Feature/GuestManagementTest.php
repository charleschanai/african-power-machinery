<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_guest(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');

        $response = $this->postJson('/api/v1/guests', [
            'full_name' => 'Juma Said',
            'phone' => '255712345678',
            'email' => 'juma@example.com',
            'notes' => 'Rafiki wa familia',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Guest added.');

        $this->assertDatabaseHas('guests', [
            'full_name' => 'Juma Said',
            'phone' => '255712345678',
        ]);
    }

    public function test_guest_validation_errors_are_in_english(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');

        $this->postJson('/api/v1/guests', [
            'full_name' => '',
            'phone' => '',
        ])->assertStatus(422)
            ->assertJsonPath('errors.full_name.0', 'Full name is required.')
            ->assertJsonPath('errors.phone.0', 'Phone number is required.');
    }

    public function test_duplicate_phone_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        Guest::factory()->create(['phone' => '255712345678']);

        $this->postJson('/api/v1/guests', [
            'full_name' => 'Mgeni Mwingine',
            'phone' => '255712345678',
        ])->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'This phone number is already registered.');
    }

    public function test_admin_can_update_guest(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $guest = Guest::factory()->create(['full_name' => 'Jina la Kale']);

        $this->putJson("/api/v1/guests/{$guest->id}", [
            'full_name' => 'Jina Jipya',
            'phone' => $guest->phone,
        ])->assertOk()
            ->assertJsonPath('message', 'Guest updated.');

        $this->assertSame('Jina Jipya', $guest->fresh()->full_name);
    }

    public function test_admin_can_delete_guest(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $guest = Guest::factory()->create();

        $this->deleteJson("/api/v1/guests/{$guest->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Guest deleted.');

        $this->assertDatabaseMissing('guests', ['id' => $guest->id]);
    }

    public function test_staff_cannot_create_guest(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']), 'sanctum');

        $this->postJson('/api/v1/guests', [
            'full_name' => 'Mgeni',
            'phone' => '255712345678',
        ])->assertStatus(403)
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_guests_can_be_exported_to_csv(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        Guest::factory()->create(['full_name' => 'Mgeni wa CSV', 'phone' => '255712345678']);

        $response = $this->get('/api/v1/guests/export')->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('Full Name', $content);
        $this->assertStringContainsString('Mgeni wa CSV', $content);
        $this->assertStringContainsString('255712345678', $content);
    }
}
