<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyPbxAccount;
use App\Services\Providers\PbxwareAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * PHASE 7: TENANT AUTO-SYNC
 * 
 * Command: php artisan pbx:sync-tenants
 * 
 * Automatically syncs all PBXware tenants (servers) to the database.
 * 
 * Logic:
 * 1. Call pbxware.tenant.list endpoint
 * 2. For each tenant on PBXware:
 *    a. Check if it exists in pbx_accounts (by server_id)
 *    b. If not, link to default company (or prompt for company_id)
 *    c. Update sync metadata
 * 3. Allow manual activate/deactivate of accounts
 * 
 * No manual server ID entry required - all automated via API.
 */
class SyncPbxTenantsCommand extends Command
{
    protected $signature = 'pbx:sync-tenants 
                            {--company-id= : Company ID to link new tenants to (default: 1)}
                            {--activate : Activate synced tenants}
                            {--deactivate= : Deactivate specific tenant by server_id}
                            {--verbose : Show detailed sync information}';

    protected $description = 'Sync all PBXware tenants from API to database automatically';

    private ?PbxwareAdapter $adapter = null;

    public function handle(): int
    {
        try {
            $this->info('🔷 Syncing PBXware Tenants...');
            
            // Lazy load adapter only when needed (in handle, not constructor)
            $this->adapter = new PbxwareAdapter();

            // Fetch all tenants from PBXware API
            $tenants = $this->adapter->listTenants();

            if (empty($tenants)) {
                $this->warn('No tenants found on PBXware');
                return 0;
            }

            $tenantCount = $tenants['count'] ?? count($tenants);
            $this->info("Found {$tenantCount} tenants on PBXware");

            // Get company ID for linking
            $companyId = $this->option('company-id') ?? config('app.default_company_id') ?? 1;

            $company = Company::findOrFail($companyId);
            $this->info("Linking tenants to company: {$company->name}");

            // Sync each tenant
            $synced = 0;
            $updated = 0;
            $failed = 0;

            $tenantList = $tenants['data'] ?? $tenants;

            foreach ($tenantList as $tenant) {
                try {
                    if ($this->syncTenant($tenant, $company)) {
                        $synced++;
                    } else {
                        $updated++;
                    }
                } catch (\Exception $e) {
                    $this->error("Failed to sync tenant {$tenant['id']}: {$e->getMessage()}");
                    $failed++;
                }
            }

            // Handle deactivation if requested
            if ($this->option('deactivate')) {
                $this->deactivateTenant($this->option('deactivate'));
            }

            // Summary
            $this->info("\n✓ Sync Complete");
            $this->info("  Synced (new): {$synced}");
            $this->info("  Updated: {$updated}");
            if ($failed > 0) {
                $this->warn("  Failed: {$failed}");
            }

            Log::info('pbx:sync-tenants completed', [
                'synced' => $synced,
                'updated' => $updated,
                'failed' => $failed,
            ]);

            return 0;
        } catch (\Exception $e) {
            $this->error("Sync failed: {$e->getMessage()}");
            Log::error('pbx:sync-tenants failed', ['error' => $e->getMessage()]);

            return 1;
        }
    }

    /**
     * Sync a single tenant to database.
     *
     * @param array $tenant Tenant data from API
     * @param Company $company Company to link to
     * @return bool True if newly created, false if updated
     */
    private function syncTenant(array $tenant, Company $company): bool
    {
        $serverId = $tenant['id'] ?? $tenant['server_id'];
        $tenantName = $tenant['name'] ?? $tenant['company_name'] ?? "Server {$serverId}";

        // Check if already exists
        $pbxAccount = CompanyPbxAccount::where('company_id', $company->id)
            ->where('server_id', $serverId)
            ->first();

        if ($pbxAccount) {
            // Update existing
            $pbxAccount->update([
                'name' => $tenantName,
                'pbx_type' => 'pbxware',
                'api_version' => 'v7',
                'last_synced_at' => now(),
            ]);

            if ($this->option('verbose')) {
                $this->line("  ↻ Updated: {$tenantName} ({$serverId})");
            }

            return false;
        }

        // Create new
        CompanyPbxAccount::create([
            'company_id' => $company->id,
            'name' => $tenantName,
            'server_id' => $serverId,
            'pbx_type' => 'pbxware',
            'api_version' => 'v7',
            'is_active' => $this->option('activate') ? true : false,
            'last_synced_at' => now(),
        ]);

        $this->line("  ✓ Created: {$tenantName} ({$serverId})");

        return true;
    }

    /**
     * Deactivate a specific tenant account.
     *
     * @param string $serverId Server ID to deactivate
     * @return void
     */
    private function deactivateTenant(string $serverId): void
    {
        $pbxAccount = CompanyPbxAccount::where('server_id', $serverId)->first();

        if (!$pbxAccount) {
            $this->warn("Tenant {$serverId} not found");
            return;
        }

        $pbxAccount->update(['is_active' => false]);
        $this->info("✓ Deactivated: {$pbxAccount->name}");
    }
}
