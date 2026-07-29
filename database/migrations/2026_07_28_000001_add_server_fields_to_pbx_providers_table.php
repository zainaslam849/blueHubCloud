<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-server PBX support.
 *
 * Each pbx_providers row now represents a single PBX SERVER (an API key,
 * possibly against the same hostname as another row but with restricted
 * tenant visibility). provider_type keeps the underlying protocol family
 * expressible (currently always 'pbxware'); secret_name references the AWS
 * Secrets Manager secret holding the server's credentials.
 *
 * The pre-existing seeded 'pbxware' row becomes the legacy/default server
 * pointing at the original global secret; env credential fallback applies
 * only to the row where is_default = true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pbx_providers', function (Blueprint $table) {
            $table->string('provider_type', 50)->default('pbxware')->after('slug');
            $table->string('secret_name')->nullable()->after('provider_type');
            $table->string('base_url')->nullable()->after('secret_name');
            $table->boolean('is_default')->default(false)->after('base_url');
        });

        DB::table('pbx_providers')
            ->where('slug', 'pbxware')
            ->update([
                'secret_name' => 'pbxware/api-credentials',
                'is_default' => true,
            ]);
    }

    public function down(): void
    {
        Schema::table('pbx_providers', function (Blueprint $table) {
            $table->dropColumn(['provider_type', 'secret_name', 'base_url', 'is_default']);
        });
    }
};
