<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== failed_jobs ===\n";
$failed = DB::table('failed_jobs')->orderBy('id')->get();
foreach ($failed as $row) {
    $payload = json_decode($row->payload, true);
    echo "--- id={$row->id} queue={$row->queue} failed_at={$row->failed_at}\n";
    echo "job_class=" . ($payload['displayName'] ?? '?') . "\n";
    echo "attempts="  . ($payload['attempts']    ?? '?') . "\n";
    echo "exception(first 800)=\n" . substr($row->exception, 0, 800) . "\n\n";
}

echo "=== run 10 stage detail ===\n";
$stages = DB::table('pipeline_run_stages')->where('pipeline_run_id', 10)->orderBy('id')->get();
foreach ($stages as $s) {
    echo "stage={$s->stage_key} status={$s->status} started={$s->started_at} finished={$s->finished_at}\n";
    echo "  metrics={$s->metrics}\n";
    echo "  error=" . ($s->error_message ?: '—') . "\n";
}

echo "\n=== run 10 call counts (company 144, 2026-04-06 .. 2026-05-06) ===\n";
$from = '2026-04-06 00:00:00';
$to   = '2026-05-06 23:59:59';
$base = DB::table('calls')->where('company_id', 144)->whereBetween('started_at', [$from, $to]);
echo "total_in_range="           . (clone $base)->count() . "\n";
echo "with_transcript="          . (clone $base)->whereNotNull('transcript_text')->where('transcript_text','!=','')->count() . "\n";
echo "summary_done="             . (clone $base)->whereNotNull('ai_summary')->where('ai_summary','!=','')->count() . "\n";
echo "summary_status_pending="   . (clone $base)->where('ai_summary_status','pending')->count() . "\n";
echo "summary_status_processing=". (clone $base)->where('ai_summary_status','processing')->count() . "\n";
echo "summary_status_failed="    . (clone $base)->where('ai_summary_status','failed')->count() . "\n";
echo "summary_status_null="      . (clone $base)->whereNull('ai_summary_status')->count() . "\n";

echo "\npending_summaries (transcript present, no summary, no status) = " .
    (clone $base)
        ->whereNotNull('transcript_text')->where('transcript_text','!=','')
        ->whereNull('ai_summary_status')
        ->where(function($q){ $q->whereNull('ai_summary')->orWhere('ai_summary',''); })
        ->count() . "\n";

echo "\n=== jobs table (default queue) ===\n";
echo "total="     . DB::table('jobs')->count() . "\n";
foreach (DB::table('jobs')->get() as $j) {
    $p = json_decode($j->payload, true);
    echo "id={$j->id} queue={$j->queue} attempts={$j->attempts} reserved_at={$j->reserved_at} available_at={$j->available_at} class=" . ($p['displayName'] ?? '?') . "\n";
}
