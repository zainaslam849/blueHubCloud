<?php

namespace App\Console\Commands;

use App\Jobs\GenerateWeeklyPbxReportsJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillWeeklyReportsCommand extends Command
{
    protected $signature = 'reports:backfill
                            {--company= : Specific company ID to backfill}
                            {--from= : Start date (YYYY-MM-DD) to scan for missing reports}
                            {--to= : End date (YYYY-MM-DD) to scan for missing reports}
                            {--dry-run : Show what would be generated without actually generating}
                            {--sync : Run synchronously instead of dispatching to queue}';

    protected $description = 'Detect weeks with calls but missing reports and generate them';

    public function handle(): int
    {
        $companyId = $this->option('company') ? (int) $this->option('company') : null;
        $fromDate = $this->option('from');
        $toDate = $this->option('to');
        $dryRun = (bool) $this->option('dry-run');
        $sync = (bool) $this->option('sync');

        $this->info('🔍 Scanning for weeks with calls but missing reports...');
        $this->newLine();

        // Get all companies or specific one
        $companiesQuery = DB::table('companies')->select(['id', 'name', 'timezone']);
        if ($companyId) {
            $companiesQuery->where('id', $companyId);
        }
        $companies = $companiesQuery->orderBy('id')->get();

        if ($companies->isEmpty()) {
            $this->error($companyId ? "Company ID {$companyId} not found." : 'No companies found.');

            return self::FAILURE;
        }

        $totalMissing = 0;
        $totalGenerated = 0;
        $missingByCompany = [];

        foreach ($companies as $company) {
            $timezone = is_string($company->timezone) && $company->timezone !== '' ? $company->timezone : 'UTC';

            $missingWeeks = $this->findMissingWeeks(
                (int) $company->id,
                $timezone,
                $fromDate,
                $toDate
            );

            if (empty($missingWeeks)) {
                continue;
            }

            $missingByCompany[(int) $company->id] = [
                'name' => $company->name,
                'weeks' => $missingWeeks,
            ];
            $totalMissing += count($missingWeeks);
        }

        if ($totalMissing === 0) {
            $this->info('✅ No missing reports found. All weeks with calls have reports.');

            return self::SUCCESS;
        }

        // Display summary
        $this->info("Found {$totalMissing} missing report(s) across ".count($missingByCompany).' company(ies):');
        $this->newLine();

        foreach ($missingByCompany as $cid => $data) {
            $this->line("  <fg=cyan>Company #{$cid}</> - {$data['name']}");
            foreach ($data['weeks'] as $week) {
                $this->line("    • Week of {$week['week_start']} (PBX Account #{$week['pbx_account_id']}, {$week['call_count']} calls)");
            }
            $this->newLine();
        }

        if ($dryRun) {
            $this->warn('🔶 Dry run mode - no reports generated.');

            return self::SUCCESS;
        }

        // Confirm before proceeding
        if (! $this->option('no-interaction') && ! $this->confirm("Generate {$totalMissing} missing report(s)?", true)) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        // Generate missing reports
        $this->newLine();
        $this->info('📊 Generating missing reports...');

        $progressBar = $this->output->createProgressBar($totalMissing);
        $progressBar->start();

        foreach ($missingByCompany as $cid => $data) {
            foreach ($data['weeks'] as $week) {
                $weekStart = $week['week_start'];
                $weekEnd = CarbonImmutable::parse($weekStart)->addDays(6)->toDateString();

                $this->logProgress("Generating report for Company #{$cid}, Week of {$weekStart}");

                if ($sync) {
                    // Run synchronously
                    $job = new GenerateWeeklyPbxReportsJob($weekStart, $weekEnd);
                    $job->handle();
                } else {
                    // Dispatch to queue
                    GenerateWeeklyPbxReportsJob::dispatch($weekStart, $weekEnd);
                }

                $totalGenerated++;
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        if ($sync) {
            $this->info("✅ Successfully generated {$totalGenerated} report(s).");
        } else {
            $this->info("✅ Dispatched {$totalGenerated} report generation job(s) to the queue.");
            $this->line('   Run <fg=yellow>php artisan queue:work</> to process them.');
        }

        return self::SUCCESS;
    }

    /**
     * Find weeks that have calls but no corresponding weekly report.
     *
     * @return array<array{week_start: string, pbx_account_id: int, call_count: int}>
     */
    private function findMissingWeeks(
        int $companyId,
        string $timezone,
        ?string $fromDate,
        ?string $toDate
    ): array {
        // Get all distinct weeks with calls for this company
        $callsQuery = DB::table('calls')
            ->select([
                'company_pbx_account_id',
                DB::raw('MIN(started_at) as first_call'),
                DB::raw('COUNT(*) as call_count'),
            ])
            ->where('company_id', $companyId)
            ->whereNotNull('started_at')
            ->whereNotNull('company_pbx_account_id')
            ->groupBy('company_pbx_account_id');

        if ($fromDate) {
            $callsQuery->where('started_at', '>=', $fromDate.' 00:00:00');
        }
        if ($toDate) {
            $callsQuery->where('started_at', '<=', $toDate.' 23:59:59');
        }

        // Get raw call data grouped by PBX account
        $pbxAccounts = $callsQuery->get();

        $missingWeeks = [];

        foreach ($pbxAccounts as $pbxAccount) {
            $pbxAccountId = (int) $pbxAccount->company_pbx_account_id;

            // Get all distinct weeks for this PBX account
            $weeksWithCalls = $this->getWeeksWithCalls(
                $companyId,
                $pbxAccountId,
                $timezone,
                $fromDate,
                $toDate
            );

            // Get existing reports for this PBX account
            $existingReports = DB::table('weekly_call_reports')
                ->where('company_id', $companyId)
                ->where('company_pbx_account_id', $pbxAccountId)
                ->pluck('week_start_date')
                ->map(fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : (string) $d)
                ->toArray();

            // Find missing weeks
            foreach ($weeksWithCalls as $week) {
                if (! in_array($week['week_start'], $existingReports, true)) {
                    $missingWeeks[] = [
                        'week_start' => $week['week_start'],
                        'pbx_account_id' => $pbxAccountId,
                        'call_count' => $week['call_count'],
                    ];
                }
            }
        }

        // Sort by week start date
        usort($missingWeeks, fn ($a, $b) => $a['week_start'] <=> $b['week_start']);

        return $missingWeeks;
    }

    /**
     * Get all weeks that have calls for a specific PBX account.
     *
     * @return array<array{week_start: string, call_count: int}>
     */
    private function getWeeksWithCalls(
        int $companyId,
        int $pbxAccountId,
        string $timezone,
        ?string $fromDate,
        ?string $toDate
    ): array {
        $query = DB::table('calls')
            ->select('started_at')
            ->where('company_id', $companyId)
            ->where('company_pbx_account_id', $pbxAccountId)
            ->whereNotNull('started_at');

        if ($fromDate) {
            $query->where('started_at', '>=', $fromDate.' 00:00:00');
        }
        if ($toDate) {
            $query->where('started_at', '<=', $toDate.' 23:59:59');
        }

        $calls = $query->orderBy('started_at')->get();

        $weekCounts = [];

        foreach ($calls as $call) {
            if (! $call->started_at) {
                continue;
            }

            $startedAt = CarbonImmutable::parse($call->started_at, 'UTC')
                ->setTimezone($timezone);

            $weekStart = $startedAt->startOfWeek(CarbonImmutable::MONDAY)->toDateString();

            if (! isset($weekCounts[$weekStart])) {
                $weekCounts[$weekStart] = 0;
            }
            $weekCounts[$weekStart]++;
        }

        $weeks = [];
        foreach ($weekCounts as $weekStart => $count) {
            $weeks[] = [
                'week_start' => $weekStart,
                'call_count' => $count,
            ];
        }

        return $weeks;
    }

    /**
     * Log progress message (to log file, not console).
     */
    private function logProgress(string $message): void
    {
        logger()->info("[BackfillWeeklyReports] {$message}");
    }
}
