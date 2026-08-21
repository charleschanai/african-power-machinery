<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsStaff(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']), 'sanctum');
    }

    public function test_search_by_guest_name(): void
    {
        $this->actingAsStaff();
        $guest = Guest::factory()->create(['full_name' => 'Juma Said']);
        $card = Card::factory()->create(['guest_id' => $guest->id]);

        $response = $this->getJson('/api/v1/guests/search?q=Juma');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.guest_name', 'Juma Said')
            ->assertJsonPath('data.0.card_number', $card->card_number);
    }

    public function test_search_by_card_number(): void
    {
        $this->actingAsStaff();
        $card = Card::factory()->create(['card_number' => 'EC-000123']);

        $response = $this->getJson('/api/v1/guests/search?q=EC-000123');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.card_number', 'EC-000123');
    }

    public function test_search_returns_duplicate_names_with_distinct_cards(): void
    {
        $this->actingAsStaff();
        $first = Guest::factory()->create(['full_name' => 'Asha Yusuf']);
        $second = Guest::factory()->create(['full_name' => 'Asha Yusuf']);
        Card::factory()->create(['guest_id' => $first->id]);
        Card::factory()->create(['guest_id' => $second->id]);

        $response = $this->getJson('/api/v1/guests/search?q=Asha');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_search_with_no_match_returns_empty_list(): void
    {
        $this->actingAsStaff();

        $response = $this->getJson('/api/v1/guests/search?q=HakunaMtu');

        $response->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('message', 'No results found.');
    }

    public function test_search_requires_query(): void
    {
        $this->actingAsStaff();

        $this->getJson('/api/v1/guests/search')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }
}
