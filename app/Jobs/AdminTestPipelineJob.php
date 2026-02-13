<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\CompanyPbxAccount;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminTestPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60; // Just for dispatching, actual work is async

    public function __construct(
        public int $companyId,
        public int $rangeDays = 30,
        public int $summarizeLimit = 500,
        public int $categorizeLimit = 500,
        public string $pipelineQueue = 'default'
    ) {}

    public function handle(): void
    {
        Log::info('Admin test pipeline started', [
            'company_id' => $this->companyId,
            'range_days' => $this->rangeDays,
        ]);

        $to = CarbonImmutable::now('UTC')->toDateString();
        $from = CarbonImmutable::now('UTC')->subDays($this->rangeDays)->toDateString();

        // STEP 1: Ingest calls (synchronous - must complete first)
        Log::info('Pipeline Step 1: Ingesting calls...', ['company_id' => $this->companyId]);
        $accounts = CompanyPbxAccount::query()
            ->where('company_id', $this->companyId)
            ->where('status', 'active')
            ->get(['id']);

        foreach ($accounts as $account) {
            IngestPbxCallsJob::dispatchSync(
                $this->companyId,
                $account->id,
                ['from' => $from, 'to' => $to]
            );
        }
        Log::info('Pipeline Step 1 complete: Ingest finished', ['company_id' => $this->companyId]);

        // STEP 2: Queue summarization (async - will process immediately)
        Log::info('Pipeline Step 2: Preparing summarization jobs...', ['company_id' => $this->companyId]);
        $callsToSummarize = Call::query()
            ->where('company_id', $this->companyId)
            ->whereNotNull('transcript_text')
            ->where('transcript_text', '!=', '')
            ->whereNull('ai_summary')
            ->orderByDesc('started_at')
            ->limit($this->summarizeLimit)
            ->get(['id']);

        $postSummaryChain = [
            new GenerateAiCategoriesForCompanyJob($this->companyId, $this->rangeDays),
            new QueueCallsForCategorizationJob(
                $this->companyId,
                $this->categorizeLimit,
                25,
                false,
                $this->pipelineQueue
            ),
            new GenerateWeeklyPbxReportsJob($from, $to),
        ];

        if ($callsToSummarize->isEmpty()) {
            Log::info('Pipeline Step 2: No calls to summarize, skipping directly to category generation', [
                'company_id' => $this->companyId,
            ]);

            Bus::chain($postSummaryChain)
                ->onQueue($this->pipelineQueue)
                ->dispatch();
        } else {
            $summaryJobs = $callsToSummarize
                ->map(fn ($call) => (new SummarizeSingleCallJob($call->id))->onQueue($this->pipelineQueue))
                ->all();

            Log::info('Pipeline Step 2: Queuing summarization batch...', [
                'company_id' => $this->companyId,
                'count' => $callsToSummarize->count(),
            ]);

            Bus::batch($summaryJobs)
                ->name('pipeline-summarize-company-' . $this->companyId)
                ->onQueue($this->pipelineQueue)
                ->then(function () use ($postSummaryChain) {
                    Bus::chain($postSummaryChain)
                        ->onQueue($this->pipelineQueue)
                        ->dispatch();
                })
                ->catch(function (Throwable $e) {
                    Log::error('Pipeline Step 2: Summarization batch failed', [
                        'company_id' => $this->companyId,
                        'error' => $e->getMessage(),
                    ]);
                })
                ->dispatch();
        }

        Log::info('Admin test pipeline queued successfully', [
            'company_id' => $this->companyId,
            'ingest_accounts' => $accounts->count(),
            'queue' => $this->pipelineQueue,
            'note' => 'Jobs will execute asynchronously. Run: php artisan queue:work --queue=' . $this->pipelineQueue . ' --stop-when-empty',
        ]);
    }
}
