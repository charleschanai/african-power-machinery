<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsStaff(): User
    {
        $user = User::factory()->create(['role' => 'staff']);

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function confirmViaApi(string $identifier): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/cards/confirm-entry', [
            'card_number' => $identifier,
            'device_name' => 'TestDevice',
        ]);
    }

    public function test_single_card_first_entry_is_allowed(): void
    {
        $this->actingAsStaff();
        $card = Card::factory()->single()->create(['used_people' => 0]);

        $response = $this->confirmViaApi($card->card_number);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Entry permission has been granted.')
            ->assertJsonPath('data.card.used_people', 1);

        $this->assertSame(1, $card->fresh()->used_people);
        $this->assertSame(CardStatus::Used, $card->fresh()->status);
        $this->assertDatabaseHas('entry_logs', [
            'card_id' => $card->id,
            'result' => 'allowed',
            'entry_number' => 1,
        ]);
    }

    public function test_single_card_second_entry_is_denied(): void
    {
        $this->actingAsStaff();
        $card = Card::factory()->single()->create(['used_people' => 1]);

        $response = $this->confirmViaApi($card->card_number);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'CARD_ALREADY_USED')
            ->assertJsonPath('message', 'This card has already been used.');

        $this->assertSame(1, $card->fresh()->used_people);
        $this->assertDatabaseHas('entry_logs', [
            'card_id' => $card->id,
            'result' => 'already_used',
            'entry_number' => 2,
        ]);
    }

    public function test_cancelled_single_card_is_denied(): void
    {
        $this->actingAsStaff();
        $card = Card::factory()->single()->cancelled()->create();

        $response = $this->confirmViaApi($card->card_number);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'CARD_CANCELLED')
            ->assertJsonPath('message', 'This card has been cancelled.');

        $this->assertDatabaseHas('entry_logs', [
            'card_id' => $card->id,
            'result' => 'cancelled',
        ]);
    }

    public function test_invalid_card_is_denied(): void
    {
        $this->actingAsStaff();

        $response = $this->confirmViaApi('EC-999999');

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'CARD_NOT_FOUND')
            ->assertJsonPath('message', 'No card matches the details you entered.');

        $this->assertDatabaseHas('entry_logs', [
            'result' => 'invalid',
        ]);
    }

    public function test_double_card_first_entry_is_allowed(): void
    {
        $this->actingAsStaff();
        $card = Card::factory()->double()->create(['used_people' => 0]);

        $response = $this->confirmViaApi($card->card_number);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.card.used_people', 1);

        $this->assertSame(CardStatus::Active, $card->fresh()->status);
    }

    public function test_double_card_second_entry_is_allowed(): void
    {
        $this->actingAsStaff();
        $card = Card::factory()->double()->create(['used_people' => 1]);

        $response = $this->confirmViaApi($card->card_number);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.card.used_people', 2)
            ->assertJsonPath('data.card.remaining', 0);

        $this->assertSame(CardStatus::Used, $card->fresh()->status);
    }

    public function test_double_card_third_entry_is_denied(): void
    {
        $this->actingAsStaff();
        $card = Card::factory()->double()->create(['used_people' => 2]);

        $response = $this->confirmViaApi($card->card_number);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'CARD_ALREADY_USED')
            ->assertJsonPath('message', 'The allowed number of people has been reached.');

        $this->assertSame(2, $card->fresh()->used_people);
    }

    public function test_staff_cannot_cancel_card(): void
    {
        $this->actingAsStaff();
        $card = Card::factory()->create();

        $this->postJson("/api/v1/cards/{$card->id}/cancel")
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->assertSame(CardStatus::Active, $card->fresh()->status);
    }

    public function test_staff_cannot_create_cards(): void
    {
        $this->actingAsStaff();

        $this->postJson('/api/v1/cards', [
            'guest_id' => 1,
            'card_type' => 'single',
        ])->assertStatus(403);
    }

    public function test_admin_can_cancel_card(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum');

        $card = Card::factory()->create();

        $this->postJson("/api/v1/cards/{$card->id}/cancel")
            ->assertOk()
            ->assertJsonPath('message', 'The card has been cancelled.');

        $this->assertSame(CardStatus::Cancelled, $card->fresh()->status);
    }
}
