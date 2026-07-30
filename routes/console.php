<?php

use App\Jobs\AdminTestPipelineJob;
use App\Jobs\QueueHeartbeatJob;
use App\Models\AppSetting;
use App\Models\Company;
use App\Models\CompanyWeeklyFetch;
use App\Models\PipelineRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Cache;
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

// NOTE: these four tasks used to live in app/Console/Kernel.php@schedule().
// That class is never bootstrapped by this Laravel 11 app (bootstrap/app.php
// has no Console\Kernel binding), so none of them ever actually ran — moved
// here to the registration point that Laravel 11 actually uses.
Schedule::call(function () {
    Cache::put('system:scheduler:last_run', now()->toIso8601String(), 3600);
})->everyMinute()->name('scheduler-heartbeat');

Schedule::job(new QueueHeartbeatJob(), 'default')
    ->everyFiveMinutes()
    ->name('queue-heartbeat');

Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('ai:generate-categories --company=1 --range=30')
    ->weekly()
    ->withoutOverlapping();

/*
 * Weekly limit-aware AI pipeline.
 *
 * Admin configures the day / time / timezone from Settings → Automation. Because
 * routes/console.php is re-evaluated on every `schedule:run` tick, we read those
 * settings here and register the task with the matching cron. The scheduler only
 * DISPATCHES queued jobs (one AdminTestPipelineJob per active company); Horizon
 * processes them. Each run caps ingestion to the company's remaining monthly call
 * limit and records per-week overflow for the user "process remaining" buttons.
 */
$weeklyRun = (function (): array {
    $defaults = [
        'enabled'  => true,
        'day'      => 1,        // 0=Sun .. 6=Sat (Monday default)
        'time'     => '02:00',
        'timezone' => 'UTC',
    ];

    try {
        $settings = AppSetting::query()->first();
        if (! $settings) {
            return $defaults;
        }

        $time = is_string($settings->weekly_run_time) && preg_match('/^\d{2}:\d{2}$/', $settings->weekly_run_time)
            ? $settings->weekly_run_time
            : $defaults['time'];

        $tz = is_string($settings->weekly_run_timezone) && $settings->weekly_run_timezone !== ''
            ? $settings->weekly_run_timezone
            : $defaults['timezone'];

        return [
            'enabled'  => (bool) ($settings->weekly_run_enabled ?? true),
            'day'      => max(0, min(6, (int) ($settings->weekly_run_day ?? $defaults['day']))),
            'time'     => $time,
            'timezone' => $tz,
        ];
    } catch (Throwable $e) {
        // DB not ready (e.g. during migrate) — fall back to safe defaults.
        return $defaults;
    }
})();

if ($weeklyRun['enabled']) {
    Schedule::call(function () use ($weeklyRun): void {
        $runId = (string) \Illuminate\Support\Str::uuid();

        Log::info('Weekly pipeline scheduler started.', ['run_id' => $runId]);

        $companies = Company::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        $dispatched = 0;
        $pausedCompanies = 0;

        foreach ($companies as $company) {
            $companyId = (int) $company->id;
            $timezone = is_string($company->timezone) && $company->timezone !== '' ? $company->timezone : 'UTC';

            try {
                // Previous full week (Mon–Sun) in the company's timezone.
                $nowLocal = CarbonImmutable::now($timezone);
                $periodStart = $nowLocal->startOfWeek(CarbonImmutable::MONDAY)->subWeek();
                $periodEnd = $periodStart->addDays(6);
                $from = $periodStart->utc()->toDateString();
                $to = $periodEnd->utc()->toDateString();

                // Credit gating: try an auto top-up first (opt-in, off-session,
                // at most one attempt per company per week), then size the
                // fetch budget from the resulting balance. Zero budget = skip
                // the fetch entirely and record the week as insufficient.
                $creditService = app(\App\Services\Billing\CreditService::class);
                app(\App\Services\Billing\AutoTopupService::class)
                    ->maybeTopUp($company, $periodStart->toDateString());

                $secondsBudget = $creditService->secondsBudget($company);

                if ($secondsBudget <= 0) {
                    CompanyWeeklyFetch::query()->updateOrCreate(
                        ['company_id' => $companyId, 'week_start_date' => $periodStart->toDateString()],
                        [
                            'week_end_date'     => $periodEnd->toDateString(),
                            'status'            => CompanyWeeklyFetch::STATUS_INSUFFICIENT_CREDITS,
                            'last_attempted_at' => now(),
                        ],
                    );
                    $pausedCompanies++;
                    continue;
                }

                $pipelineRun = PipelineRun::query()->create([
                    'company_id'    => $companyId,
                    'range_from'    => $from,
                    'range_to'      => $to,
                    'status'        => 'queued',
                    'trigger_type'  => 'scheduled',
                    'current_stage' => 'call_discovery',
                    'active_key'    => $companyId . ':' . $from . ':' . $to,
                    'started_at'    => now(),
                    'metrics'       => [
                        'max_seconds' => $secondsBudget,
                        'source'      => 'weekly-pipeline',
                    ],
                ]);

                AdminTestPipelineJob::dispatch(
                    companyId: $companyId,
                    fromDate: $from,
                    toDate: $to,
                    summarizeLimit: 5000,
                    categorizeLimit: 5000,
                    pipelineQueue: 'default',
                    pipelineRunId: $pipelineRun->id,
                    isResume: false,
                    maxCalls: null,
                    trackCompanyLimit: true,
                    maxSeconds: $secondsBudget,
                    deductCredits: true,
                )->onQueue('default');

                $dispatched++;
            } catch (Throwable $e) {
                Log::warning('Weekly pipeline scheduler failed for company.', [
                    'run_id' => $runId,
                    'company_id' => $companyId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Weekly pipeline scheduler completed.', [
            'run_id' => $runId,
            'dispatched' => $dispatched,
            'paused_companies' => $pausedCompanies,
        ]);
    })
        ->weeklyOn($weeklyRun['day'], $weeklyRun['time'])
        ->timezone($weeklyRun['timezone'])
        ->name('weekly-pipeline')
        ->withoutOverlapping(60)
        ->onOneServer();
}
