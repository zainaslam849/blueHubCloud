<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$rows = \App\Models\WeeklyCallReport::whereBetween('reporting_period_start', ['2025-12-01', '2026-01-31'])->get();

echo "found: " . $rows->count() . PHP_EOL;
foreach ($rows as $r) {
    echo sprintf("%d %s %s total_calls=%s\n", $r->id, $r->reporting_period_start, $r->week_start_date, $r->total_calls);
}
