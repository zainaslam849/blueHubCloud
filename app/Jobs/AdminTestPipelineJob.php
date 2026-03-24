<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\CompanyPbxAccount;
use App\Models\PipelineRun;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;
use function dispatch;

class AdminTestPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60; // Just for dispatching, actual work is async

    private const STAGE_CALL_DISCOVERY = 'call_discovery';
    private const STAGE_TRANSCRIPTION_FETCH = 'transcription_fetch';
    private const STAGE_AI_SUMMARY = 'ai_summary';
    private const STAGE_CATEGORY_GENERATION = 'category_generation';
    private const STAGE_CALL_CATEGORIZATION = 'call_categorization';
    private const STAGE_REPORT_GENERATION = 'report_generation';

    private ?PipelineRun $pipelineRun = null;
    private string $activeStage = self::STAGE_CALL_DISCOVERY;

    public function __construct(
        public int $companyId,
        public string $fromDate,
        public string $toDate,
        public int $summarizeLimit = 500,
        public int $categorizeLimit = 500,
        public string $pipelineQueue = 'default',
        public ?int $pipelineRunId = null,
        public bool $isResume = false,
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
            foreach ($accounts as $account) {
                Log::info('AdminTestPipelineJob - Dispatching ingest for account', ['account_id' => $account->id]);
                IngestPbxCallsJob::dispatchSync(
                    $this->companyId,
                    $account->id,
                    ['from' => $from, 'to' => $to]
                );
            }

            $this->completeStage(self::STAGE_CALL_DISCOVERY, [
                'ingest_accounts' => $accounts->count(),
            ]);
        }

        if (! $this->shouldSkipStage(self::STAGE_TRANSCRIPTION_FETCH)) {
            $this->startStage(self::STAGE_TRANSCRIPTION_FETCH);
            FetchTranscriptionsJob::dispatch()->onQueue($this->pipelineQueue);
            $this->completeStage(self::STAGE_TRANSCRIPTION_FETCH, [
                'mode' => 'queued',
            ], 'queued');
        }

        Log::info('AdminTestPipelineJob - Pipeline Step 1 complete: Ingest finished', ['company_id' => $this->companyId]);

        // STEP 2: Queue summarization (async - will process immediately)
        $skipSummary = $this->shouldSkipStage(self::STAGE_AI_SUMMARY);
        if (! $skipSummary) {
            $this->startStage(self::STAGE_AI_SUMMARY);
        }
        Log::info('AdminTestPipelineJob - Pipeline Step 2: Preparing summarization jobs...', ['company_id' => $this->companyId]);
        $callsToSummarize = Call::query()
            ->where('company_id', $this->companyId)
            ->whereNotNull('transcript_text')
            ->where('transcript_text', '!=', '')
            ->whereNull('ai_summary')
            ->orderByDesc('started_at')
            ->limit($this->summarizeLimit)
            ->get(['id']);

        Log::info('AdminTestPipelineJob - Calls to summarize', [
            'company_id' => $this->companyId,
            'count' => $callsToSummarize->count(),
        ]);

        $postSummaryChain = [
            new GenerateAiCategoriesForCompanyJob($this->companyId, $rangeDays),
            new QueueCallsForCategorizationJob(
                $this->companyId,
                $this->categorizeLimit,
                25,
                false,
                $this->pipelineQueue,
                $from,
                $to,
                $this->pipelineRun?->id
            ),
            // Note: GenerateWeeklyPbxReportsJob is now dispatched by QueueCallsForCategorizationJob
            // with a delay to ensure it runs AFTER categorization completes
        ];

        Log::info('AdminTestPipelineJob - Pipeline Step 2: Queuing summarization jobs...', [
            'company_id' => $this->companyId,
            'count' => $callsToSummarize->count(),
        ]);

        // Dispatch summarization jobs without batching (SummarizeSingleCallJob doesn't use Batchable trait)
        if ($skipSummary) {
            Log::info('AdminTestPipelineJob - Skipping ai_summary stage during resume', [
                'company_id' => $this->companyId,
                'pipeline_run_id' => $this->pipelineRun?->id,
            ]);
        } else {
            foreach ($callsToSummarize as $call) {
                SummarizeSingleCallJob::dispatch($call->id)
                    ->onQueue($this->pipelineQueue);
            }

            $this->completeStage(self::STAGE_AI_SUMMARY, [
                'queued_summaries' => $callsToSummarize->count(),
            ], 'queued');

            $this->completeStage(self::STAGE_CATEGORY_GENERATION, [
                'queued' => true,
            ], 'queued');

            $this->completeStage(self::STAGE_CALL_CATEGORIZATION, [
                'queued_limit' => $this->categorizeLimit,
            ], 'queued');

            $this->completeStage(self::STAGE_REPORT_GENERATION, [
                'queued' => true,
            ], 'queued');
        }

        Log::info('AdminTestPipelineJob - Pipeline Step 2: Queued summarization jobs, now queuing post-summary jobs...', [
            'company_id' => $this->companyId,
            'summary_count' => $callsToSummarize->count(),
        ]);

        // STEP 3-5: Chain category generation → categorization → reports
        // These will run after summaries are done (or immediately if no summaries)
        if ($callsToSummarize->count() > 0) {
            // Chain them after summarization jobs if there are any
            Bus::chain($postSummaryChain)
                ->onQueue($this->pipelineQueue)
                ->dispatch();
            Log::info('AdminTestPipelineJob - Post-summary jobs chained after summarization', ['company_id' => $this->companyId]);
        } else {
            // If no summarization jobs, dispatch post-summary jobs directly (don't wait)
            Log::info('AdminTestPipelineJob - No summarization jobs; dispatching post-summary jobs directly', ['company_id' => $this->companyId]);
            foreach ($postSummaryChain as $job) {
                dispatch($job->onQueue($this->pipelineQueue));
            }
        }

        if ($this->pipelineRun) {
            $this->pipelineRun->markQueued(self::STAGE_REPORT_GENERATION);
            $this->pipelineRun->forceFill([
                'metrics' => array_merge(
                    is_array($this->pipelineRun->metrics) ? $this->pipelineRun->metrics : [],
                    [
                        'summary_jobs_queued' => $callsToSummarize->count(),
                        'ingest_accounts' => $accounts->count(),
                        'queued_at' => now()->toIso8601String(),
                    ]
                ),
            ])->save();
        }

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
