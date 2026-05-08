<?php
/**
 * One-shot recovery for the diagnostics found on 2026-05-08:
 *   1. Re-kick stuck pipeline run #10 so the new race-condition recovery
 *      branch in ContinuePipelineAfterSummariesJob can pick up the 42
 *      late-arriving transcripts.
 *   2. Flush the 2 stale failed_jobs entries from 2026-04-30 (the
 *      duplicate-call ingest bug that has since been fixed by switching
 *      IngestPbxCallsJob to Call::updateOrCreate).
 *
 * Usage: php recover_pipeline.php
 */
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\ContinuePipelineAfterSummariesJob;
use App\Models\PipelineRun;
use Illuminate\Support\Facades\DB;

$run = PipelineRun::find(10);
if (! $run) {
    fwrite(STDERR, "Pipeline run 10 not found.\n");
    exit(1);
}

$from = \Carbon\CarbonImmutable::parse($run->range_from, 'UTC')->toDateString();
$to   = \Carbon\CarbonImmutable::parse($run->range_to,   'UTC')->toDateString();
$queue           = $run->metrics['pipeline_queue']    ?? 'default';
$summarizeLimit  = (int) ($run->metrics['summarize_limit']  ?? 500);
$categorizeLimit = (int) ($run->metrics['categorize_limit'] ?? 500);
$rangeDays       = (int) ($run->metrics['range_days']       ?? 30);

echo "Re-dispatching ContinuePipelineAfterSummariesJob for run 10:\n";
echo "  company_id={$run->company_id} from={$from} to={$to} queue={$queue}\n";
echo "  summarize_limit={$summarizeLimit} categorize_limit={$categorizeLimit}\n";

ContinuePipelineAfterSummariesJob::dispatch(
    $run->company_id,
    $from,
    $to,
    $categorizeLimit,
    $queue,
    $run->id,
    $rangeDays,
    $summarizeLimit,
    0,
)->onQueue($queue);

echo "  -> queued. The new first-poll branch will dispatch QueueCallsForSummarizationJob for the 42 pending calls.\n\n";

$flushed = DB::table('failed_jobs')->whereIn('id', [1, 2])->delete();
echo "Flushed {$flushed} stale failed_jobs (ids 1,2 from 2026-04-30; root cause already fixed by Call::updateOrCreate in IngestPbxCallsJob).\n";

echo "\nDone. Now make sure a worker is consuming the right queues:\n";
echo "  php artisan queue:work --queue=ingest-pbx,summarization,categorization,default --tries=1 --timeout=600 -v\n";
