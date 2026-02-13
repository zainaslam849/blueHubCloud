<?php

namespace App\Console\Commands;

use App\Jobs\AdminTestPipelineJob;
use App\Models\Company;
use Illuminate\Console\Command;

class RunFullPipelineCommand extends Command
{
    protected $signature = 'calls:run-full-pipeline
                          {--company= : Company ID (optional)}
                          {--range=30 : Number of days to include}
                          {--summarize-limit=500 : Max calls to summarize}
                          {--categorize-limit=500 : Max calls to categorize}
                          {--queue=default : Pipeline queue name}';

    protected $description = 'Run full call pipeline: ingest, summarize, generate categories, categorize, and reports.';

    public function handle(): int
    {
        $companyId = $this->option('company');
        $rangeDays = max(1, (int) $this->option('range'));
        $summarizeLimit = max(1, (int) $this->option('summarize-limit'));
        $categorizeLimit = max(1, (int) $this->option('categorize-limit'));
        $queue = (string) ($this->option('queue') ?? 'default');

        if ($companyId) {
            $company = Company::find((int) $companyId);

            if (! $company) {
                $this->error('No company found to run pipeline.');
                return self::FAILURE;
            }

            AdminTestPipelineJob::dispatch(
                $company->id,
                $rangeDays,
                $summarizeLimit,
                $categorizeLimit,
                $queue
            )->onQueue($queue);

            $this->info("Pipeline queued for company {$company->id} (range {$rangeDays} days)." );
            return self::SUCCESS;
        }

        $companies = Company::query()->orderBy('id')->get(['id']);

        if ($companies->isEmpty()) {
            $this->warn('No companies found.');
            return self::SUCCESS;
        }

        foreach ($companies as $company) {
            AdminTestPipelineJob::dispatch(
                $company->id,
                $rangeDays,
                $summarizeLimit,
                $categorizeLimit,
                $queue
            )->onQueue($queue);
        }

        $this->info("Pipeline queued for all companies (range {$rangeDays} days).");
        $this->line("Companies: {$companies->count()} | Queue: {$queue}");

        return self::SUCCESS;
    }
}
