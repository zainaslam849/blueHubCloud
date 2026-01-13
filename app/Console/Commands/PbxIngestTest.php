<?php

namespace App\Console\Commands;

use App\Jobs\IngestPbxCallsJob;
use App\Models\CompanyPbxAccount;
use App\Models\Call;
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
                // Build mandatory CDR params.
                $nowEpoch = time();
                $last = Call::where('company_id', $acct->company_id)
                    ->where('company_pbx_account_id', $acct->id)
                    ->whereNotNull('started_at')
                    ->max('started_at');

                $lastEpoch = null;
                if (! empty($last)) {
                    try {
                        $lastEpoch = Carbon::parse((string) $last)->timestamp;
                    } catch (\Throwable $e) {
                        $lastEpoch = null;
                    }
                }

                $fromEpoch = $lastEpoch ?? ($nowEpoch - 86400);

                $baseParams = $this->normalizePbxwareQueryParams([
                    'from' => $fromEpoch,
                    'to' => $nowEpoch,
                    'export' => 1,
                ]);

                // 1) Probe CDR actions with mandatory params.
                $cdrActions = [
                    'pbxware.cdr.list',
                    'pbxware.cdr.report',
                    'pbxware.cdr.export',
                ];

                $this->info('Action probe results (limit=1):');
                $probeResults = [];
                foreach ($cdrActions as $action) {
                    try {
                        $probeParams = $baseParams;
                        $probeParams['limit'] = 1;
                        $probeParams = $this->normalizePbxwareQueryParams($probeParams);

                        Log::info('PBXware CDR fetch params', [
                            'company_id' => $acct->company_id,
                            'company_pbx_account_id' => $acct->id,
                            'action' => $action,
                            'from' => $probeParams['from'] ?? null,
                            'to' => $probeParams['to'] ?? null,
                            'export' => $probeParams['export'] ?? null,
                        ]);

                        $res = $client->fetchAction($action, $probeParams);
                        $rows = (is_array($res) && isset($res['rows']) && is_array($res['rows']))
                            ? count($res['rows'])
                            : (is_array($res) && array_values($res) === $res ? count($res) : 0);
                        $type = (is_array($res) && isset($res['rows'])) ? 'csv' : (is_string($res) ? 'empty' : 'json');

                        $probeResults[$action] = ['rows' => $rows, 'type' => $type];
                        $this->line(" - {$action}: rows={$rows} (type={$type})");
                    } catch (PbxwareClientException $e) {
                        $probeResults[$action] = ['rows' => 0, 'type' => 'error'];
                        $this->line(" - {$action}: error");
                    }
                }

                $selected = null;
                foreach ($cdrActions as $action) {
                    if (($probeResults[$action]['rows'] ?? 0) > 0) {
                        $selected = $action;
                        break;
                    }
                }

                if (! $selected) {
                    $this->warn('No CDR action returned rows with limit=1; using pbxware.cdr.list as fallback.');
                    $selected = 'pbxware.cdr.list';
                }

                $this->info('Selected PBXware CDR action: ' . $selected);
                Log::info('pbx:ingest-test selected CDR action', [
                    'company_id' => $acct->company_id,
                    'company_pbx_account_id' => $acct->id,
                    'action' => $selected,
                ]);

                // 2) Run the ingestion job synchronously with that action and params.
                $job = new IngestPbxCallsJob((int) $acct->company_id, (int) $acct->id, $baseParams, $selected);
                if (function_exists('dispatch_sync')) {
                    dispatch_sync($job);
                } else {
                    $job->handle();
                }

                $ran++;
                $this->info("Completed ingest for account_id={$acct->id}");
                continue;

                $this->warn('Client does not support fetchAction(); cannot probe PBXware actions.');
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
        // PBXware expects query params like from/to/export/limit.
        // Convert any DateTime/Carbon values to unix timestamps.
        $out = [];
        foreach ($params as $key => $value) {
            if (! in_array($key, ['from', 'to', 'export', 'limit', 'startdate', 'enddate'], true)) {
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
