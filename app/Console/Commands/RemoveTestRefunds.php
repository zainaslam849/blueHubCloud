<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CreditTransaction;
use Illuminate\Console\Command;

/**
 * Removes the "refund" credit-ledger rows created by this session's testing
 * commands (reports:reset / reports:reset-week / reports:reset-all) — the
 * only places in the codebase that ever create a TYPE_REFUND transaction.
 *
 * Deleting these rows is purely a history cleanup: CompanyCreditBalance's
 * balance is its own stored running total, not recalculated from the ledger,
 * so removing a refund row does NOT change any company's current balance.
 * The credits those refunds granted stay exactly where they are.
 */
class RemoveTestRefunds extends Command
{
    protected $signature = 'credits:remove-test-refunds {--dry-run : Show what would be deleted without changing anything} {--yes : Skip the confirmation prompt}';

    protected $description = 'Delete refund-type credit_transactions rows created by this session\'s reports:reset* testing commands (does not touch any balance)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $rows = CreditTransaction::where('type', CreditTransaction::TYPE_REFUND)->get();

        if ($rows->isEmpty()) {
            $this->info('No refund transactions found. Nothing to do.');

            return self::SUCCESS;
        }

        $byCompany = $rows->groupBy('company_id');

        $this->line("Refund transactions found: {$rows->count()}");
        $this->line('Total credits involved: '.number_format((float) $rows->sum('credits'), 4));
        $this->newLine();

        foreach ($byCompany as $companyId => $group) {
            $company = Company::withTrashed()->find($companyId);
            $this->line(sprintf(
                '  #%d %-30s %d row(s), %s credits',
                $companyId,
                $company?->name ?? '(deleted)',
                $group->count(),
                number_format((float) $group->sum('credits'), 4),
            ));
        }

        $this->newLine();
        $this->comment('This only removes the ledger history rows — no company\'s credit balance changes.');

        if ($dryRun) {
            $this->newLine();
            $this->info('[dry run] Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm('Delete these refund history rows?')) {
            $this->comment('Cancelled.');

            return self::SUCCESS;
        }

        $deleted = CreditTransaction::where('type', CreditTransaction::TYPE_REFUND)->delete();

        $this->newLine();
        $this->info("Done — deleted {$deleted} refund transaction(s). Balances are unchanged.");

        return self::SUCCESS;
    }
}
