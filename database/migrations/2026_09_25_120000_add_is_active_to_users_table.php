<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deactivation flag for users.
 *
 * A user is never hard-deleted from this system. Entry logs point at
 * users.processed_by with nullOnDelete(), so deleting a row would silently
 * strip the "who handled this entry" half of the gate audit trail and leave
 * rows that render as 'Unknown' forever. A flag lets an admin take a person
 * off the floor without erasing the record of what they did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
