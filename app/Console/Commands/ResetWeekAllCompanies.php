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
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Week-wide version of reports:reset — wipes every company's data for one
 * week (report, advanced-view rows, weekly-fetch marker, and calls in range),
 * refunding any credits already consumed. Covers companies whose weekly run
 * never produced a report row (failed/partial fetch) as well as completed
 * ones, so nothing from that week is left behind before re-running the
 * pipeline to verify a fix.
 */
class ResetWeekAllCompanies extends Command
{
    protected $signature = 'reports:reset-week {week_start : Week start date, e.g. 2026-08-24} {--dry-run : Show what would be deleted/refunded without changing anything} {--yes : Skip the confirmation prompt}';

    protected $description = 'Delete every company\'s report, calls, and advanced-view data for one week so the whole week can be re-fetched and regenerated from scratch';

    public function handle(CreditService $creditService): int
    {
        $weekStart = (string) $this->argument('week_start');
        $dryRun = (bool) $this->option('dry-run');

        $reportsByCompany = WeeklyCallReport::whereDate('week_start_date', $weekStart)
            ->get()
            ->keyBy('company_id');

        $fetchesByCompany = CompanyWeeklyFetch::whereDate('week_start_date', $weekStart)
            ->get()
            ->keyBy('company_id');

        $companyIds = $reportsByCompany->keys()
            ->merge($fetchesByCompany->keys())
            ->unique()
            ->values();

        if ($companyIds->isEmpty()) {
            $this->info("No reports or weekly-fetch records found for week starting {$weekStart}.");

            return self::SUCCESS;
        }

        $weekEnd = $reportsByCompany->first()?->week_end_date?->toDateString()
            ?? $fetchesByCompany->first()?->week_end_date?->toDateString()
            ?? $weekStart;

        $this->line("Week {$weekStart} to {$weekEnd} — {$companyIds->count()} company(ies) affected");
        $this->newLine();

        $plan = [];
        $totalCalls = 0;
        $totalCredits = 0.0;

        foreach ($companyIds as $companyId) {
            $company = Company::withTrashed()->find($companyId);
            $report = $reportsByCompany->get($companyId);
            $fetch = $fetchesByCompany->get($companyId);

            // calls.started_at is stored in UTC; weekStart/weekEnd are calendar
            // dates in the company's local timezone. Comparing them directly
            // against the UTC column (whereDate) shifts the window by the
            // company's UTC offset — convert to actual UTC instant bounds.
            $timezone = is_string($company?->timezone) && $company->timezone !== '' ? $company->timezone : 'UTC';
            $utcStart = CarbonImmutable::parse($weekStart, $timezone)->startOfDay()->setTimezone('UTC');
            $utcEnd = CarbonImmutable::parse($weekEnd, $timezone)->endOfDay()->setTimezone('UTC');

            $callIds = DB::table('calls')
                ->where('company_id', $companyId)
                ->where('started_at', '>=', $utcStart->toDateTimeString())
                ->where('started_at', '<=', $utcEnd->toDateTimeString())
                ->pluck('id');

            $creditsToRefund = (float) DB::table('credit_transactions')
                ->where('type', CreditTransaction::TYPE_DEDUCTION)
                ->where('reference_type', Call::class)
                ->whereIn('reference_id', $callIds)
                ->sum(DB::raw('ABS(credits)'));

            $totalCalls += $callIds->count();
            $totalCredits += $creditsToRefund;

            $this->line(sprintf(
                '  #%d %-30s calls=%-5d credits=%-10s report=%s fetch=%s',
                $companyId,
                $company?->name ?? '(deleted)',
                $callIds->count(),
                number_format($creditsToRefund, 4),
                $report ? "#{$report->id} ({$report->status})" : '—',
                $fetch ? "#{$fetch->id} ({$fetch->status})" : '—',
            ));

            $plan[] = compact('companyId', 'company', 'report', 'fetch', 'callIds', 'creditsToRefund');
        }

        $this->newLine();
        $this->line("Totals: {$totalCalls} calls, ".number_format($totalCredits, 4).' credits to refund.');

        if ($dryRun) {
            $this->newLine();
            $this->info('[dry run] Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm('This permanently deletes those calls and their transcripts/AI data for ALL companies listed above. Continue?')) {
            $this->comment('Cancelled.');

            return self::SUCCESS;
        }

        foreach ($plan as $row) {
            DB::transaction(function () use ($row, $creditService) {
                ['company' => $company, 'report' => $report, 'fetch' => $fetch, 'callIds' => $callIds, 'creditsToRefund' => $creditsToRefund] = $row;

                if ($company && $creditsToRefund > 0) {
                    $creditService->credit(
                        $company,
                        $creditsToRefund,
                        CreditTransaction::TYPE_REFUND,
                        $report ?? $fetch,
                        ['source' => 'reports:reset-week', 'note' => "Refund for deleted calls, week reset (company #{$company->id})"],
                    );
                }

                if ($report) {
                    ExtensionPerformanceReport::where('weekly_call_report_id', $report->id)->delete();
                    RingGroupPerformanceReport::where('weekly_call_report_id', $report->id)->delete();
                    CategoryAnalyticsReport::where('weekly_call_report_id', $report->id)->delete();
                }

                DB::table('calls')->whereIn('id', $callIds)->delete();

                $fetch?->delete();
                $report?->delete();
            });
        }

        $this->newLine();
        $this->info("Done — {$companyIds->count()} company(ies) reset: {$totalCalls} calls deleted, ".number_format($totalCredits, 4).' credits refunded.');
        $this->comment('Re-run the weekly pipeline to regenerate this week for verification.');

        return self::SUCCESS;
    }
}
