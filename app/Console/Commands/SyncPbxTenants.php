<?php

namespace App\Console\Commands;

use App\Models\PbxProvider;
use App\Models\TenantSyncSetting;
use App\Services\PbxwareClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncPbxTenants extends Command
{
    protected $signature = 'pbx:sync-tenants {--provider-id=}';

    protected $description = 'Sync tenants for PBXware providers based on scheduled settings';

    protected PbxwareClient $pbxwareClient;

    public function __construct()
    {
        parent::__construct();
        $this->pbxwareClient = new PbxwareClient();
    }

    public function handle()
    {
        $this->info('Starting PBXware tenant sync...');

        // Get providers to sync
        if ($this->option('provider-id')) {
            $syncSettings = TenantSyncSetting::where(
                'pbx_provider_id',
                $this->option('provider-id')
            )
                ->where('enabled', true)
                ->get();
        } else {
            $syncSettings = TenantSyncSetting::where('enabled', true)
                ->get();
        }

        if ($syncSettings->isEmpty()) {
            $this->info('No tenant sync settings enabled.');
            return Command::SUCCESS;
        }

        $totalSynced = 0;

        foreach ($syncSettings as $setting) {
            try {
                $shouldSync = $setting->shouldSyncNow();
                Log::info(
                    'Tenant sync check',
                    [
                        'provider_id' => $setting->pbx_provider_id,
                        'provider_name' => $setting->pbxProvider->name,
                        'frequency' => $setting->frequency,
                        'should_sync' => $shouldSync,
                        'last_synced_at' => $setting->last_synced_at,
                    ]
                );
                
                if (!$shouldSync) {
                    $this->info(
                        "⏭️  Provider {$setting->pbxProvider->name} ({$setting->frequency}): Not yet time to sync (last: {$setting->last_synced_at})"
                    );
                    continue;
                }

                $this->info(
                    "🔄 Syncing tenants for {$setting->pbxProvider->name}..."
                );

                // Call the sync endpoint from AdminCompaniesController logic
                $result = $this->syncTenantsForProvider($setting->pbx_provider_id);

                $setting->update([
                    'last_synced_at' => now(),
                    'last_sync_count' => $result['total_synced'] ?? 0,
                    'last_sync_log' => json_encode($result),
                ]);

                $totalSynced += $result['total_synced'] ?? 0;

                $this->info(
                    "✅ {$setting->pbxProvider->name}: {$result['created_companies']} created, {$result['linked_companies']} linked, {$result['skipped_companies']} skipped"
                );

                Log::info(
                    'Tenant sync completed',
                    [
                        'provider_id' => $setting->pbx_provider_id,
                        'result' => $result,
                    ]
                );
            } catch (\Exception $e) {
                $this->error(
                    "❌ Failed to sync {$setting->pbxProvider->name}: {$e->getMessage()}"
                );

                $setting->update([
                    'last_sync_log' => json_encode([
                        'error' => $e->getMessage(),
                        'timestamp' => now(),
                    ]),
                ]);

                Log::error(
                    'Tenant sync failed',
                    [
                        'provider_id' => $setting->pbx_provider_id,
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }

        $this->info("✨ Sync complete. Total companies synced: {$totalSynced}");
        return Command::SUCCESS;
    }

    /**
     * Sync tenants for a specific provider
     */
    private function syncTenantsForProvider(int $providerId): array
    {
        $tenants = $this->pbxwareClient->fetchTenantList();

        if (empty($tenants)) {
            return [
                'created_companies' => 0,
                'linked_companies' => 0,
                'skipped_companies' => 0,
                'total_synced' => 0,
            ];
        }

        $createdCompanies = 0;
        $linkedCompanies = 0;
        $skippedCompanies = 0;

        foreach ($tenants as $serverId => $tenantData) {
            try {
                $tenantCode = $tenantData['tenantcode'] ?? null;

                // Upsert pbxware_tenants
                $existing = \App\Models\PbxwareTenant::where(
                    'server_id',
                    (string) $serverId
                )
                    ->where('pbx_provider_id', $providerId)
                    ->first();

                if ($existing) {
                    $existing->update([
                        'name' => $tenantData['name'] ?? null,
                        'tenant_code' => $tenantCode,
                        'package_name' => $tenantData['package'] ?? null,
                        'package_id' => $tenantData['package_id'] ?? null,
                        'ext_length' => $tenantData['ext_length'] ?? null,
                        'country_id' => $tenantData['country_id'] ?? null,
                        'country_code' => $tenantData['country_code'] ?? null,
                        'raw_data' => $tenantData,
                        'synced_at' => now(),
                    ]);
                } else {
                    \App\Models\PbxwareTenant::create([
                        'pbx_provider_id' => $providerId,
                        'server_id' => (string) $serverId,
                        'tenant_code' => $tenantCode,
                        'name' => $tenantData['name'] ?? null,
                        'package_name' => $tenantData['package'] ?? null,
                        'package_id' => $tenantData['package_id'] ?? null,
                        'ext_length' => $tenantData['ext_length'] ?? null,
                        'country_id' => $tenantData['country_id'] ?? null,
                        'country_code' => $tenantData['country_code'] ?? null,
                        'raw_data' => $tenantData,
                        'synced_at' => now(),
                    ]);
                }

                // Update existing accounts
                \App\Models\CompanyPbxAccount::where(
                    'server_id',
                    (string) $serverId
                )
                    ->where('pbx_provider_id', $providerId)
                    ->where(function ($query) use ($tenantCode, $tenantData) {
                        $query->whereNull('tenant_code')
                            ->orWhereNull('package_name')
                            ->orWhere('tenant_code', '!=', $tenantCode)
                            ->orWhere(
                                'package_name',
                                '!=',
                                $tenantData['package'] ?? null
                            );
                    })
                    ->update([
                        'tenant_code' => $tenantCode,
                        'package_name' => $tenantData['package'] ?? null,
                        'pbx_synced_at' => now(),
                    ]);

                // Auto-create and link companies
                $tenantName = $tenantData['name'] ?? null;
                if (is_string($tenantName) && trim($tenantName) !== '') {
                    $tenantName = trim($tenantName);
                    $company = \App\Models\Company::where(
                        'name',
                        $tenantName
                    )->first();

                    // If not found, check for soft-deleted company and restore
                    if (!$company) {
                        $trashedCompany = \App\Models\Company::onlyTrashed()
                            ->where('name', $tenantName)
                            ->first();
                        
                        if ($trashedCompany) {
                            $trashedCompany->restore();
                            $company = $trashedCompany;
                            Log::info('Restored soft-deleted company during sync', [
                                'company_id' => $company->id,
                                'name' => $tenantName,
                            ]);
                        } else {
                            $company = \App\Models\Company::create([
                                'name' => $tenantName,
                                'timezone' => 'UTC',
                                'status' => 'inactive',
                            ]);
                            $createdCompanies++;
                        }
                    }

                    $providerAccountQuery = \App\Models\CompanyPbxAccount::where(
                        'company_id',
                        $company->id
                    )->where('pbx_provider_id', $providerId);

                    $existingAccount = $providerAccountQuery
                        ->where('server_id', (string) $serverId)
                        ->first();

                    if (
                        !$existingAccount &&
                        !$providerAccountQuery->exists()
                    ) {
                        $serverTaken = \App\Models\CompanyPbxAccount::where(
                            'server_id',
                            (string) $serverId
                        )
                            ->where('pbx_provider_id', $providerId)
                            ->where('company_id', '!=', $company->id)
                            ->exists();

                        $tenantCodeTaken = !empty($tenantCode)
                            ? \App\Models\CompanyPbxAccount::where(
                                'tenant_code',
                                $tenantCode
                            )
                                ->where(
                                    'server_id',
                                    '!=',
                                    (string) $serverId
                                )
                                ->exists()
                            : false;

                        if (!$serverTaken && !$tenantCodeTaken) {
                            \App\Models\CompanyPbxAccount::create([
                                'company_id' => $company->id,
                                'pbx_provider_id' => $providerId,
                                'server_id' => (string) $serverId,
                                'tenant_code' => $tenantCode,
                                'package_name' => $tenantData['package'] ?? null,
                                'status' => $company->status === 'active'
                                    ? 'active'
                                    : 'inactive',
                                'pbx_synced_at' => now(),
                            ]);
                            $linkedCompanies++;
                        } else {
                            $skippedCompanies++;
                        }
                    } elseif (!$existingAccount) {
                        $skippedCompanies++;
                    }
                }
            } catch (\Exception $e) {
                Log::error(
                    'Error processing tenant during sync',
                    [
                        'server_id' => $serverId,
                        'error' => $e->getMessage(),
                    ]
                );
                $skippedCompanies++;
            }
        }

        return [
            'created_companies' => $createdCompanies,
            'linked_companies' => $linkedCompanies,
            'skipped_companies' => $skippedCompanies,
            'total_synced' =>
                $createdCompanies + $linkedCompanies,
        ];
    }
}
