<?php

namespace Tests\Feature;

use App\Enums\WhatsAppSenderType;
use App\Models\Card;
use App\Models\EventDetail;
use App\Models\Guest;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFaq;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppAiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    private function enableApi(): void
    {
        config([
            'services.whatsapp.token' => 'test-token',
            'services.whatsapp.phone_number_id' => '123456789',
            'services.whatsapp.business_account_id' => '987654321',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['wa_id' => '255712345678']],
                'messages' => [['id' => 'wamid.outgoing.123']],
            ], 200),
        ]);
    }

    private function payload(string $body, string $from = '255712345678', string $waId = 'wamid.incoming.1'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'TEST_BA',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '255700000000', 'phone_number_id' => '123456789'],
                        'contacts' => [['profile' => ['name' => 'John Michael'], 'wa_id' => $from]],
                        'messages' => [[
                            'from' => $from,
                            'id' => $waId,
                            'timestamp' => (string) time(),
                            'type' => 'text',
                            'text' => ['body' => $body],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    public function test_webhook_verification_challenge(): void
    {
        WhatsAppSetting::singleton()->update(['webhook_verify_token' => 'secret-token']);

        $this->get('/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=secret-token&hub_challenge=challenge-ok')
            ->assertOk()
            ->assertSee('challenge-ok');

        $this->get('/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=x')
            ->assertStatus(403);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        config(['services.whatsapp.app_secret' => 'app-secret']);

        $this->postJson('/whatsapp/webhook', $this->payload('Habari'), [
            'X-Hub-Signature-256' => 'sha256=wrong-signature',
        ])->assertStatus(403);
    }

    public function test_webhook_accepts_valid_signature(): void
    {
        config(['services.whatsapp.app_secret' => 'app-secret']);
        $this->enableApi();
        Guest::factory()->create(['full_name' => 'John Michael', 'phone' => '255712345678']);

        $signature = 'sha256=' . hash_hmac('sha256', json_encode($this->payload('Habari')), 'app-secret');

        $this->postJson('/whatsapp/webhook', $this->payload('Habari'), [
            'X-Hub-Signature-256' => $signature,
        ])->assertOk()->assertJsonPath('status', 'received');

        $this->assertNotNull(WhatsAppMessage::query()->where('direction', 'outgoing')->first());
    }

    public function test_webhook_receives_greeting_and_ai_responds(): void
    {
        $this->enableApi();
        Guest::factory()->create(['full_name' => 'John Michael', 'phone' => '255712345678']);

        $this->postJson('/whatsapp/webhook', $this->payload('Habari'))
            ->assertOk()
            ->assertJsonPath('status', 'received');

        $conversation = WhatsAppConversation::query()->where('phone', '255712345678')->first();
        $this->assertNotNull($conversation);
        $this->assertSame('John Michael', $conversation->guest_name);
        $this->assertNotNull($conversation->guest_id);

        $this->assertDatabaseHas('whatsapp_messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'incoming',
            'sender_type' => 'guest',
            'body' => 'Habari',
        ]);

        $reply = WhatsAppMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outgoing')
            ->latest('id')
            ->first();

        $this->assertNotNull($reply);
        $this->assertStringContainsString('Welcome', $reply->body);
        $this->assertSame(WhatsAppSenderType::Ai, $reply->sender_type);
    }

    public function test_registered_guest_receives_their_own_ecard(): void
    {
        $this->enableApi();
        $guest = Guest::factory()->create(['full_name' => 'John Michael', 'phone' => '255712345678']);
        $card = Card::factory()->single()->create(['guest_id' => $guest->id]);

        $this->postJson('/whatsapp/webhook', $this->payload('Naomba card yangu', waId: 'wamid.incoming.card'))
            ->assertOk();

        $reply = WhatsAppMessage::query()
            ->where('direction', 'outgoing')
            ->latest('id')
            ->first();

        $this->assertNotNull($reply);
        $this->assertStringContainsString('John Michael', $reply->body);
        $this->assertStringContainsString($card->card_number, $reply->body);
        $this->assertStringContainsString('/e-card/' . $card->qr_token, $reply->body);
    }

    public function test_unregistered_number_never_receives_guest_data(): void
    {
        $this->enableApi();
        Guest::factory()->create(['full_name' => 'Secret Person', 'phone' => '255700000000']);

        $this->postJson('/whatsapp/webhook', $this->payload('Naomba card yangu', from: '255799999999'))
            ->assertOk();

        $reply = WhatsAppMessage::query()
            ->where('direction', 'outgoing')
            ->latest('id')
            ->first();

        $this->assertNotNull($reply);
        $this->assertStringNotContainsString('Secret Person', $reply->body);
        $this->assertStringNotContainsString('wamid', $reply->body);
        $this->assertStringContainsString('could not find an E-Card', $reply->body);
    }

    public function test_ai_disabled_receives_disabled_message(): void
    {
        $this->enableApi();
        WhatsAppSetting::singleton()->update(['ai_enabled' => false]);

        $this->postJson('/whatsapp/webhook', $this->payload('Habari'))
            ->assertOk();

        $reply = WhatsAppMessage::query()
            ->where('direction', 'outgoing')
            ->latest('id')
            ->first();

        $this->assertNotNull($reply);
        $this->assertStringContainsString('AI assistant is currently unavailable', $reply->body);
    }

    public function test_manual_and_blocked_conversations_are_not_auto_answered(): void
    {
        $this->enableApi();

        $conversation = WhatsAppConversation::query()->create([
            'phone' => '255755555555',
            'status' => 'manual',
        ]);

        $this->postJson('/whatsapp/webhook', $this->payload('Habari', from: '255755555555'))
            ->assertOk();

        $this->assertSame(0, WhatsAppMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outgoing')
            ->count());
    }

    public function test_greeting_sends_interactive_menu(): void
    {
        $this->enableApi();

        $this->postJson('/whatsapp/webhook', $this->payload('Habari'))
            ->assertOk();

        Http::assertSent(fn ($request) => ($request->data()['type'] ?? null) === 'interactive');
    }

    public function test_button_tap_returns_card_details(): void
    {
        $this->enableApi();
        $guest = Guest::factory()->create(['full_name' => 'John Michael', 'phone' => '255712345678']);
        $card = Card::factory()->single()->create(['guest_id' => $guest->id]);

        $this->postJson('/whatsapp/webhook', $this->payload('E-Card yangu'))
            ->assertOk();

        $reply = WhatsAppMessage::query()
            ->where('direction', 'outgoing')
            ->latest('id')
            ->first();

        $this->assertNotNull($reply);
        $this->assertStringContainsString($card->card_number, $reply->body);
    }

    public function test_admin_settings_event_details_and_faqs(): void
    {
        $this->admin();

        $this->putJson('/api/v1/whatsapp/settings', [
            'whatsapp_business_phone' => '+255 712 345 678',
            'organizer_name' => 'Amina',
            'organizer_phone' => '+255 711 000 000',
            'default_language' => 'sw',
            'ai_enabled' => true,
        ])->assertOk()->assertJsonPath('data.whatsapp_business_phone', '+255 712 345 678');

        $this->putJson('/api/v1/whatsapp/event-details', [
            'event_name' => 'Wedding Ceremony',
            'event_date' => '2026-12-20',
            'event_time' => '10:00 PM',
            'venue' => 'Golden Hall, Dar es Salaam',
            'dress_code' => 'Formal Wear',
        ])->assertOk()->assertJsonPath('data.venue', 'Golden Hall, Dar es Salaam');

        $faq = $this->postJson('/api/v1/whatsapp/faqs', [
            'question' => 'Sherehe itafanyika wapi?',
            'answer' => 'Golden Hall, Dar es Salaam.',
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/whatsapp/faqs/{$faq['id']}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->putJson("/api/v1/whatsapp/faqs/{$faq['id']}", [
            'question' => 'Sherehe itafanyika wapi?',
            'answer' => 'Golden Hall, Dar es Salaam.',
            'status' => 'active',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $this->getJson('/api/v1/whatsapp/status')
            ->assertOk()
            ->assertJsonPath('data.settings.whatsapp_business_phone', '+255 712 345 678')
            ->assertJsonPath('data.stats.active_faqs', 1);
    }

    public function test_admin_conversation_reply_takeover_and_block(): void
    {
        $this->enableApi();
        $this->admin();

        $conversation = WhatsAppConversation::query()->create([
            'phone' => '255712345678',
            'guest_name' => 'John Michael',
            'status' => 'ai',
        ]);

        WhatsAppMessage::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'incoming',
            'sender_type' => 'guest',
            'message_type' => 'text',
            'body' => 'Habari',
        ]);

        $this->postJson("/api/v1/whatsapp/conversations/{$conversation->id}/take-over")
            ->assertOk()
            ->assertJsonPath('data.status', 'manual');

        $this->postJson("/api/v1/whatsapp/conversations/{$conversation->id}/reply", [
            'message' => 'Karibu! Tunakusubiri.',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('whatsapp_messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'outgoing',
            'sender_type' => 'admin',
            'body' => 'Karibu! Tunakusubiri.',
        ]);

        $this->getJson("/api/v1/whatsapp/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.conversation.status', 'manual')
            ->assertJsonCount(2, 'data.messages');

        $this->postJson("/api/v1/whatsapp/conversations/{$conversation->id}/release")
            ->assertOk()
            ->assertJsonPath('data.status', 'ai');

        $this->postJson("/api/v1/whatsapp/conversations/{$conversation->id}/block")
            ->assertOk()
            ->assertJsonPath('data.status', 'blocked');

        $this->postJson("/api/v1/whatsapp/conversations/{$conversation->id}/unblock")
            ->assertOk()
            ->assertJsonPath('data.status', 'ai');

        $this->getJson('/api/v1/whatsapp/conversations?q=John')
            ->assertOk()
            ->assertJsonPath('data.data.0.guest_name', 'John Michael');
    }

    public function test_public_whatsapp_number_endpoint(): void
    {
        WhatsAppSetting::singleton()->update(['whatsapp_business_phone' => '+255 712 345 678']);
        EventDetail::singleton()->update(['event_name' => 'Wedding Ceremony']);

        $this->getJson('/api/v1/public/whatsapp-number')
            ->assertOk()
            ->assertJsonPath('data.whatsapp_business_phone', '+255 712 345 678')
            ->assertJsonPath('data.wa_me_link', 'https://wa.me/255712345678')
            ->assertJsonPath('data.event_name', 'Wedding Ceremony');
    }

    public function test_faq_answer_is_used_by_the_ai(): void
    {
        $this->enableApi();
        WhatsAppFaq::query()->create([
            'question' => 'Sherehe itafanyika wapi?',
            'answer' => 'Golden Hall, Dar es Salaam. Karibu sana!',
            'status' => 'active',
        ]);

        $this->postJson('/whatsapp/webhook', $this->payload('Sherehe itafanyika wapi?'))
            ->assertOk();

        $reply = WhatsAppMessage::query()
            ->where('direction', 'outgoing')
            ->latest('id')
            ->first();

        $this->assertNotNull($reply);
        $this->assertStringContainsString('Golden Hall, Dar es Salaam', $reply->body);
        $this->assertStringContainsString('Karibu sana', $reply->body);
    }

    public function test_ai_never_invents_missing_info(): void
    {
        $this->enableApi();

        $this->postJson('/whatsapp/webhook', $this->payload('Dress code ni ipi?'))
            ->assertOk();

        $reply = WhatsAppMessage::query()
            ->where('direction', 'outgoing')
            ->latest('id')
            ->first();

        $this->assertNotNull($reply);
        $this->assertStringContainsString('do not have accurate information', $reply->body);
    }
}
