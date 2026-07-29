<?php
namespace App\Jobs;

use App\Models\CompanyPbxAccount;
use App\Models\PipelineRun;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminTestPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300; // call_discovery runs dispatchSync (synchronous PBX ingest); allow up to 5 min

    private const STAGE_CALL_DISCOVERY = 'call_discovery';
    private const STAGE_TRANSCRIPTION_FETCH = 'transcription_fetch';
    private const STAGE_AI_SUMMARY = 'ai_summary';
    private const STAGE_CATEGORY_GENERATION = 'category_generation';
    private const STAGE_CALL_CATEGORIZATION = 'call_categorization';
    private const STAGE_REPORT_GENERATION = 'report_generation';

    public ?PipelineRun $pipelineRun = null;
    public string $activeStage = self::STAGE_CALL_DISCOVERY;

    public function __construct(
        public int $companyId,
        public string $fromDate,
        public string $toDate,
        public int $summarizeLimit = 500,
        public int $categorizeLimit = 500,
        public string $pipelineQueue = 'default',
        public ?int $pipelineRunId = null,
        public bool $isResume = false,
        // Maximum NEW calls to ingest this run (null = unlimited). Legacy
        // call-count cap; the weekly run uses the credit budget instead.
        public ?int $maxCalls = null,
        // When true, record per-week fetch overflow after discovery. Off for
        // admin manual/test runs.
        public bool $trackCompanyLimit = false,
        // Credit budget in seconds of call time (null = unlimited). Shared
        // across all of the company's PBX accounts within this run.
        public ?int $maxSeconds = null,
        // When true, each NEW call deducts prorated credits from the
        // company's balance during ingest.
        public bool $deductCredits = false,
    ) {}

    public function handle(): void
    {
        $this->pipelineRun = $this->pipelineRunId
            ? PipelineRun::query()->with('stages')->find($this->pipelineRunId)
            : null;

        Log::info('AdminTestPipelineJob::handle() - STARTING', [
            'company_id' => $this->companyId,
            'from' => $this->fromDate,
            'to' => $this->toDate,
            'queue' => $this->pipelineQueue,
            'pipeline_run_id' => $this->pipelineRun?->id,
            'is_resume' => $this->isResume,
        ]);

        $to = CarbonImmutable::parse($this->toDate, 'UTC')->toDateString();
        $from = CarbonImmutable::parse($this->fromDate, 'UTC')->toDateString();
        $rangeDays = max(1, CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($to, 'UTC')));

        Log::info('AdminTestPipelineJob::handle() - Date range', ['from' => $from, 'to' => $to]);

        // STEP 1: Ingest calls (synchronous - must complete first)
        Log::info('AdminTestPipelineJob - Pipeline Step 1: Ingesting calls...', ['company_id' => $this->companyId]);
        $skipCallDiscovery = $this->shouldSkipStage(self::STAGE_CALL_DISCOVERY);
        if (! $skipCallDiscovery) {
            $this->startStage(self::STAGE_CALL_DISCOVERY);
        }

        $accounts = CompanyPbxAccount::query()
            ->where('company_id', $this->companyId)
            ->where('status', 'active')
            ->get(['id']);

        Log::info('AdminTestPipelineJob - Step 1: Found accounts to ingest', [
            'company_id' => $this->companyId,
            'account_count' => $accounts->count(),
        ]);

        if ($skipCallDiscovery) {
            Log::info('AdminTestPipelineJob - Skipping call discovery stage during resume', [
                'company_id' => $this->companyId,
                'pipeline_run_id' => $this->pipelineRun?->id,
            ]);
        } else {
            $discoveryMetrics = [
                'ingest_accounts' => $accounts->count(),
                'calls_created' => 0,
                'calls_skipped_existing' => 0,
                'calls_available_new' => 0,
                'calls_blocked_by_limit' => 0,
                'calls_blocked_by_credits' => 0,
                'seconds_consumed' => 0,
                'credits_deducted' => 0.0,
                'split_window_retries' => 0,
                'strict_lossless_discovery' => true,
                'transcription_inline_attempts' => 0,
                'transcriptions_stored_inline' => 0,
                'transcription_async_verification_candidates' => 0,
                'transcription_skipped_no_recording' => 0,
                'transcription_inline_not_found' => 0,
            ];

            // Remaining caps shrink as each account creates calls, so the
            // per-company limits are honored across multiple PBX accounts
            // (possibly on different servers) within a single run.
            $remainingCap = $this->maxCalls;
            $remainingSeconds = $this->maxSeconds;

            foreach ($accounts as $account) {
                Log::info('AdminTestPipelineJob - Dispatching ingest for account', [
                    'account_id' => $account->id,
                    'remaining_cap' => $remainingCap,
                    'remaining_seconds' => $remainingSeconds,
                ]);
                // Invoke handle() directly: dispatchSync() on a ShouldQueue job
                // returns the sync-queue push result (0), NOT handle()'s
                // metrics array, which silently discarded all discovery
                // accounting. Direct invocation is still synchronous.
                $ingestResult = (new IngestPbxCallsJob(
                    $this->companyId,
                    $account->id,
                    ['from' => $from, 'to' => $to],
                    $remainingCap,
                    $remainingSeconds,
                    $this->deductCredits
                ))->handle();

                if (is_array($ingestResult)) {
                    $created = (int) ($ingestResult['calls_created'] ?? 0);
                    $discoveryMetrics['calls_created'] += $created;
                    $discoveryMetrics['calls_skipped_existing'] += (int) ($ingestResult['calls_skipped_existing'] ?? 0);
                    $discoveryMetrics['calls_available_new'] += (int) ($ingestResult['calls_available_new'] ?? 0);
                    $discoveryMetrics['calls_blocked_by_limit'] += (int) ($ingestResult['calls_blocked_by_limit'] ?? 0);
                    $discoveryMetrics['calls_blocked_by_credits'] += (int) ($ingestResult['calls_blocked_by_credits'] ?? 0);
                    $discoveryMetrics['seconds_consumed'] += (int) ($ingestResult['seconds_consumed'] ?? 0);
                    $discoveryMetrics['credits_deducted'] += (float) ($ingestResult['credits_deducted'] ?? 0);
                    $discoveryMetrics['split_window_retries'] += (int) ($ingestResult['split_window_retries'] ?? 0);
                    $discoveryMetrics['strict_lossless_discovery'] =
                        $discoveryMetrics['strict_lossless_discovery']
                        && (bool) ($ingestResult['strict_lossless_discovery'] ?? true);
                    $discoveryMetrics['transcription_inline_attempts'] += (int) ($ingestResult['transcription_inline_attempts'] ?? 0);
                    $discoveryMetrics['transcriptions_stored_inline'] += (int) ($ingestResult['transcriptions_stored_inline'] ?? 0);
                    $discoveryMetrics['transcription_async_verification_candidates'] += (int) ($ingestResult['transcription_async_verification_candidates'] ?? 0);
                    $discoveryMetrics['transcription_skipped_no_recording'] += (int) ($ingestResult['transcription_skipped_no_recording'] ?? 0);
                    $discoveryMetrics['transcription_inline_not_found'] += (int) ($ingestResult['transcription_inline_not_found'] ?? 0);

                    if ($remainingCap !== null) {
                        $remainingCap = max(0, $remainingCap - $created);
                    }
                    if ($remainingSeconds !== null) {
                        $remainingSeconds = max(0, $remainingSeconds - (int) ($ingestResult['seconds_consumed'] ?? 0));
                    }
                }
            }

            // Record company-limit usage + per-week overflow when running as a tracked
            // (weekly / user-resume) pipeline. Admin manual runs skip this entirely.
            if ($this->trackCompanyLimit) {
                $this->recordCompanyLimitUsage($from, $to, $discoveryMetrics);
            }

            $this->completeStage(self::STAGE_CALL_DISCOVERY, $discoveryMetrics);
        }

        if (! $this->shouldSkipStage(self::STAGE_TRANSCRIPTION_FETCH)) {
            $this->startStage(self::STAGE_TRANSCRIPTION_FETCH);

            $transcriptionCandidates = \App\Models\Call::query()
                ->where('company_id', $this->companyId)
                ->where('status', 'answered')
                ->whereBetween('started_at', [
                    CarbonImmutable::parse($from, 'UTC')->startOfDay(),
                    CarbonImmutable::parse($to, 'UTC')->endOfDay(),
                ])
                ->count();

            // Fast-path: if there are no answered calls to verify for transcripts,
            // there is nothing for summary/categorization queues to process.
            // Complete the remaining stages immediately so runs do not sit in a
            // queued state waiting for work that can never exist.
            if ($transcriptionCandidates === 0) {
                $this->completeStage(self::STAGE_TRANSCRIPTION_FETCH, [
                    'mode' => 'skipped_no_candidates',
                    'candidate_total' => 0,
                    'completed_at' => now()->toIso8601String(),
                ]);
                $this->completeStage(self::STAGE_AI_SUMMARY, [
                    'queued_summaries' => 0,
                    'reason' => 'no_transcript_candidates',
                ]);
                $this->completeStage(self::STAGE_CATEGORY_GENERATION, [
                    'queued' => false,
                    'reason' => 'no_transcript_candidates',
                ]);
                $this->completeStage(self::STAGE_CALL_CATEGORIZATION, [
                    'queued_calls' => 0,
                    'reason' => 'no_transcript_candidates',
                ]);
                $this->completeStage(self::STAGE_REPORT_GENERATION, [
                    'queued' => false,
                    'reason' => 'no_transcript_candidates',
                ]);

                if ($this->pipelineRun) {
                    $this->pipelineRun->markCompleted(self::STAGE_REPORT_GENERATION);
                    $existingMetrics = is_array($this->pipelineRun->metrics) ? $this->pipelineRun->metrics : [];
                    $this->pipelineRun->forceFill([
                        'metrics' => array_merge($existingMetrics, [
                            'transcription_candidate_total' => 0,
                            'no_transcript_fast_path' => true,
                        ]),
                    ])->save();
                }

                Log::info('AdminTestPipelineJob - no transcript candidates; pipeline marked completed without queueing downstream stages', [
                    'company_id' => $this->companyId,
                    'pipeline_run_id' => $this->pipelineRun?->id,
                    'from' => $from,
                    'to' => $to,
                    'event' => 'no_transcript_fast_path_complete',
                ]);

                return;
            }

            FetchTranscriptionsJob::dispatch(
                $this->companyId,
                $from,
                $to,
                $this->pipelineRun?->id,
                $this->pipelineQueue,
                $this->summarizeLimit,
                $this->categorizeLimit,
            )->onQueue($this->pipelineQueue);
            $this->completeStage(self::STAGE_TRANSCRIPTION_FETCH, [
                'mode' => 'queued',
                'candidate_total' => $transcriptionCandidates,
                'queued_at' => now()->toIso8601String(),
            ], 'queued');

            Log::info('AdminTestPipelineJob - transcription stage queued', [
                'company_id' => $this->companyId,
                'pipeline_run_id' => $this->pipelineRun?->id,
                'candidate_total' => $transcriptionCandidates,
                'event' => 'stage_queued',
            ]);
        }

        Log::info('AdminTestPipelineJob - Pipeline Step 1 complete: Ingest finished', ['company_id' => $this->companyId]);

        // Step barrier: continue only after transcription stage reaches terminal state.
        ContinuePipelineAfterTranscriptionsJob::dispatch(
            $this->companyId,
            $from,
            $to,
            $this->summarizeLimit,
            $this->categorizeLimit,
            $this->pipelineQueue,
            $this->pipelineRun?->id,
            $rangeDays,
        )->onQueue($this->pipelineQueue)->delay(now()->addSeconds(10));

        Log::info('AdminTestPipelineJob::handle() - COMPLETE', ['company_id' => $this->companyId]);

        Log::info('Admin test pipeline queued successfully', [
            'company_id' => $this->companyId,
            'ingest_accounts' => $accounts->count(),
            'queue' => $this->pipelineQueue,
            'note' => 'Jobs will execute asynchronously. Run: php artisan queue:work --queue=' . $this->pipelineQueue . ' --stop-when-empty',
        ]);
    }

    public function failed(Throwable $exception): void
    {
        if (! $this->pipelineRunId) {
            return;
        }

        $pipelineRun = PipelineRun::query()->find($this->pipelineRunId);
        if (! $pipelineRun) {
            return;
        }

        $pipelineRun->markFailed($this->activeStage, $exception->getMessage());
        $pipelineRun->upsertStage($this->activeStage, [
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'finished_at' => now(),
        ]);
    }

    /**
     * Record the company's monthly call-limit usage and per-week overflow after
     * a tracked discovery stage (weekly auto-run or user-initiated resume).
     */
    private function recordCompanyLimitUsage(string $from, string $to, array $discoveryMetrics): void
    {
        $created   = (int) ($discoveryMetrics['calls_created'] ?? 0);
        $available = (int) ($discoveryMetrics['calls_available_new'] ?? 0);
        $blocked   = (int) ($discoveryMetrics['calls_blocked_by_limit'] ?? 0);

        // Legacy call-count metering only: when a call-count cap was set,
        // keep the consumed counter accurate. Credit-metered runs deduct
        // per call at ingest instead.
        if ($created > 0 && $this->maxCalls !== null) {
            \App\Models\Company::query()
                ->where('id', $this->companyId)
                ->increment('call_limit_used', $created);
        }

        $weekStart = CarbonImmutable::parse($from, 'UTC')->toDateString();
        $weekEnd   = CarbonImmutable::parse($to, 'UTC')->toDateString();

        $existing = \App\Models\CompanyWeeklyFetch::query()
            ->where('company_id', $this->companyId)
            ->where('week_start_date', $weekStart)
            ->first();

        // Merge with any prior run for the same week (e.g. a resume after raising
        // the limit) so totals reflect cumulative fetched/blocked for that week.
        $priorFetched = $existing?->calls_fetched ?? 0;
        $totalFetched = $priorFetched + $created;
        $totalAvailable = max($available, $existing?->calls_available ?? 0, $totalFetched + $blocked);
        $totalBlocked = max(0, $totalAvailable - $totalFetched);

        $status = $totalBlocked > 0
            ? \App\Models\CompanyWeeklyFetch::STATUS_PARTIAL
            : \App\Models\CompanyWeeklyFetch::STATUS_COMPLETE;

        \App\Models\CompanyWeeklyFetch::query()->updateOrCreate(
            [
                'company_id' => $this->companyId,
                'week_start_date' => $weekStart,
            ],
            [
                'week_end_date'     => $weekEnd,
                'calls_available'   => $totalAvailable,
                'calls_fetched'     => $totalFetched,
                'calls_blocked'     => $totalBlocked,
                'status'            => $status,
                'last_attempted_at' => now(),
                'completed_at'      => $totalBlocked > 0 ? null : now(),
            ],
        );

        Log::info('AdminTestPipelineJob - recorded company limit usage', [
            'company_id' => $this->companyId,
            'week_start' => $weekStart,
            'created' => $created,
            'available' => $totalAvailable,
            'blocked' => $totalBlocked,
            'status' => $status,
        ]);
    }

    private function startStage(string $stageKey): void
    {
        if ($this->shouldSkipStage($stageKey)) {
            return;
        }

        $this->activeStage = $stageKey;
        if (! $this->pipelineRun) {
            return;
        }

        $this->pipelineRun->markRunning($stageKey);
        $this->pipelineRun->upsertStage($stageKey, [
            'status' => 'running',
            'error_message' => null,
            'started_at' => now(),
        ]);
        $this->pipelineRun->load('stages');
    }

    private function completeStage(string $stageKey, array $metrics = [], string $status = 'completed'): void
    {
        if ($this->shouldSkipStage($stageKey)) {
            return;
        }

        if (! $this->pipelineRun) {
            return;
        }

        $this->pipelineRun->upsertStage($stageKey, [
            'status' => $status,
            'metrics' => $metrics,
            'finished_at' => now(),
        ]);
        $this->pipelineRun->load('stages');
    }

    private function shouldSkipStage(string $stageKey): bool
    {
        if (! $this->isResume || ! $this->pipelineRun) {
            return false;
        }

        $status = $this->pipelineRun->stageStatus($stageKey);

        return in_array($status, ['completed', 'queued'], true);
    }
}
