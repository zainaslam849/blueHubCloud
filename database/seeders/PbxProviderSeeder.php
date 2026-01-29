<?php

namespace Database\Seeders;

use App\Models\PbxProvider;
use Illuminate\Database\Seeder;

class PbxProviderSeeder extends Seeder
{
    /**
     * Seed a default PBX provider.
     */
    public function run(): void
    {
        PbxProvider::updateOrCreate(
            ['slug' => 'bhubcomms'],
            [
                'name' => 'BHubcomms',
                'status' => 'active',
            ]
        );
    }
}
