<?php

namespace App\Console\Commands;

use App\Jobs\QueueCallsForSummarizationJob;
use App\Models\Company;
use Illuminate\Console\Command;

class SummarizeCallsCommand extends Command
{
    protected $signature = 'calls:summarize
                          {--company= : Company ID (optional)}
                          {--limit=500 : Maximum number of calls to summarize}
                          {--batch=25 : Batch size for queued jobs}
                          {--queue=summarization : Queue name for summarize jobs}';

    protected $description = 'Queue AI summaries for calls with transcripts (company-scoped).';

    public function handle(): int
    {
        $companyId = $this->option('company');
        $limit = (int) $this->option('limit');
        $batch = (int) $this->option('batch');
        $queue = (string) ($this->option('queue') ?? 'summarization');

        $limit = $limit > 0 ? $limit : 500;
        $batch = $batch > 0 ? $batch : 25;

        if ($companyId) {
            $company = Company::find((int) $companyId);

            if (! $company) {
                $this->error('No company found to summarize calls.');
                return self::FAILURE;
            }

            QueueCallsForSummarizationJob::dispatch($company->id, $limit, $batch, $queue)
                ->onQueue('default');

            $this->info("Queued call summarization for company {$company->id} (limit {$limit}).");
            return self::SUCCESS;
        }

        $companies = Company::query()->orderBy('id')->get(['id']);

        if ($companies->isEmpty()) {
            $this->warn('No companies found.');
            return self::SUCCESS;
        }

        foreach ($companies as $company) {
            QueueCallsForSummarizationJob::dispatch($company->id, $limit, $batch, $queue)
                ->onQueue('default');
        }

        $this->info('Queued call summarization for all companies.');
        $this->line("Companies: {$companies->count()} | Limit: {$limit} | Batch: {$batch}");

        return self::SUCCESS;
    }
}
