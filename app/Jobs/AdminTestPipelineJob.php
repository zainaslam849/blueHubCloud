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

        Log::info('Pipeline Step 2: Queuing summarization jobs...', [
            'company_id' => $this->companyId,
            'count' => $callsToSummarize->count(),
        ]);

        // Dispatch summarization jobs without batching (SummarizeSingleCallJob doesn't use Batchable trait)
        foreach ($callsToSummarize as $call) {
            SummarizeSingleCallJob::dispatch($call->id)
                ->onQueue($this->pipelineQueue);
        }

        Log::info('Pipeline Step 2: Queued summarization jobs, now queuing post-summary jobs...', [
            'company_id' => $this->companyId,
            'summary_count' => $callsToSummarize->count(),
        ]);

        // STEP 3-5: Chain category generation → categorization → reports
        // These will run after summaries are done (or immediately if no summaries)
        Bus::chain($postSummaryChain)
            ->onQueue($this->pipelineQueue)
            ->dispatch();

        Log::info('Admin test pipeline queued successfully', [
            'company_id' => $this->companyId,
            'ingest_accounts' => $accounts->count(),
            'queue' => $this->pipelineQueue,
            'note' => 'Jobs will execute asynchronously. Run: php artisan queue:work --queue=' . $this->pipelineQueue . ' --stop-when-empty',
        ]);
    }
}
