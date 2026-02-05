<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAiCategoriesForCompanyJob;
use App\Models\Company;
use Illuminate\Console\Command;

class GenerateAiCategoriesCommand extends Command
{
    protected $signature = 'ai:generate-categories {--company= : Company ID} {--range=30 : Number of days to include}';

    protected $description = 'Generate AI categories for a company using recent call summaries.';

    public function handle(): int
    {
        $companyId = $this->option('company');
        $rangeDays = (int) $this->option('range');
        $rangeDays = $rangeDays > 0 ? $rangeDays : 30;

        if ($companyId) {
            $company = Company::find((int) $companyId);
        } else {
            $company = Company::orderBy('id')->first();
        }

        if (! $company) {
            $this->error('No company found to generate categories.');
            return self::FAILURE;
        }

        GenerateAiCategoriesForCompanyJob::dispatch($company->id, $rangeDays);

        $this->info("Queued AI category generation for company {$company->id} (last {$rangeDays} days).");

        return self::SUCCESS;
    }
}
