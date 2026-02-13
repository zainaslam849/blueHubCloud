<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAiCategoriesForCompanyJob;
use App\Models\Company;
use Illuminate\Console\Command;

class GenerateAiCategoriesCallsCommand extends Command
{
    protected $signature = 'calls:generate-ai-categories
                          {--company= : Company ID (optional)}
                          {--range=30 : Number of days to include}';

    protected $description = 'Generate AI categories for calls using recent call summaries.';

    public function handle(): int
    {
        $companyId = $this->option('company');
        $rangeDays = (int) $this->option('range');
        $rangeDays = $rangeDays > 0 ? $rangeDays : 30;

        if ($companyId) {
            $company = Company::find((int) $companyId);

            if (! $company) {
                $this->error('No company found to generate categories.');
                return self::FAILURE;
            }

            GenerateAiCategoriesForCompanyJob::dispatch($company->id, $rangeDays);

            $this->info("Queued AI category generation for company {$company->id} (last {$rangeDays} days).");
            return self::SUCCESS;
        }

        $companies = Company::query()->orderBy('id')->get(['id']);

        if ($companies->isEmpty()) {
            $this->warn('No companies found.');
            return self::SUCCESS;
        }

        foreach ($companies as $company) {
            GenerateAiCategoriesForCompanyJob::dispatch($company->id, $rangeDays);
        }

        $this->info("Queued AI category generation for all companies (last {$rangeDays} days).");
        $this->line("Companies: {$companies->count()}");

        return self::SUCCESS;
    }
}
