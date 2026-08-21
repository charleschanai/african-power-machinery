<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_settings', function (Blueprint $table) {
            $table->id();
            $table->string('whatsapp_business_phone')->nullable();
            $table->string('phone_number_id')->nullable();
            $table->string('business_account_id')->nullable();
            $table->string('webhook_verify_token')->nullable();
            $table->boolean('ai_enabled')->default(true);
            $table->string('organizer_name')->nullable();
            $table->string('organizer_phone')->nullable();
            $table->string('default_language', 5)->default('en');
            $table->text('ai_disabled_message')->nullable();
            $table->text('unknown_guest_message')->nullable();
            $table->text('no_info_message')->nullable();
            $table->timestamps();
        });

        Schema::create('event_details', function (Blueprint $table) {
            $table->id();
            $table->string('event_name')->nullable();
            $table->text('event_description')->nullable();
            $table->date('event_date')->nullable();
            $table->string('event_time')->nullable();
            $table->string('venue')->nullable();
            $table->string('venue_address')->nullable();
            $table->string('google_maps_url')->nullable();
            $table->string('dress_code')->nullable();
            $table->text('parking_information')->nullable();
            $table->text('accommodation_information')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('contact_person')->nullable();
            $table->text('event_program')->nullable();
            $table->string('rsvp_link')->nullable();
            $table->text('rsvp_information')->nullable();
            $table->text('gift_information')->nullable();
            $table->text('important_instructions')->nullable();
            $table->text('other_information')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_faqs', function (Blueprint $table) {
            $table->id();
            $table->string('question');
            $table->text('answer');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['status']);
        });

        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->foreignId('guest_id')->nullable()->constrained('guests')->nullOnDelete();
            $table->string('guest_name')->nullable();
            $table->string('status', 20)->default('ai');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['last_message_at']);
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->string('whatsapp_message_id')->nullable();
            $table->string('direction', 10);
            $table->string('sender_type', 10);
            $table->string('message_type', 20)->default('text');
            $table->text('body');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->unique(['whatsapp_message_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');
        Schema::dropIfExists('whatsapp_faqs');
        Schema::dropIfExists('event_details');
        Schema::dropIfExists('whatsapp_settings');
    }
};
