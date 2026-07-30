<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

/**
 * This class is NOT bootstrapped in this Laravel 11 app — bootstrap/app.php
 * has no Console\Kernel binding, so schedule() below is never invoked by
 * the framework. All scheduling lives in routes/console.php instead (the
 * convention Laravel 11 actually uses). Do NOT add schedule entries here;
 * they will silently never run. See routes/console.php for the heartbeat,
 * queue-heartbeat, horizon:snapshot, and ai:generate-categories tasks that
 * used to live in this method.
 */
class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        //
    }

    protected function commands(): void
    {
        // Load commands if any
        $this->load(__DIR__ . '/Commands');
    }
}
