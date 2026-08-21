<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->string('card_number')->unique();
            $table->string('qr_token', 64)->unique();
            $table->enum('card_type', ['single', 'double']);
            $table->unsignedTinyInteger('allowed_people');
            $table->unsignedTinyInteger('used_people')->default(0);
            $table->enum('status', ['active', 'used', 'cancelled'])->default('active');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['card_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
