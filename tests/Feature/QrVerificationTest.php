<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QrVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsStaff(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']), 'sanctum');
    }

    public function test_valid_qr_is_verifiable(): void
    {
        $this->actingAsStaff();
        $card = Card::factory()->single()->create(['used_people' => 0]);

        $response = $this->postJson('/api/v1/cards/verify', [
            'qr_token' => $card->qr_token,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.card.card_number', $card->card_number)
            ->assertJsonPath('data.guest.full_name', $card->guest->full_name);
    }

    public function test_invalid_qr_is_denied(): void
    {
        $this->actingAsStaff();

        $this->postJson('/api/v1/cards/verify', [
            'qr_token' => 'fake-token-123',
        ])->assertStatus(422)
            ->assertJsonPath('error_code', 'CARD_NOT_FOUND');
    }

    public function test_qr_of_cancelled_card_is_denied(): void
    {
        $this->actingAsStaff();
        $card = Card::factory()->cancelled()->create();

        $this->postJson('/api/v1/cards/verify', [
            'qr_token' => $card->qr_token,
        ])->assertStatus(422)
            ->assertJsonPath('error_code', 'CARD_CANCELLED')
            ->assertJsonPath('message', 'This card has been cancelled.');
    }

    public function test_qr_of_used_card_is_denied(): void
    {
        $this->actingAsStaff();
        $card = Card::factory()->single()->create(['used_people' => 1]);

        $this->postJson('/api/v1/cards/verify', [
            'qr_token' => $card->qr_token,
        ])->assertStatus(422)
            ->assertJsonPath('error_code', 'CARD_ALREADY_USED')
            ->assertJsonPath('message', 'This card has already been used.');
    }

    public function test_qr_confirm_records_entry(): void
    {
        $this->actingAsStaff();
        $card = Card::factory()->single()->create(['used_people' => 0]);

        $this->postJson('/api/v1/cards/confirm-entry', [
            'qr_token' => $card->qr_token,
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, $card->fresh()->used_people);
        $this->assertSame(CardStatus::Used, $card->fresh()->status);
        $this->assertDatabaseHas('entry_logs', [
            'card_id' => $card->id,
            'result' => 'allowed',
        ]);
    }

    public function test_qr_confirm_does_not_mutate_during_verify(): void
    {
        $this->actingAsStaff();
        $card = Card::factory()->double()->create(['used_people' => 0]);

        $this->postJson('/api/v1/cards/verify', ['qr_token' => $card->qr_token])->assertOk();
        $this->assertSame(0, $card->fresh()->used_people);
        $this->assertDatabaseCount('entry_logs', 0);
    }

    public function test_public_card_link_exposes_only_safe_data(): void
    {
        $card = Card::factory()->single()->create(['used_people' => 0]);

        $response = $this->getJson('/api/v1/public/card/' . $card->qr_token);

        $response->assertOk()
            ->assertJsonPath('data.card.card_number', $card->card_number)
            ->assertJsonPath('data.guest.full_name', $card->guest->full_name);

        $this->assertArrayNotHasKey('token', $response->json('data.card'));
    }

    public function test_public_card_link_missing_card(): void
    {
        $this->getJson('/api/v1/public/card/does-not-exist')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'CARD_NOT_FOUND');
    }
}
