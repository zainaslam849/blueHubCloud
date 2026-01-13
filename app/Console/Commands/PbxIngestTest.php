<?php

namespace App\Console\Commands;

use App\Jobs\IngestPbxCallsJob;
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
        $ran = 0;

        foreach ($accounts as $acct) {
            $this->info("Starting ingest for company_id={$acct->company_id} account_id={$acct->id}");

            $this->currentCompanyId = (int) $acct->company_id;
            $this->currentCompanyPbxAccountId = (int) $acct->id;

            // PBX base URL is intentionally centralized in Secrets Manager.
            $client = PbxClientResolver::resolve();

            try {
                // Official PBXware flow is implemented in IngestPbxCallsJob:
                // - pbxware.cdr.export for discovery (CSV)
                // - pbxware.cdr.download for recording stream
                // Run synchronously so output reflects a single run.
                $job = new IngestPbxCallsJob((int) $acct->company_id, (int) $acct->id);
                if (function_exists('dispatch_sync')) {
                    dispatch_sync($job);
                } else {
                    $job->handle();
                }

                $ran++;
                $this->info("Completed ingest for account_id={$acct->id}");
                continue;

                // no-op
            } catch (PbxwareClientException $e) {
                $this->error("PBX API error for account_id={$acct->id}: " . $e->getMessage());
            } catch (\Throwable $e) {
                $this->error("Error during ingest for account_id={$acct->id}: " . $e->getMessage());
            }
        }

        // Verification summary
        $this->line('');
        $this->info('=== Ingest Test Summary ===');
        $this->info('Accounts processed: ' . $ran);

        $this->info('All ingests completed.');
        return 0;
    }

    // Parameter normalization is handled inside IngestPbxCallsJob.
}
