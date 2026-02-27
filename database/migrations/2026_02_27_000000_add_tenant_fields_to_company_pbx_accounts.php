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
            $table->string('tenant_code')->nullable()->after('server_id')->unique();
            $table->string('package_name')->nullable()->after('tenant_code');
            $table->timestamp('pbx_synced_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_pbx_accounts', function (Blueprint $table) {
            $table->dropUnique(['tenant_code']);
            $table->dropColumn(['tenant_code', 'package_name', 'pbx_synced_at']);
        });
    }
};
