<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_normal_sms', function (Blueprint $table) {
            $table->id();
            $table->enum('card_type', ['single', 'double'])->index();
            $table->foreignId('card_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_name')->nullable();
            $table->string('phone');
            $table->text('message');
            $table->string('status')->default('queued')->index();
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('card_shared_work', function (Blueprint $table) {
            $table->id();
            $table->enum('card_type', ['single', 'double'])->index();
            $table->string('title');
            $table->text('description');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_shared_work');
        Schema::dropIfExists('card_normal_sms');
    }
};
