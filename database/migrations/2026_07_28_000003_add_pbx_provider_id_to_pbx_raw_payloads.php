<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-server PBX support — attribute raw payloads to a specific server.
 *
 * The 'provider' string slug is ambiguous once several servers share the
 * pbxware type; the FK makes the audit trail unambiguous. Existing rows are
 * backfilled to the default (legacy) server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pbx_raw_payloads', function (Blueprint $table) {
            $table->foreignId('pbx_provider_id')
                ->nullable()
                ->after('provider')
                ->constrained('pbx_providers')
                ->nullOnDelete();
            $table->index('pbx_provider_id');
        });

        $defaultId = DB::table('pbx_providers')->where('is_default', true)->value('id');

        if ($defaultId !== null) {
            DB::table('pbx_raw_payloads')
                ->whereNull('pbx_provider_id')
                ->update(['pbx_provider_id' => $defaultId]);
        }
    }

    public function down(): void
    {
        Schema::table('pbx_raw_payloads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pbx_provider_id');
        });
    }
};
