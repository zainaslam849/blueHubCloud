<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Company::updateOrCreate(
            ['name' => 'Acme Corp'],
            [
                'timezone' => 'UTC',
                'status' => 'active',
            ]
        );

        Company::updateOrCreate(
            ['name' => 'Tech Startups Inc'],
            [
                'timezone' => 'America/New_York',
                'status' => 'active',
            ]
        );

        Company::updateOrCreate(
            ['name' => 'Global Services Ltd'],
            [
                'timezone' => 'Europe/London',
                'status' => 'active',
            ]
        );
    }
}
