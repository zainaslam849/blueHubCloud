<?php

namespace App\Jobs;

use App\Models\CompanyPbxAccount;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        Log::info('Pipeline Step 2: Queuing summarization jobs...', ['company_id' => $this->companyId]);
        QueueCallsForSummarizationJob::dispatch(
            $this->companyId,
            $this->summarizeLimit,
            25,
            $this->pipelineQueue
        )->onQueue($this->pipelineQueue);

        // STEP 3: Generate AI categories (async - runs after, skips if no summaries)
        Log::info('Pipeline Step 3: Dispatching AI category generation...', ['company_id' => $this->companyId]);
        GenerateAiCategoriesForCompanyJob::dispatch($this->companyId, $this->rangeDays)
            ->onQueue($this->pipelineQueue);

        // STEP 4: Categorize calls (async - will use categories created by step 3 or existing ones)
        Log::info('Pipeline Step 4: Queuing categorization jobs...', ['company_id' => $this->companyId]);
        QueueCallsForCategorizationJob::dispatch(
            $this->companyId,
            $this->categorizeLimit,
            25,
            false,
            $this->pipelineQueue
        )->onQueue($this->pipelineQueue);

        // STEP 5: Generate reports
        Log::info('Pipeline Step 5: Dispatching weekly reports generation...', ['company_id' => $this->companyId]);
        GenerateWeeklyPbxReportsJob::dispatch($from, $to)
            ->onQueue($this->pipelineQueue);

        Log::info('Admin test pipeline queued successfully', [
            'company_id' => $this->companyId,
            'ingest_accounts' => $accounts->count(),
            'queue' => $this->pipelineQueue,
            'note' => 'Jobs will execute asynchronously. Run: php artisan queue:work --queue=' . $this->pipelineQueue . ' --stop-when-empty',
        ]);
    }
}
