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
        Schema::table('cards', function (Blueprint $table) {
            $table->foreignId('card_template_id')
                ->nullable()
                ->after('guest_id')
                ->constrained('card_templates')
                ->nullOnDelete();

            $table->string('event_name')->nullable()->after('card_template_id');
            $table->date('event_date')->nullable()->after('event_name');
            $table->time('event_time')->nullable()->after('event_date');
            $table->string('venue')->nullable()->after('event_time');
            $table->string('table_number')->nullable()->after('venue');
            $table->string('seat_number')->nullable()->after('table_number');
            $table->text('special_message')->nullable()->after('seat_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('card_template_id');
            $table->dropColumn([
                'event_name',
                'event_date',
                'event_time',
                'venue',
                'table_number',
                'seat_number',
                'special_message',
            ]);
        });
    }
};
