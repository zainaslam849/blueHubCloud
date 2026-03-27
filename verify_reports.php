<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->boot();

use Illuminate\Support\Facades\DB;

echo "=== Weekly Call Reports ===\n";
$reports = DB::table('weekly_call_reports')->orderBy('id')->get();
foreach ($reports as $r) {
    $ext  = DB::table('extension_performance_reports')->where('weekly_call_report_id', $r->id)->count();
    $rg   = DB::table('ring_group_performance_reports')->where('weekly_call_report_id', $r->id)->count();
    $cat  = DB::table('category_analytics_reports')->where('weekly_call_report_id', $r->id)->count();
    printf(
        "Report %d | company=%d | %s→%s | calls=%d | status=%-10s | generated_at=%s | ext=%d rg=%d cat=%d\n",
        $r->id, $r->company_id, $r->week_start, $r->week_end,
        $r->total_calls, $r->status, $r->generated_at ?? 'null', $ext, $rg, $cat
    );
}

echo "\n=== Top Extensions per Report ===\n";
$exts = DB::table('extension_performance_reports')
    ->select('weekly_call_report_id','extension','total_calls_answered','total_calls_made','total_minutes')
    ->orderBy('weekly_call_report_id')
    ->orderByDesc('total_calls_answered')
    ->get();

$grouped = $exts->groupBy('weekly_call_report_id');
foreach ($grouped as $reportId => $rows) {
    echo "Report $reportId:\n";
    foreach ($rows->take(10) as $e) {
        printf("  ext=%-20s answered=%d made=%d minutes=%s\n",
            $e->extension, $e->total_calls_answered, $e->total_calls_made, $e->total_minutes);
    }
}
