<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Enums\CardType;
use App\Models\Card;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CardManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_card_gets_allowed_people_of_one(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $guest = Guest::factory()->create();

        $response = $this->postJson('/api/v1/cards', [
            'guest_id' => $guest->id,
            'card_type' => 'single',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.card.card_type', 'single')
            ->assertJsonPath('data.card.allowed_people', 1);

        $this->assertMatchesRegularExpression('/^EC-\d{6}$/', $response->json('data.card.card_number'));
    }

    public function test_double_card_gets_allowed_people_of_two(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $guest = Guest::factory()->create();

        $response = $this->postJson('/api/v1/cards', [
            'guest_id' => $guest->id,
            'card_type' => 'double',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.card.card_type', 'double')
            ->assertJsonPath('data.card.allowed_people', 2);
    }

    public function test_card_number_is_unique_and_sequential(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $guest = Guest::factory()->create();

        $first = $this->postJson('/api/v1/cards', ['guest_id' => $guest->id, 'card_type' => 'single'])->json('data.card');
        $second = $this->postJson('/api/v1/cards', ['guest_id' => $guest->id, 'card_type' => 'double'])->json('data.card');

        $this->assertNotSame($first['card_number'], $second['card_number']);

        $firstNumber = (int) substr($first['card_number'], 3);
        $secondNumber = (int) substr($second['card_number'], 3);
        $this->assertSame($firstNumber + 1, $secondNumber);
    }

    public function test_invalid_card_type_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $guest = Guest::factory()->create();

        $this->postJson('/api/v1/cards', [
            'guest_id' => $guest->id,
            'card_type' => 'family',
        ])->assertStatus(422)
            ->assertJsonPath('errors.card_type.0', 'Card type is not valid.');
    }

    public function test_cards_can_be_filtered_by_search_term(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $guest = Guest::factory()->create(['full_name' => 'Asha Juma']);
        Card::factory()->create(['guest_id' => $guest->id, 'card_number' => 'EC-111111']);
        Card::factory()->create(['guest_id' => Guest::factory(), 'card_number' => 'EC-222222']);

        $byName = $this->getJson('/api/v1/cards?q=Asha')->assertOk()->json('data.data');
        $byNumber = $this->getJson('/api/v1/cards?q=EC-111111')->assertOk()->json('data.data');

        $this->assertCount(1, $byName);
        $this->assertSame('EC-111111', $byName[0]['card_number']);
        $this->assertCount(1, $byNumber);
    }

    public function test_search_filter_does_not_bypass_status_filter(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $guest = Guest::factory()->create(['full_name' => 'Hamisi Rajabu']);
        Card::factory()->create(['guest_id' => $guest->id, 'card_number' => 'EC-333333', 'status' => 'cancelled']);
        Card::factory()->create(['guest_id' => $guest->id, 'card_number' => 'EC-444444', 'status' => 'active']);

        $response = $this->getJson('/api/v1/cards?q=EC-333333&status=cancelled')->assertOk();

        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame('EC-333333', $response->json('data.data.0.card_number'));

        $response = $this->getJson('/api/v1/cards?q=Hamisi&status=active')->assertOk();

        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame('EC-444444', $response->json('data.data.0.card_number'));
    }

    public function test_cards_can_be_exported_to_csv(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $guest = Guest::factory()->create(['full_name' => 'Mgeni wa Export']);
        Card::factory()->create(['guest_id' => $guest->id, 'card_number' => 'EC-555555']);

        $response = $this->get('/api/v1/cards/export')->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('Card Number', $content);
        $this->assertStringContainsString('EC-555555', $content);
        $this->assertStringContainsString('Mgeni wa Export', $content);
    }

    public function test_expired_card_is_denied(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']), 'sanctum');
        $card = Card::factory()->single()->create([
            'expires_at' => now()->subDay(),
            'used_people' => 0,
        ]);

        $this->postJson('/api/v1/cards/confirm-entry', [
            'card_number' => $card->card_number,
        ])->assertStatus(422)
            ->assertJsonPath('error_code', 'CARD_EXPIRED')
            ->assertJsonPath('message', 'This card has expired.');
    }

    public function test_cards_can_be_imported_from_csv_with_guest_details(): void
    {
        Storage::fake('local');

        $csv = <<<'CSV'
jina,simu,barua_pepe,aina,namba_ya_kadi,inayomalizika
Juma Ally,0754123456,juma@example.com,single,EC-000100,2026-12-31 23:59
Asha Hassan,0711223344,,double,,
CSV;

        $this->actingAs(User::factory()->admin()->create(), 'sanctum');

        $response = $this->post('/api/v1/cards/import', [
            'file' => UploadedFile::fake()->createWithContent('kadi.csv', $csv),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.imported', 2)
            ->assertJsonPath('data.skipped', 0);

        $this->assertDatabaseHas('guests', ['full_name' => 'Juma Ally', 'phone' => '0754123456']);
        $this->assertDatabaseHas('guests', ['full_name' => 'Asha Hassan', 'phone' => '0711223344']);

        $this->assertDatabaseHas('cards', ['card_number' => 'EC-000100', 'card_type' => 'single', 'allowed_people' => 1]);
        $this->assertDatabaseHas('cards', ['card_number' => 'EC-000101', 'card_type' => 'double', 'allowed_people' => 2]);
    }

    public function test_import_reuses_existing_guest_by_phone(): void
    {
        Storage::fake('local');

        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $guest = Guest::factory()->create(['full_name' => 'Asha Hassan', 'phone' => '0711223344']);

        $csv = <<<'CSV'
jina,simu,barua_pepe,aina,namba_ya_kadi
Asha Hassan,0711223344,asha@example.com,single,
Baraka Mushi,0711223344,,single,
CSV;

        $this->post('/api/v1/cards/import', [
            'file' => UploadedFile::fake()->createWithContent('kadi.csv', $csv),
        ])->assertOk()->assertJsonPath('data.imported', 2);

        $this->assertSame(1, Guest::query()->where('phone', '0711223344')->count());
        $this->assertSame(2, Guest::query()->find($guest->id)->cards()->count());
    }

    public function test_import_skips_invalid_rows_and_reports_errors(): void
    {
        Storage::fake('local');

        $csv = <<<'CSV'
jina,simu,barua_pepe,aina,namba_ya_kadi
Juma Ally,,,single,EC-000200
Asha Hassan,0711223344,,family,EC-000201
Baraka Mushi,0733556677,,double,EC-000200
CSV;

        $this->actingAs(User::factory()->admin()->create(), 'sanctum');

        $response = $this->post('/api/v1/cards/import', [
            'file' => UploadedFile::fake()->createWithContent('kadi.csv', $csv),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.skipped', 2);

        $this->assertCount(2, $response->json('data.errors'));
        $this->assertDatabaseHas('cards', ['card_number' => 'EC-000200']);
        $this->assertDatabaseMissing('cards', ['card_number' => 'EC-000201']);
    }
}
