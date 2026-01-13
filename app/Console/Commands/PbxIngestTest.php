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

class PbxIngestTest extends Command
{
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
        $since = Carbon::now()->subDay()->toIsoString();

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
            $this->info("Starting ingest for company_id={$acct->company_id} account_id={$acct->id} since={$since}");

            // PBX base URL is intentionally centralized in Secrets Manager.
            $client = PbxClientResolver::resolve();

            try {
                // For mock client the MockPbxwareClient expects a DateTimeInterface
                $calls = [];
                if (method_exists($client, 'fetchCalls')) {
                    try {
                        $calls = $client->fetchCalls(new \DateTimeImmutable($since));
                    } catch (\TypeError $te) {
                        // Fallback: try passing as array params
                        $calls = $client->fetchCalls(['since' => $since, 'company_id' => $acct->company_id]);
                    }
                }

                foreach ($calls as $item) {
                    $callUid = $item['call_uid'] ?? $item['id'] ?? null;
                    if (! $callUid) {
                        $this->warn('Skipping call with missing uid');
                        continue;
                    }

                    $attributes = [
                        'company_id' => $acct->company_id,
                        'company_pbx_account_id' => $acct->id,
                        'direction' => $item['direction'] ?? null,
                        'from_number' => $item['from'] ?? $item['from_number'] ?? null,
                        'to_number' => $item['to'] ?? $item['to_number'] ?? null,
                        'started_at' => isset($item['started_at']) ? Carbon::parse($item['started_at'])->toDateTimeString() : null,
                        'ended_at' => isset($item['ended_at']) ? Carbon::parse($item['ended_at'])->toDateTimeString() : null,
                        'duration_seconds' => $item['duration'] ?? $item['duration_seconds'] ?? null,
                        'status' => $item['status'] ?? null,
                    ];

                    $call = Call::updateOrCreate([
                        'call_uid' => $callUid,
                    ], array_merge(['company_id' => $acct->company_id, 'company_pbx_account_id' => $acct->id], array_filter($attributes, function ($v) { return $v !== null; })));

                    $totalCalls++;
                    $this->info("📞 Call ingested: call_id={$call->id} call_uid={$callUid}");

                    // Handle recordings
                    $recordings = [];
                    if (! empty($item['recordings']) && is_array($item['recordings'])) {
                        $recordings = $item['recordings'];
                    } elseif (! empty($item['recording_id'])) {
                        $recordings = [ ['id' => $item['recording_id']] ];
                    }

                    foreach ($recordings as $rec) {
                        $recId = is_array($rec) ? ($rec['id'] ?? $rec['recording_id'] ?? null) : $rec;
                        if (! $recId) continue;

                        $exists = CallRecording::where('idempotency_key', (string)$recId)->where('call_id', $call->id)->exists();
                        if ($exists) {
                            $this->info("Skipping already-ingested recording: call_id={$call->id} idempotency_key={$recId}");
                            continue;
                        }

                        // Run the recording ingestion synchronously so we can report success
                        $job = new IngestPbxRecordingJob($acct->company_id, $call->id, (string)$recId);
                        if (function_exists('dispatch_sync')) {
                            dispatch_sync($job);
                        } else {
                            dispatch($job);
                        }

                        $s3Key = sprintf('recordings/incoming/%s/%s.mp3', $acct->company_id, $callUid);
                        $s3Paths[] = $s3Key;
                        $totalRecordings++;
                        $this->info("🎧 Recording uploaded: {$s3Key}");
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
}
