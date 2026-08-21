<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->string('status')->default('queued')->index();
            $table->string('phone_e164')->nullable();
            $table->string('provider_message_id')->nullable()->index();
            $table->text('error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['card_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_sends');
    }
};
