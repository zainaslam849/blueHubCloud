<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Company;
use App\Models\Call;
use App\Jobs\IngestPbxRecordingJob;

class PbxTestRecordingUpload extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pbx:test-recording-upload';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch a local test job that uploads a sample audio file to S3 and creates a call_recordings row';

    public function handle(): int
    {
        $this->info('Preparing local test recording upload...');

        $companyId = 1;

        // Try to use DB to create company/call, but gracefully fall back if DB is inaccessible.
        try {
            // Ensure company id=1 exists; insert directly if missing to guarantee id=1 for local testing.
            $company = Company::find($companyId);
            if (! $company) {
                DB::table('companies')->insert([
                    'id' => $companyId,
                    'name' => 'Local Test Company',
                    'timezone' => 'UTC',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->info('Created test company with id=1');
                Log::info('Created test company for pbx:test-recording-upload');
            } else {
                $this->info('Using existing company id=1');
            }

            // Create a fake call in the DB
            $callUid = (string) Str::uuid();
            $call = Call::create([
                'company_id' => $companyId,
                'company_pbx_account_id' => null,
                'call_uid' => $callUid,
                'direction' => 'inbound',
                'from_number' => '0000000000',
                'to_number' => '1111111111',
                'status' => 'completed',
            ]);

            $this->info('Created test call: id=' . $call->id . ' uid=' . $callUid);
            Log::info('Created test call for pbx:test-recording-upload', ['company_id' => $companyId, 'call_id' => $call->id, 'call_uid' => $callUid]);

            $callIdToUse = $call->id;
        } catch (\Throwable $e) {
            // DB is unavailable (access denied) — fall back to synthetic IDs and continue.
            Log::warning('DB unavailable for pbx:test-recording-upload; falling back to local IDs', ['error' => $e->getMessage()]);
            $this->warn('Database unavailable — proceeding with local-only test identifiers.');

            $callIdToUse = random_int(1_000_000, 9_999_999);
            $callUid = (string) Str::uuid();
            $this->info('Using synthetic test call id=' . $callIdToUse . ' uid=' . $callUid);
        }

        // Dispatch the local-only test job (no DB required to run the job)
        IngestPbxRecordingJob::dispatch($companyId, $callIdToUse, 'test-audio/sample.mp3');

        $this->info('Dispatched IngestPbxRecordingJob for call_id=' . $callIdToUse);
        Log::info('Dispatched IngestPbxRecordingJob (local test)', ['company_id' => $companyId, 'call_id' => $callIdToUse]);

        return 0;
    }
}
