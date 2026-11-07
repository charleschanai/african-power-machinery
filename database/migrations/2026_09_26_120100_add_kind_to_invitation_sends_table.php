<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets invitation_sends hold more than one row per card+channel.
 *
 * The original table had UNIQUE(card_id, channel), which meant a card could
 * only ever have a single SMS row and a single WhatsApp row. A reminder is a
 * second SMS to the same card, so that constraint has to be widened.
 *
 * MySQL will not let you drop an index that a foreign key depends on, so the
 * FK is dropped and re-added inside the migration. The `kind` column is added
 * instead of simply removing uniqueness: the legacy bulk campaign keeps writing
 * `invitation` rows exactly as before, while reminders write `reminder` rows.
 * Existing rows default to `invitation`, so no history changes meaning and no
 * data is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitation_sends', function (Blueprint $table) {
            $table->dropForeign(['card_id']);
            $table->dropUnique(['card_id', 'channel']);

            $table->string('kind')->default('invitation')->after('channel')->index();
        });

        Schema::table('invitation_sends', function (Blueprint $table) {
            $table->foreign('card_id')->references('id')->on('cards')->cascadeOnDelete();
            $table->unique(['card_id', 'channel', 'kind'], 'invitation_sends_card_channel_kind_unique');
        });
    }

    public function down(): void
    {
        // Keep the most recent row per card+channel so the original
        // constraint can be restored without colliding.
        DB::table('invitation_sends')
            ->whereIn('id', function ($query) {
                $query->selectRaw('MAX(id)')
                    ->from('invitation_sends')
                    ->groupBy('card_id', 'channel');
            })
            ->delete();

        Schema::table('invitation_sends', function (Blueprint $table) {
            $table->dropForeign(['card_id']);
            $table->dropUnique('invitation_sends_card_channel_kind_unique');
            $table->dropColumn('kind');
        });

        Schema::table('invitation_sends', function (Blueprint $table) {
            $table->foreign('card_id')->references('id')->on('cards')->cascadeOnDelete();
            $table->unique(['card_id', 'channel']);
        });
    }
};
