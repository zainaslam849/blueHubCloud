<?php

namespace App\Console;

use App\Jobs\IngestPbxCallsJob;
use App\Jobs\QueueHeartbeatJob;
use App\Models\CompanyPbxAccount;
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

        // Auto-sync PBXware tenants every minute (model decides when each provider is due)
        $schedule->command('pbx:sync-tenants')
            ->everyMinute()
            ->withoutOverlapping()
            ->name('pbx-tenant-sync');

        // Hardcoded AI category generation schedule (every week)
        $schedule->command('ai:generate-categories --company=1 --range=30')
            ->weekly()
            ->withoutOverlapping();

        $schedule->call(function () {
            $enabled = (bool) config('services.pbxware.ingest_enabled', env('PBXWARE_INGEST_ENABLED', true));
            if (! $enabled) {
                Log::info('PBXware ingest scheduler is disabled via PBXWARE_INGEST_ENABLED');
                return;
            }

            Log::info('PBXware ingest scheduler starting');

            $accounts = CompanyPbxAccount::where('status', 'active')->get();
            foreach ($accounts as $account) {
                // Dispatch a job per PBX account; job handles company scoping
                IngestPbxCallsJob::dispatch($account->company_id, $account->id)->onQueue('ingest-pbx');
            }

            Log::info('PBXware ingest scheduler completed', ['dispatched' => $accounts->count()]);
        })->everyFiveMinutes()->withoutOverlapping();
    }

    protected function commands(): void
    {
        // Load commands if any
        $this->load(__DIR__ . '/Commands');
    }
}
