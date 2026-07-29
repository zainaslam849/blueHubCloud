<?php

namespace App\Console\Commands;

use App\Models\PbxProvider;
use App\Models\TenantSyncSetting;
use App\Services\Pbx\PbxClientResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncPbxTenants extends Command
{
    protected $signature = 'pbx:sync-tenants {--provider-id=}';

    protected $description = 'Sync tenants for PBXware servers based on scheduled settings';

    // NOTE: Do NOT build a PBX client via __construct. Laravel resolves
    // every registered command's constructor during artisan boot/discovery,
    // and client construction performs Secrets Manager I/O. A client is
    // built per server inside handle(), only when that server is synced.

    public function handle()
    {
        $this->info('Starting PBXware tenant sync...');
        $hadFailures = false;
        $requestedProviderId = $this->option('provider-id');

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
                $server = $setting->pbxProvider;
                if (! $server || $server->status !== 'active') {
                    $this->info("⏭️  Server for sync setting #{$setting->id} is missing or inactive; skipping.");
                    continue;
                }

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

                $result = $this->syncTenantsForServer($server);

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
                $hadFailures = true;
                $friendlyError = $this->buildFriendlySyncError($e->getMessage());

                $this->error(
                    "❌ Failed to sync {$setting->pbxProvider->name}: {$friendlyError}"
                );

                $setting->update([
                    'last_sync_log' => json_encode([
                        'error' => $friendlyError,
                        'raw_error' => $e->getMessage(),
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

        // For manual provider-specific trigger from admin UI, report failure
        // with a non-zero exit so API can surface a clear message.
        if ($requestedProviderId && $hadFailures) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function buildFriendlySyncError(string $rawMessage): string
    {
        $message = trim($rawMessage);
        $lower = strtolower($message);

        $isConnectTimeout = str_contains($lower, 'curl error 28') ||
            str_contains($lower, 'failed to connect') ||
            str_contains($lower, "couldn't connect to server");

        if ($isConnectTimeout) {
            return 'PBX host is unreachable from this server. Check PBX base URL, DNS/firewall/VPN rules, and ensure outbound HTTPS to the PBX host is allowed.';
        }

        return $message;
    }

    /**
     * Sync tenants for a specific PBX server using that server's own
     * credentials.
     */
    private function syncTenantsForServer(PbxProvider $server): array
    {
        $providerId = $server->id;
        $client = PbxClientResolver::resolve($server);
        $tenants = $client->fetchTenantList();

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

                // Auto-create and link companies.
                // Company resolution is SERVER-SCOPED: tenant names are not
                // globally unique across servers, so a same-named tenant on a
                // different server must become a separate company (duplicates
                // arrive under different IDs).
                $tenantName = $tenantData['name'] ?? null;
                if (is_string($tenantName) && trim($tenantName) !== '') {
                    $tenantName = trim($tenantName);

                    // 1) The account already mapped to this exact (server, tenant) wins.
                    $mappedAccount = \App\Models\CompanyPbxAccount::where('pbx_provider_id', $providerId)
                        ->where('server_id', (string) $serverId)
                        ->first();

                    if ($mappedAccount) {
                        $company = \App\Models\Company::withTrashed()->find($mappedAccount->company_id);
                    } else {
                        // 2) Name match restricted to companies already on this server.
                        $company = \App\Models\Company::withTrashed()
                            ->where('name', $tenantName)
                            ->whereHas('companyPbxAccounts', function ($query) use ($providerId) {
                                $query->where('pbx_provider_id', $providerId);
                            })
                            ->first();
                    }

                    // Skip if company is soft-deleted (don't restore or create duplicate)
                    if ($company && $company->trashed()) {
                        $skippedCompanies++;
                        Log::info('Skipped soft-deleted company during sync', [
                            'company_id' => $company->id,
                            'name' => $tenantName,
                            'pbx_provider_id' => $providerId,
                        ]);
                        continue;
                    }

                    // 3) No company on this server matches: create a fresh one.
                    if (!$company) {
                        $company = \App\Models\Company::create([
                            'name' => $tenantName,
                            'timezone' => 'UTC',
                            'status' => 'inactive',
                        ]);
                        $createdCompanies++;
                        Log::info('Auto-created company during tenant sync', [
                            'company_id' => $company->id,
                            'name' => $tenantName,
                            'pbx_provider_id' => $providerId,
                            'server_id' => (string) $serverId,
                        ]);
                    }
                    // If company exists (active/inactive), keep its current status - don't change it

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

                        // tenant_code uniqueness is scoped per server.
                        $tenantCodeTaken = !empty($tenantCode)
                            ? \App\Models\CompanyPbxAccount::where(
                                'tenant_code',
                                $tenantCode
                            )
                                ->where('pbx_provider_id', $providerId)
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
