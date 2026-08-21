<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entry_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('entry_number')->default(1);
            $table->enum('result', ['allowed', 'denied', 'already_used', 'cancelled', 'expired', 'invalid']);
            $table->timestamp('scanned_at')->useCurrent();
            $table->string('gate_name')->nullable();
            $table->string('device_name')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['result']);
            $table->index(['scanned_at']);
            $table->index(['card_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_logs');
    }
};
