<?php

namespace App\Console\Commands;

use App\Models\Call;
use App\Models\CategoryAnalyticsReport;
use App\Models\Company;
use App\Models\CompanyWeeklyFetch;
use App\Models\CreditTransaction;
use App\Models\ExtensionPerformanceReport;
use App\Models\RingGroupPerformanceReport;
use App\Models\WeeklyCallReport;
use App\Services\Billing\CreditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wipes one company's week clean — the report, its advanced-view rows, the
 * weekly-fetch marker, and every call in that date range — refunding any
 * credits those calls already consumed so re-fetching doesn't double-charge.
 * Built to let a fix (e.g. the timezone backfill) be verified by
 * regenerating a specific report from scratch rather than waiting a week.
 */
class ResetWeeklyReport extends Command
{
    protected $signature = 'reports:reset {report_id : The WeeklyCallReport id to wipe} {--dry-run : Show what would be deleted/refunded without changing anything} {--yes : Skip the confirmation prompt}';

    protected $description = 'Delete a weekly report, its calls, and its advanced-view data so it can be re-fetched and regenerated from scratch';

    public function handle(CreditService $creditService): int
    {
        $report = WeeklyCallReport::find((int) $this->argument('report_id'));

        if (! $report) {
            $this->error("No weekly report with id #{$this->argument('report_id')}.");

            return self::FAILURE;
        }

        $company = Company::withTrashed()->find($report->company_id);
        $dryRun = (bool) $this->option('dry-run');

        $calls = DB::table('calls')
            ->where('company_id', $report->company_id)
            ->whereDate('started_at', '>=', $report->week_start_date->toDateString())
            ->whereDate('started_at', '<=', $report->week_end_date->toDateString())
            ->get(['id']);
        $callIds = $calls->pluck('id');

        $creditsToRefund = (float) DB::table('credit_transactions')
            ->where('type', CreditTransaction::TYPE_DEDUCTION)
            ->where('reference_type', Call::class)
            ->whereIn('reference_id', $callIds)
            ->sum(DB::raw('ABS(credits)'));

        $weeklyFetch = CompanyWeeklyFetch::where('company_id', $report->company_id)
            ->whereDate('week_start_date', $report->week_start_date->toDateString())
            ->first();

        $this->line("Report #{$report->id} — {$company?->name} — {$report->week_start_date->toDateString()} to {$report->week_end_date->toDateString()}");
        $this->line('  Calls to delete: '.$callIds->count());
        $this->line('  Credits to refund: '.number_format($creditsToRefund, 4));
        $this->line('  Weekly-fetch marker: '.($weeklyFetch ? "found (#{$weeklyFetch->id}, status={$weeklyFetch->status})" : 'none'));
        $this->line('  Advanced-view rows: '
            .ExtensionPerformanceReport::where('weekly_call_report_id', $report->id)->count().' extension, '
            .RingGroupPerformanceReport::where('weekly_call_report_id', $report->id)->count().' ring group, '
            .CategoryAnalyticsReport::where('weekly_call_report_id', $report->id)->count().' category');

        if ($dryRun) {
            $this->newLine();
            $this->info('[dry run] Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm('This permanently deletes those calls and their transcripts/AI data. Continue?')) {
            $this->comment('Cancelled.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($report, $company, $callIds, $creditsToRefund, $weeklyFetch, $creditService) {
            if ($company && $creditsToRefund > 0) {
                $creditService->credit(
                    $company,
                    $creditsToRefund,
                    CreditTransaction::TYPE_REFUND,
                    $report,
                    ['source' => 'reports:reset', 'note' => "Refund for deleted calls, report #{$report->id}"],
                );
            }

            ExtensionPerformanceReport::where('weekly_call_report_id', $report->id)->delete();
            RingGroupPerformanceReport::where('weekly_call_report_id', $report->id)->delete();
            CategoryAnalyticsReport::where('weekly_call_report_id', $report->id)->delete();

            DB::table('calls')->whereIn('id', $callIds)->delete();

            $weeklyFetch?->delete();

            $report->delete();
        });

        $this->newLine();
        $this->info("Done — deleted {$callIds->count()} calls, refunded ".number_format($creditsToRefund, 4).' credits, removed the report and its fetch marker.');
        $this->comment('Re-run the pipeline for this company/week to regenerate.');

        return self::SUCCESS;
    }
}
