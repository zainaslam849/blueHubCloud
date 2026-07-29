<?php

namespace App\Console;

use App\Jobs\QueueHeartbeatJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->call(function () {
            Cache::put('system:scheduler:last_run', now()->toIso8601String(), 3600);
        })->everyMinute()->name('scheduler-heartbeat');

        $schedule->job(new QueueHeartbeatJob())
            ->everyFiveMinutes()
            ->onQueue('default')
            ->name('queue-heartbeat');

        $schedule->command('horizon:snapshot')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        // pbx:sync-tenants is registered in routes/console.php (Laravel 11 convention).
        // Do NOT re-register it here — duplicate registration causes the scheduler to
        // run the command twice per minute and `php artisan schedule:list` to show
        // two entries for the same job.

        // Hardcoded AI category generation schedule (every week)
        $schedule->command('ai:generate-categories --company=1 --range=30')
            ->weekly()
            ->withoutOverlapping();

        // NOTE: The unbounded every-5-minute PBX ingest was intentionally removed.
        // Call ingestion now runs ONLY inside the limit-aware weekly pipeline
        // (see routes/console.php → 'weekly-pipeline'), which caps how many calls each
        // company fetches to its remaining monthly call limit. Re-enabling an unlimited
        // ingest here would bypass that limit.
    }

    protected function commands(): void
    {
        // Load commands if any
        $this->load(__DIR__ . '/Commands');
    }
}
