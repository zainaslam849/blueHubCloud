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
        Schema::table('company_pbx_accounts', function (Blueprint $table) {
            // Add name column if it doesn't exist
            // Used to display a friendly account name (defaults to pbx_name if not set)
            if (!Schema::hasColumn('company_pbx_accounts', 'name')) {
                $table->string('name')->nullable()->after('id');
            }
        });

        // Populate name column with pbx_name for existing records
        \DB::statement("UPDATE company_pbx_accounts SET name = pbx_name WHERE name IS NULL");
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
