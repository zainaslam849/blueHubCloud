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
                // 1) Discover which PBXware action actually returns CDR rows.
                if (method_exists($client, 'testAvailableActions')) {
                    $diag = $client->testAvailableActions();
                    $results = is_array($diag) ? ($diag['results'] ?? []) : [];

                    $this->info('Action probe results (limit=1):');
                    foreach ($results as $action => $info) {
                        $status = (string) ($info['status'] ?? 'unknown');
                        $rows = (int) ($info['rows'] ?? 0);
                        $type = (string) ($info['response_type'] ?? 'unknown');
                        $this->line(" - {$action}: {$status} (rows={$rows}, type={$type})");
                    }

                    $selected = null;
                    foreach ($results as $action => $info) {
                        if (($info['status'] ?? null) === 'success' && (int) ($info['rows'] ?? 0) > 0) {
                            $selected = (string) $action;
                            break;
                        }
                    }

                    if (! $selected) {
                        $this->warn('No action returned rows with limit=1; using first empty action as fallback (may still ingest 0).');
                        foreach ($results as $action => $info) {
                            if (($info['status'] ?? null) === 'empty') {
                                $selected = (string) $action;
                                break;
                            }
                        }
                    }

                    if (! $selected) {
                        $this->warn('No usable PBXware CDR action discovered for this account; skipping.');
                        continue;
                    }

                    $this->info('Selected PBXware CDR action: ' . $selected);
                    Log::info('pbx:ingest-test selected CDR action', [
                        'company_id' => $acct->company_id,
                        'company_pbx_account_id' => $acct->id,
                        'action' => $selected,
                    ]);

                    // 2) Run the ingestion job synchronously with that action.
                    $pbxParams = $this->normalizePbxwareQueryParams([
                        'startdate' => $now->copy()->subDay(),
                        'enddate' => $now,
                    ]);

                    Log::info('PBXware CDR query params (excluding apikey)', [
                        'company_id' => $acct->company_id,
                        'company_pbx_account_id' => $acct->id,
                        'params' => $pbxParams,
                    ]);

                    $job = new IngestPbxCallsJob((int) $acct->company_id, (int) $acct->id, $pbxParams, $selected);
                    if (function_exists('dispatch_sync')) {
                        dispatch_sync($job);
                    } else {
                        // Best-effort fallback for older Laravel versions.
                        $job->handle();
                    }

                    $ran++;
                    $this->info("Completed ingest for account_id={$acct->id}");
                    continue;
                }

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

                $this->warn('Client does not support action probing; please update client to use testAvailableActions().');
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
