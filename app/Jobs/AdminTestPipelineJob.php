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
use function dispatch;

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
        Log::info('AdminTestPipelineJob::handle() - STARTING', [
            'company_id' => $this->companyId,
            'range_days' => $this->rangeDays,
            'queue' => $this->pipelineQueue,
        ]);

        $to = CarbonImmutable::now('UTC')->toDateString();
        $from = CarbonImmutable::now('UTC')->subDays($this->rangeDays)->toDateString();

        Log::info('AdminTestPipelineJob::handle() - Date range', ['from' => $from, 'to' => $to]);

        // STEP 1: Ingest calls (synchronous - must complete first)
        Log::info('AdminTestPipelineJob - Pipeline Step 1: Ingesting calls...', ['company_id' => $this->companyId]);
        $accounts = CompanyPbxAccount::query()
            ->where('company_id', $this->companyId)
            ->where('status', 'active')
            ->get(['id']);

        Log::info('AdminTestPipelineJob - Step 1: Found accounts to ingest', [
            'company_id' => $this->companyId,
            'account_count' => $accounts->count(),
        ]);

        foreach ($accounts as $account) {
            Log::info('AdminTestPipelineJob - Dispatching ingest for account', ['account_id' => $account->id]);
            IngestPbxCallsJob::dispatchSync(
                $this->companyId,
                $account->id,
                ['from' => $from, 'to' => $to]
            );
        }
        Log::info('AdminTestPipelineJob - Pipeline Step 1 complete: Ingest finished', ['company_id' => $this->companyId]);

        // STEP 2: Queue summarization (async - will process immediately)
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

        Log::info('AdminTestPipelineJob - Pipeline Step 2: Queuing summarization jobs...', [
            'company_id' => $this->companyId,
            'count' => $callsToSummarize->count(),
        ]);

        // Dispatch summarization jobs without batching (SummarizeSingleCallJob doesn't use Batchable trait)
        foreach ($callsToSummarize as $call) {
            SummarizeSingleCallJob::dispatch($call->id)
                ->onQueue($this->pipelineQueue);
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

        Log::info('AdminTestPipelineJob::handle() - COMPLETE', ['company_id' => $this->companyId]);

        Log::info('Admin test pipeline queued successfully', [
            'company_id' => $this->companyId,
            'ingest_accounts' => $accounts->count(),
            'queue' => $this->pipelineQueue,
            'note' => 'Jobs will execute asynchronously. Run: php artisan queue:work --queue=' . $this->pipelineQueue . ' --stop-when-empty',
        ]);
    }
}
