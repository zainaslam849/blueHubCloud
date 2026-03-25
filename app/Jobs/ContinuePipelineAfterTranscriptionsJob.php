<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\PipelineRun;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ContinuePipelineAfterTranscriptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public array $backoff = [15, 30, 60];

    public function __construct(
        public int $companyId,
        public string $fromDate,
        public string $toDate,
        public int $summarizeLimit = 500,
        public int $categorizeLimit = 500,
        public string $pipelineQueue = 'default',
        public ?int $pipelineRunId = null,
        public int $rangeDays = 30,
    ) {}

    public function handle(): void
    {
        $stageStartedAt = microtime(true);
        $from = CarbonImmutable::parse($this->fromDate, 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($this->toDate, 'UTC')->endOfDay();

        Log::info('ContinuePipelineAfterTranscriptionsJob: stage_start', [
            'company_id' => $this->companyId,
            'from' => $this->fromDate,
            'to' => $this->toDate,
            'pipeline_run_id' => $this->pipelineRunId,
            'queue' => $this->pipelineQueue,
            'job_id' => $this->job?->getJobId(),
            'attempt' => $this->attempts(),
            'event' => 'stage_start',
        ]);

        $verificationMetrics = $this->buildVerificationMetrics($from, $to);

        $pendingTranscriptions = Call::query()
            ->where('company_id', $this->companyId)
            ->where('status', 'answered')
            ->where('has_transcription', true)
            ->whereNull('transcript_text')
            ->whereBetween('started_at', [$from, $to])
            ->count();

        $oldestPending = Call::query()
            ->where('company_id', $this->companyId)
            ->where('status', 'answered')
            ->where('has_transcription', true)
            ->whereNull('transcript_text')
            ->whereBetween('started_at', [$from, $to])
            ->min('created_at');

        $oldestPendingAgeSeconds = $oldestPending
            ? now()->diffInSeconds(CarbonImmutable::parse($oldestPending), false)
            : null;

        if ($pendingTranscriptions > 0) {
            Log::info('ContinuePipelineAfterTranscriptionsJob: waiting for transcription stage to finish', [
                'company_id' => $this->companyId,
                'pending_transcriptions' => $pendingTranscriptions,
                'oldest_pending_age_seconds' => $oldestPendingAgeSeconds,
                'next_retry_in_seconds' => 20,
                'reason' => 'answered_calls_pending_verification',
                'verification_metrics' => $verificationMetrics,
                'pipeline_run_id' => $this->pipelineRunId,
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
                'event' => 'stage_blocked',
            ]);

            $pipelineRun = $this->pipelineRunId
                ? PipelineRun::query()->with('stages')->find($this->pipelineRunId)
                : null;
            if ($pipelineRun) {
                $pipelineRun->upsertStage('transcription_fetch', [
                    'status' => 'running',
                    'metrics' => array_merge($verificationMetrics, [
                        'pending_transcriptions' => $pendingTranscriptions,
                        'oldest_pending_age_seconds' => $oldestPendingAgeSeconds,
                        'blocked_reason' => 'answered_calls_pending_verification',
                    ]),
                ]);
            }

            FetchTranscriptionsJob::dispatch(
                $this->companyId,
                $this->fromDate,
                $this->toDate,
                $this->pipelineRunId,
                $this->pipelineQueue,
                $this->summarizeLimit,
                $this->categorizeLimit,
            )->onQueue($this->pipelineQueue);

            static::dispatch(
                $this->companyId,
                $this->fromDate,
                $this->toDate,
                $this->summarizeLimit,
                $this->categorizeLimit,
                $this->pipelineQueue,
                $this->pipelineRunId,
                $this->rangeDays,
            )->onQueue($this->pipelineQueue)->delay(now()->addSeconds(20));

            return;
        }

        Log::info('ContinuePipelineAfterTranscriptionsJob: barrier_cleared', [
            'company_id' => $this->companyId,
            'pipeline_run_id' => $this->pipelineRunId,
            'reason' => 'all_answered_calls_terminal_or_saved',
            'verification_metrics' => $verificationMetrics,
            'elapsed_ms' => (int) round((microtime(true) - $stageStartedAt) * 1000),
            'job_id' => $this->job?->getJobId(),
            'attempt' => $this->attempts(),
            'event' => 'stage_unblocked',
        ]);

        $pipelineRun = null;
        if ($this->pipelineRunId) {
            $pipelineRun = PipelineRun::query()->with('stages')->find($this->pipelineRunId);
        }

        if ($pipelineRun) {
            $summaryStageStatus = $pipelineRun->stageStatus('ai_summary');
            if (in_array($summaryStageStatus, ['queued', 'running', 'completed'], true)) {
                Log::info('ContinuePipelineAfterTranscriptionsJob: downstream stages already queued or completed, skipping', [
                    'company_id' => $this->companyId,
                    'pipeline_run_id' => $pipelineRun->id,
                    'ai_summary_stage_status' => $summaryStageStatus,
                ]);

                return;
            }

            $pipelineRun->upsertStage('transcription_fetch', [
                'status' => 'completed',
                'metrics' => array_merge($verificationMetrics, [
                    'pending_transcriptions' => 0,
                    'completed_at' => now()->toIso8601String(),
                ]),
                'finished_at' => now(),
            ]);
            $pipelineRun->markRunning('ai_summary');
            $pipelineRun->load('stages');
        }

        $callsToSummarize = Call::query()
            ->where('company_id', $this->companyId)
            ->whereNotNull('transcript_text')
            ->where('transcript_text', '!=', '')
            ->where(function ($q) {
                $q->whereNull('ai_summary')
                    ->orWhere('ai_summary', '');
            })
            ->whereBetween('started_at', [$from, $to])
            ->orderByDesc('started_at')
            ->limit($this->summarizeLimit)
            ->get(['id']);

        if ($callsToSummarize->isEmpty()) {
            Log::info('ContinuePipelineAfterTranscriptionsJob: no calls eligible for summarization', [
                'company_id' => $this->companyId,
                'pipeline_run_id' => $this->pipelineRunId,
                'from' => $this->fromDate,
                'to' => $this->toDate,
                'reason' => 'no_transcripts_available',
            ]);

            if ($pipelineRun) {
                $pipelineRun->upsertStage('ai_summary', [
                    'status' => 'completed',
                    'metrics' => [
                        'queued_summaries' => 0,
                        'reason' => 'no_transcripts_available',
                    ],
                    'finished_at' => now(),
                ]);
            }

            ContinuePipelineAfterSummariesJob::dispatch(
                $this->companyId,
                $this->fromDate,
                $this->toDate,
                $this->categorizeLimit,
                $this->pipelineQueue,
                $this->pipelineRunId,
                $this->rangeDays,
            )->onQueue($this->pipelineQueue)->delay(now()->addSeconds(5));

            return;
        }

        QueueCallsForSummarizationJob::dispatch(
            $this->companyId,
            $this->summarizeLimit,
            25,
            $this->pipelineQueue,
            $this->fromDate,
            $this->toDate,
            $this->pipelineRunId,
        )->onQueue($this->pipelineQueue);

        ContinuePipelineAfterSummariesJob::dispatch(
            $this->companyId,
            $this->fromDate,
            $this->toDate,
            $this->categorizeLimit,
            $this->pipelineQueue,
            $this->pipelineRunId,
            $this->rangeDays,
        )->onQueue($this->pipelineQueue)->delay(now()->addSeconds(20));

        if ($pipelineRun) {
            $pipelineRun->upsertStage('ai_summary', [
                'status' => 'queued',
                'metrics' => [
                    'queued_summaries' => $callsToSummarize->count(),
                ],
                'finished_at' => now(),
            ]);
            $pipelineRun->markQueued('ai_summary');
            $pipelineRun->forceFill([
                'metrics' => array_merge(
                    is_array($pipelineRun->metrics) ? $pipelineRun->metrics : [],
                    [
                        'summary_jobs_queued' => $callsToSummarize->count(),
                        'queued_at' => now()->toIso8601String(),
                    ]
                ),
            ])->save();
        }

        Log::info('ContinuePipelineAfterTranscriptionsJob: downstream stages queued', [
            'company_id' => $this->companyId,
            'queued_summaries' => $callsToSummarize->count(),
            'pipeline_run_id' => $this->pipelineRunId,
            'verification_metrics' => $verificationMetrics,
            'elapsed_ms' => (int) round((microtime(true) - $stageStartedAt) * 1000),
            'job_id' => $this->job?->getJobId(),
            'attempt' => $this->attempts(),
            'event' => 'stage_complete',
        ]);
    }

    private function buildVerificationMetrics(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $baseQuery = Call::query()
            ->where('company_id', $this->companyId)
            ->where('status', 'answered')
            ->whereBetween('started_at', [$from, $to]);

        $answeredTotal = (clone $baseQuery)->count();
        $saved = (clone $baseQuery)
            ->whereNotNull('transcript_text')
            ->where('transcript_text', '!=', '')
            ->count();
        $pending = (clone $baseQuery)
            ->where('has_transcription', true)
            ->whereNull('transcript_text')
            ->count();

        $terminal = max(0, $answeredTotal - $pending - $saved);
        $coveragePct = $answeredTotal > 0
            ? round(($saved / $answeredTotal) * 100, 2)
            : 100.0;

        return [
            'candidate_total' => $answeredTotal,
            'successful' => $saved,
            'remaining' => $pending,
            'terminal_non_transcript' => $terminal,
            'coverage_pct' => $coveragePct,
        ];
    }
}
