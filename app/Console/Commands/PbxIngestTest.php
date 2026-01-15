<?php

namespace App\Console\Commands;

use App\Jobs\IngestPbxCallsJob;
use App\Models\CompanyPbxAccount;
use Carbon\Carbon;
use Illuminate\Console\Command;
use App\Services\Pbx\PbxClientResolver;

class PbxIngestTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pbx:ingest-test {--company_id= : Company ID (optional)} {--account_id= : Company PBX account ID (optional)} {--from= : Start datetime (optional; parseable by Carbon)} {--to= : End datetime (optional; parseable by Carbon)} {--limit= : Max rows per request (optional; default 1000, max 1000)} {--list_servers : List available PBXware server IDs via pbxware.tenant.list} {--server_id= : Persist PBXware server ID to company_pbx_accounts.server_id before ingesting} {--mock : Force PBXWARE_MOCK_MODE=true for this run}';

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
            $client = PbxClientResolver::resolve();
            if (! method_exists($client, 'fetchTenantServerIds')) {
                $this->error('Client does not support tenant discovery in this mode.');
                return self::FAILURE;
            }

            $serverIds = $client->fetchTenantServerIds();
            if (! is_array($serverIds) || $serverIds === []) {
                $this->warn('No server IDs returned from pbxware.tenant.list.');
                return self::SUCCESS;
            }

            $this->info('Available PBXware server IDs:');
            foreach ($serverIds as $id) {
                $this->line('- ' . $id);
            }
            return self::SUCCESS;
        }

        $serverIdOpt = $this->option('server_id');
        if (is_string($serverIdOpt) && trim($serverIdOpt) !== '') {
            $acct->server_id = trim($serverIdOpt);
            $acct->save();
            $this->info('Saved server_id on PBX account: ' . $acct->server_id);
        }

        if (! is_string($acct->server_id) || trim($acct->server_id) === '') {
            $this->error('PBX account is missing server_id. Run with --list_servers then pass --server_id=<id>.');
            return self::FAILURE;
        }

        $params = array_filter([
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'limit' => $this->option('limit'),
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
