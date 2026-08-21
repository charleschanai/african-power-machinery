<?php

namespace Tests\Feature;

use App\Models\CardTemplate;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NewFeaturesFullFlowTest extends TestCase
{
    use RefreshDatabase;

    private function fakePng(string $name = 'front.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'png') . '.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    private function staff(): User
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff, 'sanctum');

        return $staff;
    }

    private function createSingleTemplate(string $name = 'Wedding Gold'): array
    {
        return $this->postJson('/api/v1/card-templates', [
            'name' => $name,
            'card_type' => 'single',
            'front_design' => $this->fakePng('front.png'),
            'description' => 'Design test',
        ])->assertCreated()->assertJsonPath('success', true)->json('data');
    }

    public function test_full_flow_single_template_to_checkin(): void
    {
        Storage::fake('public');
        $this->admin();

        $template = $this->createSingleTemplate();

        $this->assertDatabaseHas('card_templates', [
            'id' => $template['id'],
            'name' => 'Wedding Gold',
            'card_type' => 'single',
            'status' => 'active',
        ]);

        $guest = Guest::factory()->create();

        $card = $this->postJson('/api/v1/cards', [
            'guest_id' => $guest->id,
            'card_template_id' => $template['id'],
            'card_type' => 'single',
            'event_name' => 'Harusi ya Juma na Neema',
            'event_date' => now()->addDays(10)->toDateString(),
            'event_time' => '16:00',
            'venue' => 'Kiliwani Hotel, Dar es Salaam',
            'table_number' => 'T-12',
            'special_message' => 'Karibu sana!',
        ])->assertCreated()->assertJsonPath('success', true)->json('data.card');

        $this->assertDatabaseHas('cards', [
            'id' => $card['id'],
            'card_template_id' => $template['id'],
        ]);

        $this->assertSame($card['card_template']['name'], 'Wedding Gold');
        $this->assertSame($card['event_name'], 'Harusi ya Juma na Neema');
        $this->assertSame($card['venue'], 'Kiliwani Hotel, Dar es Salaam');
        $this->assertSame($card['special_message'], 'Karibu sana!');

        $this->getJson("/api/v1/public/card/{$card['qr_token']}")
            ->assertOk()
            ->assertJsonPath('data.guest.full_name', $guest->full_name)
            ->assertJsonPath('data.card.card_number', $card['card_number'])
            ->assertJsonPath('data.card.event_name', 'Harusi ya Juma na Neema')
            ->assertJsonPath('data.card.event_date', now()->addDays(10)->toDateString())
            ->assertJsonPath('data.card.event_time', '16:00:00')
            ->assertJsonPath('data.card.venue', 'Kiliwani Hotel, Dar es Salaam')
            ->assertJsonPath('data.card.table_number', 'T-12')
            ->assertJsonPath('data.card.special_message', 'Karibu sana!')
            ->assertJsonPath('data.card.template.name', 'Wedding Gold')
            ->assertJsonPath('data.card.template.front_design', $template['front_design']);

        $this->staff();
        $verify = $this->postJson('/api/v1/cards/verify', ['card_number' => $card['card_number']])
            ->assertOk()->assertJsonPath('success', true)->json('data');

        $this->assertSame($verify['card']['template']['name'], 'Wedding Gold');
        $this->assertSame($verify['card']['event_name'], 'Harusi ya Juma na Neema');

        $confirm = $this->postJson('/api/v1/cards/confirm-entry', [
            'card_number' => $card['card_number'],
            'device_name' => 'Gate-Device-1',
            'number_of_guests' => 1,
            'verification_method' => 'qr',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, $confirm->json('data.card.used_people'));
        $this->assertSame('qr', $confirm->json('data.entry.verification_method'));
        $this->assertSame(1, $confirm->json('data.entry.number_of_guests'));
        $this->assertDatabaseHas('entry_logs', [
            'card_id' => $card['id'],
            'verification_method' => 'qr',
            'number_of_guests' => 1,
            'result' => 'allowed',
        ]);
    }

    public function test_double_template_and_double_checkin(): void
    {
        Storage::fake('public');
        $this->admin();

        $template = $this->postJson('/api/v1/card-templates', [
            'name' => 'Anniversary Double',
            'card_type' => 'double',
            'front_design' => $this->fakePng('front.png'),
            'back_design' => $this->fakePng('back.png'),
        ])->assertCreated()->assertJsonPath('data.card_type', 'double')->json('data');

        $this->assertNotNull($template['back_design']);

        $guest = Guest::factory()->create();
        $card = $this->postJson('/api/v1/cards', [
            'guest_id' => $guest->id,
            'card_template_id' => $template['id'],
            'card_type' => 'double',
        ])->assertCreated()->json('data.card');

        $this->assertSame(2, $card['allowed_people']);

        $this->staff();
        $this->postJson('/api/v1/cards/confirm-entry', [
            'card_number' => $card['card_number'],
            'device_name' => 'Gate-1',
            'number_of_guests' => 2,
            'verification_method' => 'card_number',
        ])->assertOk()->assertJsonPath('data.card.remaining', 0);

        $this->assertDatabaseHas('entry_logs', [
            'card_id' => $card['id'],
            'verification_method' => 'card_number',
            'number_of_guests' => 2,
        ]);
    }

    public function test_template_requires_back_design_for_double(): void
    {
        Storage::fake('public');
        $this->admin();

        $this->postJson('/api/v1/card-templates', [
            'name' => 'Missing Back',
            'card_type' => 'double',
            'front_design' => $this->fakePng('front.png'),
        ])->assertStatus(422)->assertJsonValidationErrors('back_design');
    }

    public function test_admin_can_toggle_template_status_and_update(): void
    {
        Storage::fake('public');
        $this->admin();

        $template = $this->createSingleTemplate('Toggle Me');

        $this->postJson("/api/v1/card-templates/{$template['id']}/toggle-status")
            ->assertOk()->assertJsonPath('data.status', 'inactive');

        $this->putJson("/api/v1/card-templates/{$template['id']}", [
            'name' => 'Updated Name',
            'card_type' => 'single',
            'front_design' => $this->fakePng('front2.png'),
            'status' => 'active',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Name');

        $this->getJson('/api/v1/card-templates')
            ->assertOk()
            ->assertJsonPath('data.data.0.name', 'Updated Name')
            ->assertJsonPath('data.data.0.status', 'active');
    }

    public function test_template_in_use_cannot_be_deleted(): void
    {
        Storage::fake('public');
        $this->admin();

        $template = $this->createSingleTemplate('In Use');

        $guest = Guest::factory()->create();
        $this->postJson('/api/v1/cards', [
            'guest_id' => $guest->id,
            'card_template_id' => $template['id'],
            'card_type' => 'single',
        ])->assertCreated();

        $this->deleteJson("/api/v1/card-templates/{$template['id']}")
            ->assertStatus(422);

        $this->assertSame(1, CardTemplate::where('id', $template['id'])->count());
    }

    public function test_staff_cannot_manage_templates(): void
    {
        Storage::fake('public');
        $this->staff();

        $this->postJson('/api/v1/card-templates', [
            'name' => 'Nope',
            'card_type' => 'single',
            'front_design' => $this->fakePng('front.png'),
        ])->assertStatus(403);

        $this->getJson('/api/v1/card-templates')->assertOk();
    }

    public function test_card_index_filters_by_template_and_includes_event(): void
    {
        Storage::fake('public');
        $this->admin();

        $template = $this->createSingleTemplate('Filter By Me');

        $guest = Guest::factory()->create();
        $card = $this->postJson('/api/v1/cards', [
            'guest_id' => $guest->id,
            'card_template_id' => $template['id'],
            'card_type' => 'single',
            'event_name' => 'Sherehe ya Miaka 50',
            'venue' => 'Mlimani City',
        ])->assertCreated()->json('data.card');

        $this->getJson('/api/v1/cards?card_template_id=' . $template['id'])
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $card['id'])
            ->assertJsonPath('data.data.0.card_template.name', 'Filter By Me')
            ->assertJsonPath('data.data.0.event_name', 'Sherehe ya Miaka 50');

        $this->getJson('/api/v1/cards')
            ->assertOk()
            ->assertJsonPath('data.data.0.card_template.name', 'Filter By Me');
    }
}
