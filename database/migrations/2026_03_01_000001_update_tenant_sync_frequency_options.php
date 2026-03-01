<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenant_sync_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('interval_minutes')->default(5)->after('frequency');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE tenant_sync_settings MODIFY frequency ENUM('every_minutes','hourly','daily','weekly') NOT NULL DEFAULT 'daily'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE tenant_sync_settings MODIFY frequency ENUM('hourly','daily','weekly') NOT NULL DEFAULT 'daily'");
        }

        Schema::table('tenant_sync_settings', function (Blueprint $table) {
            $table->dropColumn('interval_minutes');
        });
    }
};
