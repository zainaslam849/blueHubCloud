<?php

namespace App\Console\Commands;

use App\Jobs\IngestPbxCallsJob;
use App\Models\CompanyPbxAccount;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PbxIngestTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pbx:ingest-test {--company_id= : Company ID (optional)} {--account_id= : Company PBX account ID (optional)} {--from= : Start datetime (optional; parseable by Carbon)} {--to= : End datetime (optional; parseable by Carbon)} {--limit= : Max rows per request (optional; default 1000, max 1000)} {--cdr_csv_action= : PBXware documented CDR CSV list/export action name (overrides PBXWARE_CDR_CSV_ACTION; must NOT be pbxware.cdr.download)} {--mock : Force PBXWARE_MOCK_MODE=true for this run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run PBXware ingestion for a given date range (CDR CSV via PBXWARE_CDR_CSV_ACTION; recordings via pbxware.cdr.download)';

    public function handle(): int
    {
        if ($this->option('mock')) {
            config(['pbx.mode' => 'mock']);
            $this->info("Running with pbx.mode=mock (forced by --mock).");
        }

        $cdrCsvAction = $this->option('cdr_csv_action');
        if (is_string($cdrCsvAction) && trim($cdrCsvAction) !== '') {
            config(['pbx.providers.pbxware.cdr_csv_action' => trim($cdrCsvAction)]);
            $this->info('Using CDR CSV action override from --cdr_csv_action.');
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
