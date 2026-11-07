<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reusable invitation record.
 *
 * Everything the admin types once lives here: guest, phone number, event
 * details, the Canva card, the invitation message and the reminder message.
 * Sending an SMS, sending a reminder and sharing the card on WhatsApp all
 * read from this one row, so nothing has to be typed twice.
 *
 * The delivery log (invitation_sends) is deliberately left alone — it records
 * provider-level results for the legacy bulk campaign and is not the source
 * of truth for this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();

            // Guest -> Invitation -> Card, as requested.
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->nullable()->constrained()->nullOnDelete();

            // Phone is snapshotted so history survives a later guest edit.
            $table->string('phone');
            $table->string('phone_e164')->nullable()->index();

            // Event details, snapshotted from the card / EventDetail singleton.
            $table->string('event_name')->nullable();
            $table->date('event_date')->nullable();
            $table->string('event_time')->nullable();
            $table->string('venue')->nullable();

            // The reusable content.
            $table->text('message');
            $table->text('reminder_message')->nullable();

            $table->string('status')->default('pending')->index();
            $table->text('last_error')->nullable();

            // Send status tracking.
            $table->timestamp('last_sms_sent_at')->nullable();
            $table->timestamp('last_reminder_sent_at')->nullable();
            $table->timestamp('last_whatsapp_shared_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedInteger('reminders_count')->default(0);

            $table->timestamps();

            // One invitation per card for a given guest. Nullable card_id means
            // a guest can keep a card-less draft alongside their real ones.
            $table->unique(['guest_id', 'card_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
