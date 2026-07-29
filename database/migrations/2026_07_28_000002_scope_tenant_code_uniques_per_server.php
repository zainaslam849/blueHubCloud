<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-server PBX support — tenant codes are only unique WITHIN a server.
 *
 * Two PBX servers can legitimately expose tenants with identical tenant
 * codes (the client's compound-key test case), so the global uniques on
 * tenant_code must become composite (pbx_provider_id, tenant_code).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_pbx_accounts', function (Blueprint $table) {
            $table->dropUnique('company_pbx_accounts_tenant_code_unique');
            $table->unique(['pbx_provider_id', 'tenant_code'], 'company_pbx_accounts_provider_tenant_code_unique');
        });

        Schema::table('pbxware_tenants', function (Blueprint $table) {
            $table->dropUnique('pbxware_tenants_tenant_code_unique');
            $table->unique(['pbx_provider_id', 'tenant_code'], 'pbxware_tenants_provider_tenant_code_unique');
        });
    }

    public function down(): void
    {
        // Guard: reverting to global uniques fails if cross-server duplicates
        // now exist; surface that clearly instead of a raw SQL error.
        foreach (['company_pbx_accounts', 'pbxware_tenants'] as $tableName) {
            $duplicates = DB::table($tableName)
                ->select('tenant_code', DB::raw('COUNT(*) as occurrences'))
                ->whereNotNull('tenant_code')
                ->groupBy('tenant_code')
                ->having('occurrences', '>', 1)
                ->get();

            if ($duplicates->isNotEmpty()) {
                throw new \RuntimeException(
                    "Cannot restore global tenant_code unique on {$tableName}: cross-server duplicates exist: "
                    . $duplicates->map(fn ($row) => "{$row->tenant_code} x{$row->occurrences}")->implode(', ')
                );
            }
        }

        Schema::table('company_pbx_accounts', function (Blueprint $table) {
            $table->dropUnique('company_pbx_accounts_provider_tenant_code_unique');
            $table->unique('tenant_code', 'company_pbx_accounts_tenant_code_unique');
        });

        Schema::table('pbxware_tenants', function (Blueprint $table) {
            $table->dropUnique('pbxware_tenants_provider_tenant_code_unique');
            $table->unique('tenant_code', 'pbxware_tenants_tenant_code_unique');
        });
    }
};
