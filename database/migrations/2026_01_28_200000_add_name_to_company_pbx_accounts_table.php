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
        // name and pbx_name columns already exist in initial create migration, so this is a no-op
        // This migration is kept for historical reasons
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_pbx_accounts', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
