<?php

namespace App\Console\Commands;

use App\Jobs\IngestPbxCallsJob;
use App\Jobs\IngestPbxRecordingJob;
use App\Models\Call;
use App\Models\CallRecording;
use App\Models\CompanyPbxAccount;
use App\Services\Pbx\PbxClientResolver;
use App\Exceptions\PbxwareClientException;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PbxIngestTest extends Command
{
    private ?int $currentCompanyId = null;
    private ?int $currentCompanyPbxAccountId = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pbx:ingest-test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run IngestPbxCallsJob for the last 24 hours synchronously for all PBX accounts';

    public function handle(): int
    {
        $now = Carbon::now();

        // Determine mock mode strictly from environment variable only.
        $mock = filter_var(env('PBXWARE_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);
        if ($mock) {
            $this->info('🟢 Running in mock PBX mode per PBXWARE_MOCK_MODE env var (no credentials required).');
        } else {
            // Do not abort or block execution based on host environment.
            // Log a warning if real PBX mode is used outside EC2, but continue.
            $this->info('🔵 Running in REAL PBX mode per PBXWARE_MOCK_MODE env var.');
            $metadataUrl = 'http://169.254.169.254/latest/meta-data/public-ipv4';
            $opts = ['http' => ['method' => 'GET', 'timeout' => 2]];
            $context = stream_context_create($opts);

            $publicIp = @file_get_contents($metadataUrl, false, $context);
            if ($publicIp === false || empty($publicIp)) {
                $this->warn('PBXWARE_MOCK_MODE is disabled but instance metadata is not reachable. Continuing anyway as behaviour is controlled by .env.');
            } else {
                $publicIp = trim($publicIp);
                $this->info('✅ Detected EC2 public NAT IP: ' . $publicIp);
            }
        }

        $accounts = CompanyPbxAccount::all();
        if ($accounts->isEmpty()) {
            $this->info('No PBX accounts found.');
            return 0;
        }
        // Stats
        $totalCalls = 0;
        $totalRecordings = 0;
        $s3Paths = [];

        foreach ($accounts as $acct) {
            $this->info("Starting ingest for company_id={$acct->company_id} account_id={$acct->id}");

            $this->currentCompanyId = (int) $acct->company_id;
            $this->currentCompanyPbxAccountId = (int) $acct->id;

            // PBX base URL is intentionally centralized in Secrets Manager.
            $client = PbxClientResolver::resolve();

            try {
                // PBXware query parameters must be unix timestamps.
                // For first-time ingestion (no cursor), do NOT send `since` at all.
                // Default to last 24 hours.
                $pbxParams = $this->normalizePbxwareQueryParams([
                    'startdate' => $now->copy()->subDay(),
                    'enddate' => $now,
                ]);

                Log::info('PBXware CDR query params (excluding apikey)', [
                    'company_id' => $acct->company_id,
                    'company_pbx_account_id' => $acct->id,
                    'params' => $pbxParams,
                ]);

                $cdr = $client->fetchCdrList($pbxParams);
                $rows = is_array($cdr) ? ($cdr['rows'] ?? []) : [];

                foreach ($rows as $row) {
                    $mapped = $this->mapPbxwareCdr($row);
                    $callUid = $mapped['call_uid'] ?? null;
                    if (empty($callUid)) {
                        continue;
                    }

                    $attributes = [
                        'company_id' => $acct->company_id,
                        'company_pbx_account_id' => $acct->id,
                        'started_at' => $mapped['started_at'] ?? null,
                    ];

                    $call = Call::firstOrCreate(
                        ['call_uid' => $callUid],
                        array_filter($attributes, function ($v) { return $v !== null; })
                    );

                    if (! $call->wasRecentlyCreated) {
                        $this->info('Skipping duplicate call_uid: ' . $callUid);
                        Log::info('Skipping duplicate call_uid', ['call_uid' => $callUid, 'company_id' => $acct->company_id, 'company_pbx_account_id' => $acct->id]);
                    }

                    if ($call->wasRecentlyCreated) {
                        $totalCalls++;
                        $this->info("📞 Call ingested: call_id={$call->id} call_uid={$callUid}");
                    }

                    // Fetch recordings only when recording_available === true and not already fetched.
                    if (! empty($mapped['recording_available']) && ! empty($mapped['recording_path'])) {
                        $recordingPath = (string) $mapped['recording_path'];
                        $exists = CallRecording::where('idempotency_key', $recordingPath)
                            ->where('call_id', $call->id)
                            ->exists();

                        if ($exists) {
                            $this->info("Recording already fetched: call_id={$call->id} idempotency_key={$recordingPath}");
                            Log::info('Recording already fetched', ['call_id' => $call->id, 'call_uid' => $callUid, 'idempotency_key' => $recordingPath]);
                        } else {
                            // Run the recording ingestion synchronously so we can report success
                            $job = new IngestPbxRecordingJob($acct->company_id, $call->id, $recordingPath);
                            if (function_exists('dispatch_sync')) {
                                dispatch_sync($job);
                            } else {
                                dispatch($job);
                            }

                            $s3Key = sprintf('recordings/incoming/%s/%s.mp3', $acct->company_id, $callUid);
                            $s3Paths[] = $s3Key;

                            $totalRecordings++;
                            $this->info("🎧 Recording job dispatched: call_id={$call->id} recording_path={$recordingPath} -> s3={$s3Key}");
                        }
                    }
                }

                $this->info("Completed ingest for account_id={$acct->id}");
            } catch (PbxwareClientException $e) {
                $this->error("PBX API error for account_id={$acct->id}: " . $e->getMessage());
            } catch (\Throwable $e) {
                $this->error("Error during ingest for account_id={$acct->id}: " . $e->getMessage());
            }
        }

        // Verification summary
        $this->line('');
        $this->info('=== Ingest Test Summary ===');
        $this->info('Total calls ingested: ' . $totalCalls);
        $this->info('Total recordings uploaded: ' . $totalRecordings);
        $this->info('S3 paths:');
        foreach (array_unique($s3Paths) as $p) {
            $this->line(' - ' . $p);
        }
        $lambdaExpected = $totalRecordings > 0 ? 'yes' : 'no';
        $this->info('Lambda trigger expected: ' . $lambdaExpected);

        $this->info('All ingests completed.');
        return 0;
    }

    private function mapPbxwareCdr(array $row): array
    {
        // PBXware CDR CSV mapping using fixed column indexes.
        // PBXware uses Unique ID only.
        $uniqueId = isset($row[7]) ? trim((string) $row[7]) : '';
        if ($uniqueId === '') {
            $this->warn('Skipping PBX call: no unique identifier present');
            Log::warning('Skipping PBX call: no unique identifier present', [
                'company_id' => $this->currentCompanyId,
                'company_pbx_account_id' => $this->currentCompanyPbxAccountId,
            ]);
            Log::debug('PBX call missing Unique ID at column index 7', [
                'row_len' => count($row),
                'available_indexes' => array_keys($row),
                'company_id' => $this->currentCompanyId,
                'company_pbx_account_id' => $this->currentCompanyPbxAccountId,
            ]);
            return ['call_uid' => null];
        }

        $startedAt = null;
        if (isset($row[2]) && $row[2] !== '' && $row[2] !== null) {
            $ts = $row[2];
            try {
                $startedAt = is_numeric($ts)
                    ? Carbon::createFromTimestamp((int) $ts)->toDateTimeString()
                    : Carbon::parse((string) $ts)->toDateTimeString();
            } catch (\Throwable $e) {
                $startedAt = null;
            }
        }

        $recordingPath = isset($row[8]) ? (string) $row[8] : '';
        $recordingAvailable = isset($row[9]) && ((string) $row[9] === 'True');

        return [
            'call_uid' => $uniqueId,
            'started_at' => $startedAt,
            'recording_path' => $recordingPath,
            'recording_available' => $recordingAvailable,
        ];
    }

    private function normalizePbxwareQueryParams(array $params): array
    {
        // PBXware expects query params like startdate/enddate/limit.
        // Convert any DateTime/Carbon values to unix timestamps.
        $out = [];
        foreach ($params as $key => $value) {
            if (! in_array($key, ['startdate', 'enddate', 'limit'], true)) {
                continue;
            }

            if ($value instanceof \DateTimeInterface) {
                $out[$key] = $value->getTimestamp();
                continue;
            }

            if (is_string($value) && $value !== '' && ! is_numeric($value)) {
                try {
                    $out[$key] = Carbon::parse($value)->timestamp;
                    continue;
                } catch (\Throwable $e) {
                    // Ignore invalid strings
                    continue;
                }
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
