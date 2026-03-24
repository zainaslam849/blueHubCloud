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

class ContinuePipelineAfterSummariesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public array $backoff = [15, 30, 60];

    public function __construct(
        public int $companyId,
        public string $fromDate,
        public string $toDate,
        public int $categorizeLimit = 500,
        public string $pipelineQueue = 'default',
        public ?int $pipelineRunId = null,
        public int $rangeDays = 30,
    ) {}

    public function handle(): void
    {
        $from = CarbonImmutable::parse($this->fromDate, 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($this->toDate, 'UTC')->endOfDay();

        $pendingSummaries = Call::query()
            ->where('company_id', $this->companyId)
            ->whereNotNull('transcript_text')
            ->where('transcript_text', '!=', '')
            ->where(function ($q) {
                $q->whereNull('ai_summary')
                    ->orWhere('ai_summary', '');
            })
            ->whereBetween('started_at', [$from, $to])
            ->count();

        if ($pendingSummaries > 0) {
            Log::info('ContinuePipelineAfterSummariesJob: waiting for summarization stage to finish', [
                'company_id' => $this->companyId,
                'pending_summaries' => $pendingSummaries,
                'pipeline_run_id' => $this->pipelineRunId,
            ]);

            static::dispatch(
                $this->companyId,
                $this->fromDate,
                $this->toDate,
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
            $categoryStageStatus = $pipelineRun->stageStatus('category_generation');
            if (in_array($categoryStageStatus, ['queued', 'running', 'completed'], true)) {
                Log::info('ContinuePipelineAfterSummariesJob: downstream stages already queued or completed, skipping', [
                    'company_id' => $this->companyId,
                    'pipeline_run_id' => $pipelineRun->id,
                    'category_generation_stage_status' => $categoryStageStatus,
                ]);

                return;
            }

            $pipelineRun->upsertStage('ai_summary', [
                'status' => 'completed',
                'metrics' => [
                    'pending_summaries' => 0,
                    'completed_at' => now()->toIso8601String(),
                ],
                'finished_at' => now(),
            ]);
            $pipelineRun->markRunning('category_generation');
            $pipelineRun->load('stages');
        }

        GenerateAiCategoriesForCompanyJob::dispatch($this->companyId, $this->rangeDays)
            ->onQueue($this->pipelineQueue);

        QueueCallsForCategorizationJob::dispatch(
            $this->companyId,
            $this->categorizeLimit,
            25,
            false,
            $this->pipelineQueue,
            $this->fromDate,
            $this->toDate,
            $this->pipelineRunId
        )->onQueue($this->pipelineQueue)->delay(now()->addSeconds(10));

        if ($pipelineRun) {
            $pipelineRun->upsertStage('category_generation', [
                'status' => 'queued',
                'metrics' => ['queued' => true],
                'finished_at' => now(),
            ]);
            $pipelineRun->upsertStage('call_categorization', [
                'status' => 'queued',
                'metrics' => ['queued_limit' => $this->categorizeLimit],
                'finished_at' => now(),
            ]);
            $pipelineRun->markQueued('call_categorization');
        }

        Log::info('ContinuePipelineAfterSummariesJob: category and report stages queued', [
            'company_id' => $this->companyId,
            'pipeline_run_id' => $this->pipelineRunId,
        ]);
    }
}
