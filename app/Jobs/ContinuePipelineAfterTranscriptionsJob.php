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
        $from = CarbonImmutable::parse($this->fromDate, 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($this->toDate, 'UTC')->endOfDay();

        $pendingTranscriptions = Call::query()
            ->where('company_id', $this->companyId)
            ->where('has_transcription', true)
            ->whereNull('transcript_text')
            ->whereBetween('started_at', [$from, $to])
            ->count();

        if ($pendingTranscriptions > 0) {
            Log::info('ContinuePipelineAfterTranscriptionsJob: waiting for transcription stage to finish', [
                'company_id' => $this->companyId,
                'pending_transcriptions' => $pendingTranscriptions,
                'pipeline_run_id' => $this->pipelineRunId,
            ]);

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
                'metrics' => [
                    'pending_transcriptions' => 0,
                    'completed_at' => now()->toIso8601String(),
                ],
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
        ]);
    }
}
