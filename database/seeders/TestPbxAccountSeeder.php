<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TestPbxAccountSeeder extends Seeder
{
    public function run()
    {
        // Ensure PBX provider exists
        $provider = DB::table('pbx_providers')->where('slug', 'pbxware')->first();
        if (! $provider) {
            $providerId = DB::table('pbx_providers')->insertGetId([
                'name' => 'PBXware',
                'slug' => 'pbxware',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $providerId = $provider->id;
        }

        // Create a minimal company
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Test Company ' . Str::random(6),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert a PBX account for that company. PBX base URL is intentionally
        // centralized in Secrets Manager (pbxware/api-credentials).
        DB::table('company_pbx_accounts')->insert([
            'company_id' => $companyId,
            'pbx_provider_id' => $providerId,
            'name' => 'Test PBX Account',
            'pbx_name' => 'pbxware-test',
            'server_id' => 'test-server-1',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command->info("Inserted test company_id={$companyId} and PBX account (provider_id={$providerId}).");
    }
}
