<?php

namespace App\Console\Commands;

use App\Jobs\IngestPbxCallsJob;
use App\Models\CompanyPbxAccount;
use Illuminate\Console\Command;

class PbxIngestTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pbx:ingest-test {--company_id= : Company ID (optional)} {--account_id= : Company PBX account ID (optional)} {--from= : Start datetime (optional; parseable by Carbon)} {--to= : End datetime (optional; parseable by Carbon)} {--server_id= : Persist PBXware server ID to company_pbx_accounts.server_id before ingesting} {--list_servers : (Disabled) Tenant discovery is not permitted for tenant-scoped API keys} {--mock : Force PBXWARE_MOCK_MODE=true for this run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run PBXware ingestion for a given date range (CDR via pbxware.cdr.download; transcription via pbxware.transcription.get)';

    public function handle(): int
    {
        if ($this->option('mock')) {
            config(['pbx.mode' => 'mock']);
            $this->info("Running with pbx.mode=mock (forced by --mock).");
        }

        $accountQuery = CompanyPbxAccount::query();
        if ($this->option('company_id')) {
            $accountQuery->where('company_id', (int) $this->option('company_id'));
        }
        if ($this->option('account_id')) {
            $accountQuery->where('id', (int) $this->option('account_id'));
        }

        $acct = $accountQuery->first();
        if (! $acct) {
            $this->error('No matching PBX account found.');
            return self::FAILURE;
        }

        if ($this->option('list_servers')) {
            $this->error('Tenant discovery is disabled: PBXware API keys are tenant-scoped and pbxware.tenant.list is not permitted. Configure company_pbx_accounts.server_id instead.');
            return self::FAILURE;
        }

        $serverIdOpt = $this->option('server_id');
        if (is_string($serverIdOpt) && trim($serverIdOpt) !== '') {
            $acct->server_id = trim($serverIdOpt);
            $acct->save();
            $this->info('Saved server_id on PBX account: ' . $acct->server_id);
        }

        if (! is_string($acct->server_id) || trim($acct->server_id) === '') {
            $this->error('PBX server_id must be configured for this account');
            return self::FAILURE;
        }

        $params = array_filter([
            'from' => $this->option('from'),
            'to' => $this->option('to'),
        ], function ($v) {
            return $v !== null && $v !== '';
        });

        $this->info('Dispatching ingestion job...');
        if (! empty($params['from']) || ! empty($params['to'])) {
            $this->line('Range: from=' . ($params['from'] ?? '(default)') . ' to=' . ($params['to'] ?? '(default)'));
        } else {
            $this->line('Range: default (last 24 hours)');
        }

        dispatch_sync(new IngestPbxCallsJob((int) $acct->company_id, (int) $acct->id, $params));
        $this->info('Ingest completed.');

        return self::SUCCESS;
    }
}
