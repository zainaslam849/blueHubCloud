<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$jobs = DB::table('failed_jobs')->orderBy('id')->get(['id','failed_at','exception']);
echo "Failed jobs (" . $jobs->count() . "):" . PHP_EOL;
foreach ($jobs as $job) {
    echo "[Job {$job->id}] {$job->failed_at}" . PHP_EOL;
    echo "Error: " . mb_substr($job->exception, 0, 250) . "..." . PHP_EOL;
    echo "" . PHP_EOL;
}


require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$failedJobs = DB::table('failed_jobs')->orderBy('id', 'desc')->limit(2)->get();

foreach ($failedJobs as $job) {
    echo "=====================================\n";
    echo "Failed Job ID: {$job->id}\n";
    echo "Queue: {$job->queue}\n";
    echo "Failed at: {$job->failed_at}\n";
    echo "Payload: " . substr($job->payload, 0, 200) . "...\n";
    echo "\nException (first 1000 chars):\n";
    echo substr($job->exception, 0, 1000) . "\n";
    echo "=====================================\n\n";
}
