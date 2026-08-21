<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->string('phone_e164', 20)->nullable()->after('phone');
            $table->index(['phone_e164']);
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropIndex(['phone_e164']);
            $table->dropColumn('phone_e164');
        });
    }
};
