<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.3 — Multi-PBX invariants verification.
 *
 * Reports (and exits non-zero on) violations that would break provider
 * isolation in a multi-PBX deployment. Read-only: makes NO changes.
 *
 *   1. company_pbx_accounts must have at most one row per
 *      (pbx_provider_id, server_id).
 *   2. pbxware_tenants must have at most one row per
 *      (pbx_provider_id, server_id).
 *   3. For every CompanyPbxAccount with a tenant_code, the matching
 *      PbxwareTenant must belong to the same pbx_provider_id.
 */
class PbxVerifyInvariants extends Command
{
    protected $signature = 'pbx:verify-invariants {--json : Emit machine-readable JSON output}';

    protected $description = 'Verify multi-PBX schema invariants (provider isolation). Read-only.';

    public function handle(): int
    {
        $violations = [
            'duplicate_company_pbx_accounts' => $this->checkDuplicateCompanyPbxAccounts(),
            'duplicate_pbxware_tenants' => $this->checkDuplicatePbxwareTenants(),
            'cross_provider_tenant_mismatch' => $this->checkCrossProviderTenantMismatch(),
        ];

        $totalViolations = array_sum(array_map('count', $violations));

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $totalViolations === 0,
                'violations' => $violations,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $totalViolations === 0 ? self::SUCCESS : self::FAILURE;
        }

        if ($totalViolations === 0) {
            $this->info('All multi-PBX invariants hold.');

            return self::SUCCESS;
        }

        $this->error("Found {$totalViolations} invariant violation(s):");

        foreach ($violations as $kind => $rows) {
            if (empty($rows)) {
                continue;
            }

            $this->newLine();
            $this->warn(strtoupper(str_replace('_', ' ', $kind)) . ' (' . count($rows) . ')');
            foreach ($rows as $row) {
                $this->line('  - ' . json_encode($row, JSON_UNESCAPED_SLASHES));
            }
        }

        return self::FAILURE;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function checkDuplicateCompanyPbxAccounts(): array
    {
        if (! Schema::hasTable('company_pbx_accounts')) {
            return [];
        }

        return DB::table('company_pbx_accounts')
            ->select('pbx_provider_id', 'server_id', DB::raw('COUNT(*) as occurrences'))
            ->whereNotNull('pbx_provider_id')
            ->whereNotNull('server_id')
            ->groupBy('pbx_provider_id', 'server_id')
            ->having('occurrences', '>', 1)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function checkDuplicatePbxwareTenants(): array
    {
        if (! Schema::hasTable('pbxware_tenants')) {
            return [];
        }

        return DB::table('pbxware_tenants')
            ->select('pbx_provider_id', 'server_id', DB::raw('COUNT(*) as occurrences'))
            ->whereNotNull('pbx_provider_id')
            ->whereNotNull('server_id')
            ->groupBy('pbx_provider_id', 'server_id')
            ->having('occurrences', '>', 1)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * Find CompanyPbxAccount rows whose tenant_code matches a PbxwareTenant
     * on a DIFFERENT pbx_provider_id (cross-provider leakage).
     *
     * @return array<int, array<string, mixed>>
     */
    private function checkCrossProviderTenantMismatch(): array
    {
        if (! Schema::hasTable('company_pbx_accounts') || ! Schema::hasTable('pbxware_tenants')) {
            return [];
        }

        if (! Schema::hasColumn('company_pbx_accounts', 'tenant_code')) {
            return [];
        }

        return DB::table('company_pbx_accounts as cpa')
            ->join('pbxware_tenants as pt', 'pt.tenant_code', '=', 'cpa.tenant_code')
            ->whereColumn('pt.pbx_provider_id', '!=', 'cpa.pbx_provider_id')
            ->select(
                'cpa.id as company_pbx_account_id',
                'cpa.company_id',
                'cpa.pbx_provider_id as account_provider_id',
                'pt.pbx_provider_id as tenant_provider_id',
                'cpa.tenant_code',
                'cpa.server_id'
            )
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }
}
