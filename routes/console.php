<?php

use App\Jobs\GenerateWeeklyReport;
use App\Services\WeeklyReportAggregationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('pbx:sync-tenants')
    ->everyMinute()
    ->name('pbx-tenant-sync')
    ->withoutOverlapping();

Schedule::call(function (): void {
    $runId = (string) \Illuminate\Support\Str::uuid();
    $startedAt = microtime(true);

    Log::info('Weekly report scheduler started.', [
        'run_id' => $runId,
    ]);

    $companies = DB::table('companies')
        ->select(['id', 'timezone'])
        ->where('status', 'active')
        ->orderBy('id')
        ->get();

    $aggregationService = app(WeeklyReportAggregationService::class);

    $companiesProcessed = 0;
    $reportsDispatched = 0;

    foreach ($companies as $company) {
        $companiesProcessed++;

        $companyId = (int) $company->id;
        $timezone = is_string($company->timezone) && $company->timezone !== '' ? $company->timezone : 'UTC';

        try {
            // Aggregate for the previous full week in the company's timezone.
            $nowLocal = CarbonImmutable::now($timezone);
            $thisWeekStart = $nowLocal->startOfWeek(CarbonImmutable::MONDAY);
            $periodStart = $thisWeekStart->subWeek();
            $periodEnd = $periodStart->addDays(6)->endOfDay();

            $aggregationService->aggregateCompany(
                companyId: $companyId,
                from: $periodStart->utc(),
                to: $periodEnd->utc(),
            );

            // Find (or re-find) the snapshot row for that week and dispatch generation.
            $weeklyReport = DB::table('weekly_call_reports')
                ->select(['id', 'status'])
                ->where('company_id', $companyId)
                ->where('reporting_period_start', $periodStart->toDateString())
                ->where('reporting_period_end', $periodStart->addDays(6)->toDateString())
                ->first();

            if ($weeklyReport && ($weeklyReport->status ?? null) !== 'completed') {
                GenerateWeeklyReport::dispatch((int) $weeklyReport->id)->afterCommit();
                $reportsDispatched++;
            }
        } catch (Throwable $e) {
            Log::warning('Weekly report scheduler failed for company.', [
                'run_id' => $runId,
                'company_id' => $companyId,
                'timezone' => $timezone,
                'error' => $e->getMessage(),
            ]);
        }
    }

    Log::info('Weekly report scheduler completed.', [
        'run_id' => $runId,
        'companies_processed' => $companiesProcessed,
        'reports_dispatched' => $reportsDispatched,
        'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
    ]);
})
    // Run weekly; aggregation itself is per-company timezone.
    // 12:15 UTC ensures even UTC-12 has reached Monday.
    ->weeklyOn(1, '12:15')
    ->timezone('UTC')
    ->name('weekly-reports-aggregate-and-dispatch')
    ->withoutOverlapping(60)
    ->onOneServer();
