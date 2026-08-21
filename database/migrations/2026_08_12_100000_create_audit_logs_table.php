<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('email', 255)->nullable()->index();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('user_agent', 500)->nullable();
            $table->string('event', 100)->index();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
