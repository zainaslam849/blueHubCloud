<?php

namespace Database\Seeders;

use App\Models\Call;
use App\Models\Company;
use App\Models\CompanyPbxAccount;
use App\Models\CallCategory;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class TestCallsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get or create test company
        $company = Company::firstOrCreate(
            ['name' => 'Test Company'],
            [
                'status' => 'active',
                'timezone' => 'Australia/Sydney',
            ]
        );

        // Get or create test PBX account
        $pbxAccount = CompanyPbxAccount::firstOrCreate(
            ['company_id' => $company->id, 'pbx_name' => 'Test PBX'],
            [
                'pbx_provider_id' => 1,
                'server_id' => 'test-server-1',
            ]
        );

        // Get categories
        $categories = CallCategory::all();
        $categoryIds = $categories->pluck('id')->toArray();

        // Sample durations to showcase formatting (seconds)
        $durations = [
            45,           // 0:00:45
            120,          // 0:02:00
            185,          // 0:03:05
            3661,         // 1:01:01
            7325,         // 2:02:05
            120,          // 0:02:00
            456,          // 0:07:36
            1800,         // 0:30:00
            0,            // No duration
        ];

        $statuses = ['answered', 'missed', 'unknown'];
        $sources = ['ai', 'manual', 'default'];

        foreach ($durations as $index => $duration) {
            $createdAt = Carbon::now()->subDays(rand(0, 30))->subHours(rand(0, 23));

            Call::firstOrCreate(
                [
                    'company_pbx_account_id' => $pbxAccount->id,
                    'server_id' => 'test-server-1',
                    'pbx_unique_id' => 'test-call-' . ($index + 1),
                ],
                [
                    'company_id' => $company->id,
                    'from' => '+61' . rand(200000000, 999999999),
                    'to' => '+61' . rand(200000000, 999999999),
                    'direction' => rand(0, 1) ? 'inbound' : 'outbound',
                    'status' => $statuses[array_rand($statuses)],
                    'started_at' => $createdAt,
                    'ended_at' => $createdAt->clone()->addSeconds($duration),
                    'duration_seconds' => $duration,
                    'has_transcription' => rand(0, 1) === 1,
                    'category_id' => ! empty($categoryIds) ? $categoryIds[array_rand($categoryIds)] : null,
                    'category_source' => $sources[array_rand($sources)],
                    'category_confidence' => rand(60, 100) / 100,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]
            );
        }

        $this->command->info('Test calls seeded successfully!');
    }
}
