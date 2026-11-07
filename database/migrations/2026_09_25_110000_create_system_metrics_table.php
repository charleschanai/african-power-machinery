<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lightweight time-series store for request and system metrics.
 *
 * Deliberately narrow: one row per completed request that is worth keeping
 * (slow, or an error). Aggregate stats live in the cache, so this table only
 * needs to answer "what went wrong, and when".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('metric', 40)->index();
            $table->string('level', 10)->default('info');
            $table->string('method', 10)->nullable();
            $table->string('route', 191)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedBigInteger('memory_bytes')->default(0);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();

            // The monitoring screens all read a time window newest-first, so
            // one composite index covers both the filter and the sort.
            $table->index(['metric', 'created_at']);
            $table->index(['level', 'created_at']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_metrics');
    }
};
