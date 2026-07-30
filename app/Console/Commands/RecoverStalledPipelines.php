<?php

namespace App\Console\Commands;

use App\Jobs\AdminTestPipelineJob;
use App\Models\PipelineRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Watchdog for stalled pipeline runs.
 *
 * The pipeline advances by each stage dispatching the next job. If any one
 * of those dispatches is lost — a worker restarted mid-flight, an OOM kill,
 * a stale queue connection, a reboot — the run stops silently and stays
 * "running"/"queued" forever with nobody watching it. This has required
 * manual developer recovery at least three times (see recover_pipeline.php,
 * 2026-05-08).
 *
 * This command detects runs that have made no progress for a while and
 * re-dispatches them the same way the admin "Resume" button does. Stage
 * bookkeeping means already-completed stages are skipped, and per-call
 * credit deduction is idempotent (unique ledger reference), so recovery is
 * safe to run repeatedly.
 *
 * Runs that cannot be recovered after --max-attempts are marked failed so
 * they surface in the admin UI instead of silently retrying forever.
 */
class RecoverStalledPipelines extends Command
{
    protected $signature = 'pipeline:recover
                            {--minutes=15 : Treat a run as stalled after this many minutes without progress}
                            {--max-attempts=3 : Mark the run failed after this many automatic recovery attempts}
                            {--dry-run : Report what would be recovered without dispatching anything}';

    protected $description = 'Detect and re-dispatch pipeline runs that have stalled mid-stage';

    public function handle(): int
    {
        $minutes = max(5, (int) $this->option('minutes'));
        $maxAttempts = max(1, (int) $this->option('max-attempts'));
        $dryRun = (bool) $this->option('dry-run');

        $threshold = now()->subMinutes($minutes);

        $stalled = PipelineRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->where('updated_at', '<=', $threshold)
            ->orderBy('id')
            ->get();

        if ($stalled->isEmpty()) {
            $this->info('No stalled pipeline runs.');

            return self::SUCCESS;
        }

        $this->warn("Found {$stalled->count()} stalled pipeline run(s) (no progress for {$minutes}+ minutes).");

        $recovered = 0;
        $abandoned = 0;

        foreach ($stalled as $run) {
            $metrics = is_array($run->metrics) ? $run->metrics : [];
            $attempts = (int) ($metrics['auto_recovery_count'] ?? 0);
            $stalledFor = $run->updated_at ? (int) $run->updated_at->diffInMinutes(now()) : null;

            $context = [
                'pipeline_run_id' => $run->id,
                'company_id' => $run->company_id,
                'status' => $run->status,
                'stage' => $run->current_stage,
                'stalled_for_minutes' => $stalledFor,
                'auto_recovery_count' => $attempts,
            ];

            if ($attempts >= $maxAttempts) {
                $this->error("  #{$run->id} (company {$run->company_id}, stage {$run->current_stage}) — giving up after {$attempts} attempts.");
                $abandoned++;

                if ($dryRun) {
                    continue;
                }

                $run->forceFill([
                    'status' => 'failed',
                    'last_error' => "Automatic recovery gave up after {$attempts} attempts; stalled at stage '{$run->current_stage}'. Needs investigation.",
                    'active_key' => null,
                ])->save();

                Log::error('pipeline:recover — abandoned stalled run after repeated attempts', $context);

                continue;
            }

            $this->line("  #{$run->id} (company {$run->company_id}, stage {$run->current_stage}, stalled {$stalledFor}m) — re-dispatching (attempt " . ($attempts + 1) . ").");
            $recovered++;

            if ($dryRun) {
                continue;
            }

            $metrics['auto_recovery_count'] = $attempts + 1;
            $metrics['last_auto_recovery_at'] = now()->toIso8601String();

            // Touch updated_at so the next watchdog pass gives this run a
            // fresh grace period rather than immediately re-recovering it.
            $run->forceFill([
                'status' => 'queued',
                'metrics' => $metrics,
                'updated_at' => now(),
            ])->save();

            $this->dispatchRun($run, $metrics);

            Log::warning('pipeline:recover — re-dispatched stalled run', $context);
        }

        $this->newLine();
        $this->info("Recovered: {$recovered}. Abandoned: {$abandoned}." . ($dryRun ? ' (dry run — nothing dispatched)' : ''));

        return self::SUCCESS;
    }

    /**
     * Re-dispatch the orchestrator in resume mode, preserving the run's
     * original metering context so a recovered weekly run keeps its credit
     * budget instead of silently becoming an unmetered admin-style run.
     */
    private function dispatchRun(PipelineRun $run, array $metrics): void
    {
        $isMetered = in_array(
            $metrics['source'] ?? null,
            ['weekly-pipeline', 'user-process-remaining'],
            true
        );

        AdminTestPipelineJob::dispatch(
            companyId: (int) $run->company_id,
            fromDate: $run->range_from?->toDateString()
                ?? CarbonImmutable::now('UTC')->subDay()->toDateString(),
            toDate: $run->range_to?->toDateString()
                ?? CarbonImmutable::now('UTC')->toDateString(),
            summarizeLimit: (int) ($metrics['summarize_limit'] ?? 5000),
            categorizeLimit: (int) ($metrics['categorize_limit'] ?? 5000),
            pipelineQueue: 'default',
            pipelineRunId: $run->id,
            isResume: true,
            maxCalls: $metrics['max_calls'] ?? null,
            trackCompanyLimit: $isMetered,
            maxSeconds: $metrics['max_seconds'] ?? null,
            deductCredits: $isMetered,
        )->onQueue('default');
    }
}
