<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the free-text details printed on the card itself.
 *
 * The invitation already stored the structured fields the admin types in
 * (event name, date, time, venue). What it could not store was the wording that
 * is actually printed on the design, such as the couple's names, the ceremony
 * schedule or an RSVP number. That text now lives alongside the structured
 * fields so the invitation message, the reminder and the WhatsApp caption can
 * all repeat the card exactly, instead of the admin retyping it each time.
 *
 * Nullable so existing rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->text('wedding_details')->nullable()->after('venue');
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropColumn('wedding_details');
        });
    }
};
