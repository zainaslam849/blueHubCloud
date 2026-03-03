<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PHASE 7: Add tenant auto-sync fields to company_pbx_accounts
     * 
     * Fields to support the pbx:sync-tenants command:
     * - pbx_type: Provider type (pbxware, 3cx, zoom_phone, etc.)
     * - api_version: API version for provider (v7, v8, etc.)
     * - is_active: Whether this account is actively syncing
     * - last_synced_at: Timestamp of last successful sync
     */
    public function up(): void
    {
        Schema::table('company_pbx_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('company_pbx_accounts', 'pbx_type')) {
                $table->string('pbx_type')->default('pbxware')->after('server_id')->index();
            }
            if (!Schema::hasColumn('company_pbx_accounts', 'api_version')) {
                $table->string('api_version')->default('v7')->after('pbx_type');
            }
            if (!Schema::hasColumn('company_pbx_accounts', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('api_version')->index();
            }
            if (!Schema::hasColumn('company_pbx_accounts', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable()->after('is_active');
            }
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('company_pbx_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('company_pbx_accounts', 'pbx_type')) {
                $table->dropColumn('pbx_type');
            }
            if (Schema::hasColumn('company_pbx_accounts', 'api_version')) {
                $table->dropColumn('api_version');
            }
            if (Schema::hasColumn('company_pbx_accounts', 'is_active')) {
                $table->dropColumn('is_active');
            }
            if (Schema::hasColumn('company_pbx_accounts', 'last_synced_at')) {
                $table->dropColumn('last_synced_at');
            }
        });
    }
};
