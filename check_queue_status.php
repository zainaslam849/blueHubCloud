<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$jobsCount = DB::table('jobs')->count();
$failedCount = DB::table('failed_jobs')->count();
$callsCount = DB::table('calls')->count();

echo "=== Queue Status ===" . PHP_EOL;
echo "Jobs queued: $jobsCount" . PHP_EOL;
echo "Failed jobs: $failedCount" . PHP_EOL;
echo "Calls in DB: $callsCount" . PHP_EOL;

if ($jobsCount > 0) {
    echo PHP_EOL . "Queued Jobs:" . PHP_EOL;
    $jobs = DB::table('jobs')->orderBy('id', 'desc')->limit(5)->get();
    foreach ($jobs as $job) {
        echo "  - ID: {$job->id}, Queue: {$job->queue}, Attempts: {$job->attempts}" . PHP_EOL;
    }
}

if ($failedCount > 0) {
    echo PHP_EOL . "Failed Jobs:" . PHP_EOL;
    $failed = DB::table('failed_jobs')->orderBy('id', 'desc')->limit(3)->get();
    foreach ($failed as $job) {
        echo "  - ID: {$job->id}, Queue: {$job->queue}, Failed: {$job->failed_at}" . PHP_EOL;
    }
}
