<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            $table->string('phone_e164', 20);
            $table->string('guest_name');
            $table->text('message_sent');
            $table->string('status')->default('sent');
            $table->string('error')->nullable();
            $table->date('reminder_date');
            $table->unsignedSmallInteger('week_number');
            $table->unsignedSmallInteger('year');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['reminder_date', 'card_id']);
            $table->index(['year', 'week_number', 'card_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_reminder_logs');
    }
};
