<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$reports = DB::table('weekly_call_reports as w')
    ->leftJoin('companies as c', 'c.id', '=', 'w.company_id')
    ->select([
        'w.id',
        'w.company_id',
        'c.name as company_name',
        'w.week_start_date',
        'w.week_end_date',
        'w.total_calls',
        'w.answered_calls',
        'w.missed_calls',
        'w.metrics',
    ])
    ->orderByDesc('w.id')
    ->limit(20)
    ->get();

echo "=== Weekly Reports Category Verification ===" . PHP_EOL;

if ($reports->isEmpty()) {
    echo "No weekly_call_reports found." . PHP_EOL;
    exit(0);
}

$foundWithCategories = 0;

foreach ($reports as $report) {
    $metrics = is_string($report->metrics) ? json_decode($report->metrics, true) : (array) $report->metrics;
    $categoryCounts = $metrics['category_counts'] ?? [];

    // category_counts may be associative ("id|name" => count) or list of objects
    $categoryBucketCount = 0;
    if (is_array($categoryCounts)) {
        if (array_is_list($categoryCounts)) {
            foreach ($categoryCounts as $item) {
                if (is_array($item) && !empty($item['call_count'])) {
                    $categoryBucketCount += (int) $item['call_count'];
                }
            }
        } else {
            foreach ($categoryCounts as $count) {
                $categoryBucketCount += (int) $count;
            }
        }
    }

    $categorizedCalls = DB::table('calls')
        ->where('weekly_call_report_id', $report->id)
        ->whereNotNull('category_id')
        ->count();

    $totalAssignedCalls = DB::table('calls')
        ->where('weekly_call_report_id', $report->id)
        ->count();

    $hasCategoryData = $categoryBucketCount > 0 || $categorizedCalls > 0;
    if ($hasCategoryData) {
        $foundWithCategories++;
    }

    echo PHP_EOL;
    echo "Report #{$report->id} | Company {$report->company_id} ({$report->company_name})" . PHP_EOL;
    echo "Week: {$report->week_start_date} -> {$report->week_end_date}" . PHP_EOL;
    echo "Report totals: total={$report->total_calls}, answered={$report->answered_calls}, missed={$report->missed_calls}" . PHP_EOL;
    echo "Calls assigned to report: {$totalAssignedCalls}" . PHP_EOL;
    echo "Categorized calls assigned: {$categorizedCalls}" . PHP_EOL;
    echo "Category counts total from metrics: {$categoryBucketCount}" . PHP_EOL;
    echo "Category breakdown status: " . ($hasCategoryData ? 'HAS DATA ✅' : 'EMPTY ⚠️') . PHP_EOL;
}

echo PHP_EOL;
echo "Reports with category data in last {$reports->count()} checked: {$foundWithCategories}" . PHP_EOL;
