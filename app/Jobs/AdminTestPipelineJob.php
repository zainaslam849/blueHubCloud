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
    public int $timeout = 30;

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

        QueueCallsForSummarizationJob::dispatch(
            $this->companyId,
            $this->summarizeLimit,
            25,
            $this->pipelineQueue
        )
            ->delay(now()->addMinutes(2))
            ->onQueue($this->pipelineQueue);

        GenerateAiCategoriesForCompanyJob::dispatch($this->companyId, $this->rangeDays)
            ->delay(now()->addMinutes(4))
            ->onQueue($this->pipelineQueue);

        QueueCallsForCategorizationJob::dispatch(
            $this->companyId,
            $this->categorizeLimit,
            25,
            false,
            $this->pipelineQueue
        )
            ->delay(now()->addMinutes(5))
            ->onQueue($this->pipelineQueue);

        GenerateWeeklyPbxReportsJob::dispatch($from, $to)
            ->delay(now()->addMinutes(7))
            ->onQueue($this->pipelineQueue);

        Log::info('Admin test pipeline queued', [
            'company_id' => $this->companyId,
            'ingest_accounts' => $accounts->count(),
            'queue' => $this->pipelineQueue,
        ]);
    }
}
