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
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class ContinuePipelineAfterSummariesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public array $backoff = [15, 30, 60];

    /**
     * Maximum number of times this job will re-dispatch itself while waiting
     * for pending summaries. At ~20s between polls this caps blocking at
     * roughly 20 minutes; after that the run advances anyway so it can never
     * loop forever.
     */
    private const MAX_POLL_ATTEMPTS = 60;

    public function __construct(
        public int $companyId,
        public string $fromDate,
        public string $toDate,
        public int $categorizeLimit = 500,
        public string $pipelineQueue = 'default',
        public ?int $pipelineRunId = null,
        public int $rangeDays = 30,
        public int $summarizeLimit = 500,
        public int $pollAttempts = 0,
    ) {}

    public function handle(): void
    {
        $from = CarbonImmutable::parse($this->fromDate, 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($this->toDate, 'UTC')->endOfDay();

        $pendingSummaries = Call::query()
            ->where('company_id', $this->companyId)
            ->whereNotNull('transcript_text')
            ->where('transcript_text', '!=', '')
            ->whereNull('ai_summary_status')
            ->where(function ($q) {
                $q->whereNull('ai_summary')
                    ->orWhere('ai_summary', '');
            })
            ->whereBetween('started_at', [$from, $to])
            ->count();

        if ($pendingSummaries > 0) {
            // RACE-CONDITION RECOVERY:
            // The upstream ContinuePipelineAfterTranscriptionsJob may have
            // short-circuited with reason="no_transcripts_available" because
            // transcripts were still being saved when it ran. By the time we
            // poll here, those late transcripts exist but no summarization
            // job was ever dispatched for them. Detect this on the FIRST
            // poll only, and dispatch QueueCallsForSummarizationJob ourselves
            // so the work actually gets done instead of polling forever.
            if ($this->pollAttempts === 0) {
                Log::warning('ContinuePipelineAfterSummariesJob: late-arriving transcripts detected, dispatching summarization recovery', [
                    'company_id' => $this->companyId,
                    'pending_summaries' => $pendingSummaries,
                    'pipeline_run_id' => $this->pipelineRunId,
                    'event' => 'late_transcript_recovery',
                ]);

                QueueCallsForSummarizationJob::dispatch(
                    $this->companyId,
                    max($this->summarizeLimit, $pendingSummaries),
                    25,
                    $this->pipelineQueue,
                    $this->fromDate,
                    $this->toDate,
                    $this->pipelineRunId,
                )->onQueue($this->pipelineQueue);

                if ($this->pipelineRunId) {
                    $run = PipelineRun::query()->find($this->pipelineRunId);
                    if ($run) {
                        $run->upsertStage('ai_summary', [
                            'status' => 'running',
                            'metrics' => [
                                'recovered_late_transcripts' => $pendingSummaries,
                                'recovered_at' => now()->toIso8601String(),
                            ],
                        ]);
                        $run->markRunning('ai_summary');
                    }
                }
            }

            // SAFETY CAP: stop polling forever. After MAX_POLL_ATTEMPTS,
            // mark the stage completed-with-timeout and let the pipeline move
            // on so categorization / report generation can still finish with
            // whatever summaries were produced.
            if ($this->pollAttempts >= self::MAX_POLL_ATTEMPTS) {
                Log::error('ContinuePipelineAfterSummariesJob: poll timeout reached, advancing pipeline with partial summaries', [
                    'company_id' => $this->companyId,
                    'pending_summaries' => $pendingSummaries,
                    'pipeline_run_id' => $this->pipelineRunId,
                    'poll_attempts' => $this->pollAttempts,
                    'event' => 'poll_timeout',
                ]);

                if ($this->pipelineRunId) {
                    $run = PipelineRun::query()->find($this->pipelineRunId);
                    if ($run) {
                        $run->upsertStage('ai_summary', [
                            'status' => 'completed',
                            'metrics' => [
                                'pending_summaries_at_timeout' => $pendingSummaries,
                                'reason' => 'poll_timeout',
                                'completed_at' => now()->toIso8601String(),
                            ],
                            'finished_at' => now(),
                        ]);
                    }
                }

                // Fall through to the "summaries done" branch below so the
                // category / report stages still get queued.
            } else {
                Log::info('ContinuePipelineAfterSummariesJob: waiting for summarization stage to finish', [
                    'company_id' => $this->companyId,
                    'pending_summaries' => $pendingSummaries,
                    'pipeline_run_id' => $this->pipelineRunId,
                    'poll_attempts' => $this->pollAttempts,
                ]);

                static::dispatch(
                    $this->companyId,
                    $this->fromDate,
                    $this->toDate,
                    $this->categorizeLimit,
                    $this->pipelineQueue,
                    $this->pipelineRunId,
                    $this->rangeDays,
                    $this->summarizeLimit,
                    $this->pollAttempts + 1,
                )->onQueue($this->pipelineQueue)->delay(now()->addSeconds(20));

                return;
            }
        }

        $pipelineRun = null;
        if ($this->pipelineRunId) {
            $pipelineRun = PipelineRun::query()->with('stages')->find($this->pipelineRunId);
        }

        $transcriptedCallsInRange = Call::query()
            ->where('company_id', $this->companyId)
            ->whereNotNull('transcript_text')
            ->where('transcript_text', '!=', '')
            ->whereBetween('started_at', [$from, $to])
            ->count();

        // Fast-path: if this range has zero transcripted calls, there is no
        // summary/categorization/report queue work to perform for this run.
        // Finalize immediately so runs do not remain queued waiting for work
        // that can never be produced.
        if ($transcriptedCallsInRange === 0) {
            if ($pipelineRun) {
                $pipelineRun->upsertStage('ai_summary', [
                    'status' => 'completed',
                    'metrics' => [
                        'pending_summaries' => 0,
                        'reason' => 'no_transcript_candidates',
                        'completed_at' => now()->toIso8601String(),
                    ],
                    'finished_at' => now(),
                ]);
                $pipelineRun->upsertStage('category_generation', [
                    'status' => 'completed',
                    'metrics' => [
                        'queued' => false,
                        'reason' => 'no_transcript_candidates',
                    ],
                    'finished_at' => now(),
                ]);
                $pipelineRun->upsertStage('call_categorization', [
                    'status' => 'completed',
                    'metrics' => [
                        'queued_calls' => 0,
                        'reason' => 'no_transcript_candidates',
                    ],
                    'finished_at' => now(),
                ]);
                $pipelineRun->upsertStage('report_generation', [
                    'status' => 'completed',
                    'metrics' => [
                        'queued' => false,
                        'reason' => 'no_transcript_candidates',
                    ],
                    'finished_at' => now(),
                ]);
                $pipelineRun->markCompleted('report_generation');
            }

            Log::info('ContinuePipelineAfterSummariesJob: no transcript candidates in range, pipeline finalized without queueing downstream stages', [
                'company_id' => $this->companyId,
                'pipeline_run_id' => $this->pipelineRunId,
                'from' => $this->fromDate,
                'to' => $this->toDate,
                'event' => 'no_transcript_fast_path_complete',
            ]);

            return;
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

        // Ensure category taxonomy generation completes before call categorization starts.
        Bus::chain([
            new GenerateAiCategoriesForCompanyJob(
                $this->companyId,
                $this->rangeDays,
                $this->pipelineRunId,
            ),
            new QueueCallsForCategorizationJob(
                $this->companyId,
                $this->categorizeLimit,
                25,
                false,
                $this->pipelineQueue,
                $this->fromDate,
                $this->toDate,
                $this->pipelineRunId
            ),
        ])->onQueue($this->pipelineQueue)->dispatch();

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
