<?php

namespace App\Console\Commands;

use App\Models\Call;
use App\Models\Company;
use App\Models\CreditTransaction;
use App\Services\Billing\CreditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Full reset — wipes every company's calls, transcriptions, weekly reports,
 * and advanced-view analytics across all of history, refunding any credits
 * already consumed so re-fetching doesn't double-charge. Built to let this
 * session's timezone fixes be verified by regenerating everything from
 * scratch rather than one test week.
 *
 * weekly_call_reports cascades to extension/ring-group/category analytics
 * rows; calls cascades to call_transcriptions — both via DB foreign keys,
 * so those tables don't need separate delete statements.
 *
 * pbx_raw_payloads (raw ingestion cache) and pipeline_runs (operational run
 * history/logs) are deliberately left untouched — they aren't "call data"
 * and clearing them isn't needed to regenerate reports from scratch.
 */
class ResetAllReportData extends Command
{
    protected $signature = 'reports:reset-all {--dry-run : Show what would be deleted/refunded without changing anything} {--yes : Skip the confirmation prompt}';

    protected $description = 'Delete every call, transcription, weekly report, and advanced-view row for every company, refunding consumed credits, so everything can be re-fetched and regenerated from scratch';

    public function handle(CreditService $creditService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $totalCalls = DB::table('calls')->count();
        $totalReports = DB::table('weekly_call_reports')->count();
        $totalFetches = DB::table('company_weekly_fetches')->count();

        $refundsByCompany = DB::table('credit_transactions')
            ->select('company_id', DB::raw('SUM(ABS(credits)) as total'))
            ->where('type', CreditTransaction::TYPE_DEDUCTION)
            ->where('reference_type', Call::class)
            ->groupBy('company_id')
            ->get();

        $totalCredits = (float) $refundsByCompany->sum('total');

        $this->line("Calls to delete: {$totalCalls}");
        $this->line("Transcriptions: cascade-deleted with their calls");
        $this->line("Weekly reports to delete: {$totalReports} (cascades to extension/ring-group/category analytics rows)");
        $this->line("Weekly-fetch markers to delete: {$totalFetches}");
        $this->line('Companies to refund: '.$refundsByCompany->count());
        $this->line('Total credits to refund: '.number_format($totalCredits, 4));
        $this->newLine();
        $this->comment('Note: pbx_raw_payloads and pipeline_runs (operational logs) are left untouched.');

        if ($dryRun) {
            $this->newLine();
            $this->info('[dry run] Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm('This permanently deletes ALL calls, transcriptions, and weekly reports for EVERY company, for all of history. Continue?')) {
            $this->comment('Cancelled.');

            return self::SUCCESS;
        }

        foreach ($refundsByCompany as $row) {
            $company = Company::withTrashed()->find($row->company_id);
            if ($company && $row->total > 0) {
                $creditService->credit(
                    $company,
                    (float) $row->total,
                    CreditTransaction::TYPE_REFUND,
                    null,
                    ['source' => 'reports:reset-all', 'note' => 'Full reset refund — all calls deleted for regeneration'],
                );
            }
        }

        DB::table('weekly_call_reports')->delete();
        DB::table('company_weekly_fetches')->delete();
        DB::table('calls')->delete();

        $this->newLine();
        $this->info("Done — deleted {$totalCalls} calls, {$totalReports} reports, {$totalFetches} fetch markers; refunded ".number_format($totalCredits, 4).' credits across '.$refundsByCompany->count().' companies.');
        $this->comment('Re-run the pipeline to re-fetch and regenerate everything from scratch.');

        return self::SUCCESS;
    }
}
