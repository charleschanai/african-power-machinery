<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('entry_logs', function (Blueprint $table) {
            $table->enum('verification_method', ['qr', 'card_number', 'guest_name'])
                ->nullable()
                ->after('result');
            $table->unsignedTinyInteger('number_of_guests')
                ->nullable()
                ->after('verification_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('entry_logs', function (Blueprint $table) {
            $table->dropColumn(['verification_method', 'number_of_guests']);
        });
    }
};
