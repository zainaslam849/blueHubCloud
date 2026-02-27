<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyPbxAccount;
use App\Models\PbxProvider;
use App\Models\PbxwareTenant;
use App\Services\PbxwareClient;
use App\Exceptions\PbxwareClientException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class AdminCompaniesController extends Controller
{
    protected PbxwareClient $pbxwareClient;

    public function __construct()
    {
        $this->pbxwareClient = new PbxwareClient();
    }

    /**
     * List all companies with their PBX account info
     */
    public function index(Request $request): JsonResponse
    {
        $companies = Company::query()
            ->with('companyPbxAccounts.pbxProvider:id,name')
            ->select('id', 'name', 'status', 'timezone', 'created_at')
            ->orderBy('name')
            ->get()
            ->map(function ($company) {
                $account = $company->companyPbxAccounts->first();

                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'status' => $company->status,
                    'timezone' => $company->timezone,
                    'server_id' => $account?->server_id,
                    'tenant_code' => $account?->tenant_code,
                    'package_name' => $account?->package_name,
                    'pbx_provider_id' => $account?->pbx_provider_id,
                    'pbx_provider_name' => $account?->pbxProvider?->name,
                    'created_at' => $company->created_at?->toISOString(),
                ];
            });

        return response()->json(['data' => $companies]);
    }

    /**
     * Create new company with optional PBX account linking
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:companies,name'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', 'in:active,inactive'],
            'server_id' => ['sometimes', 'string', 'max:100'],
            'pbx_provider_id' => ['sometimes', 'integer', 'exists:pbx_providers,id'],
            'tenant_code' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $company = Company::create([
            'name' => $validated['name'],
            'timezone' => $validated['timezone'] ?? 'UTC',
            'status' => $validated['status'] ?? 'active',
        ]);

        // If server_id provided, link the PBX account
        if (! empty($validated['server_id'])) {
            if (empty($validated['pbx_provider_id'])) {
                throw ValidationException::withMessages([
                    'pbx_provider_id' => 'PBX Provider is required when linking a server.',
                ]);
            }

            // Check if this server is already assigned to another company
            $existingAccount = CompanyPbxAccount::where('server_id', $validated['server_id'])
                ->where('pbx_provider_id', $validated['pbx_provider_id'])
                ->where('company_id', '!=', $company->id)
                ->first();

            if ($existingAccount) {
                // Delete the company we just created
                $company->delete();
                throw ValidationException::withMessages([
                    'server_id' => "Server {$validated['server_id']} is already assigned to another company.",
                ]);
            }

            // Fetch tenant details from cache
            $tenant = PbxwareTenant::where('server_id', $validated['server_id'])
                ->where('pbx_provider_id', $validated['pbx_provider_id'])
                ->first();

            CompanyPbxAccount::create([
                'company_id' => $company->id,
                'pbx_provider_id' => $validated['pbx_provider_id'],
                'server_id' => $validated['server_id'],
                'tenant_code' => $validated['tenant_code'] ?? $tenant?->tenant_code,
                'package_name' => $tenant?->package_name,
                'status' => 'active',
                'pbx_synced_at' => now(),
            ]);
        }

        return response()->json([
            'data' => $company,
            'message' => 'Company created successfully',
        ], 201);
    }

    /**
     * Fetch available tenants from PBXware and sync to local cache
     */
    public function syncTenants(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pbx_provider_id' => ['required', 'integer', 'exists:pbx_providers,id'],
        ]);

        try {
            // Fetch tenants from PBXware
            $tenants = $this->pbxwareClient->fetchTenantList();

            if (empty($tenants)) {
                return response()->json([
                    'message' => 'No tenants found on PBXware server',
                    'new_tenants' => [],
                    'existing_tenants' => [],
                ]);
            }

            $pbxProviderId = $validated['pbx_provider_id'];
            $newTenants = [];
            $existingTenants = [];
            $createdCompanies = 0;
            $linkedCompanies = 0;
            $skippedCompanies = 0;

            // Process each tenant returned from PBXware
            foreach ($tenants as $serverId => $tenantData) {
                $tenantCode = $tenantData['tenantcode'] ?? null;

                // Check if this combination already exists
                $existing = PbxwareTenant::where('server_id', (string) $serverId)
                    ->where('pbx_provider_id', $pbxProviderId)
                    ->first();

                if ($existing) {
                    // Update existing record
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
                    $existingTenants[] = [
                        'server_id' => (string) $serverId,
                        'tenant_code' => $tenantCode,
                        'name' => $tenantData['name'] ?? null,
                        'package' => $tenantData['package'] ?? null,
                    ];
                } else {
                    // Create new record
                    PbxwareTenant::create([
                        'pbx_provider_id' => $pbxProviderId,
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
                    $newTenants[] = [
                        'server_id' => (string) $serverId,
                        'tenant_code' => $tenantCode,
                        'name' => $tenantData['name'] ?? null,
                        'package' => $tenantData['package'] ?? null,
                    ];
                }

                CompanyPbxAccount::where('server_id', (string) $serverId)
                    ->where('pbx_provider_id', $pbxProviderId)
                    ->where(function ($query) use ($tenantCode, $tenantData) {
                        $query->whereNull('tenant_code')
                            ->orWhereNull('package_name')
                            ->orWhere('tenant_code', '!=', $tenantCode)
                            ->orWhere('package_name', '!=', $tenantData['package'] ?? null);
                    })
                    ->update([
                        'tenant_code' => $tenantCode,
                        'package_name' => $tenantData['package'] ?? null,
                        'pbx_synced_at' => now(),
                    ]);

                $tenantName = $tenantData['name'] ?? null;
                if (is_string($tenantName) && trim($tenantName) !== '') {
                    $tenantName = trim($tenantName);
                    $company = Company::where('name', $tenantName)->first();

                    if (! $company) {
                        $company = Company::create([
                            'name' => $tenantName,
                            'timezone' => 'UTC',
                            'status' => 'inactive',
                        ]);
                        $createdCompanies++;
                    }

                    $providerAccountQuery = CompanyPbxAccount::where('company_id', $company->id)
                        ->where('pbx_provider_id', $pbxProviderId);

                    $existingAccount = $providerAccountQuery
                        ->where('server_id', (string) $serverId)
                        ->first();

                    if (! $existingAccount && ! $providerAccountQuery->exists()) {
                        $serverTaken = CompanyPbxAccount::where('server_id', (string) $serverId)
                            ->where('pbx_provider_id', $pbxProviderId)
                            ->where('company_id', '!=', $company->id)
                            ->exists();

                        $tenantCodeTaken = ! empty($tenantCode)
                            ? CompanyPbxAccount::where('tenant_code', $tenantCode)
                                ->where('server_id', '!=', (string) $serverId)
                                ->exists()
                            : false;

                        if (! $serverTaken && ! $tenantCodeTaken) {
                            CompanyPbxAccount::create([
                                'company_id' => $company->id,
                                'pbx_provider_id' => $pbxProviderId,
                                'server_id' => (string) $serverId,
                                'tenant_code' => $tenantCode,
                                'package_name' => $tenantData['package'] ?? null,
                                'status' => $company->status === 'active' ? 'active' : 'inactive',
                                'pbx_synced_at' => now(),
                            ]);
                            $linkedCompanies++;
                        } else {
                            $skippedCompanies++;
                        }
                    } elseif (! $existingAccount) {
                        $skippedCompanies++;
                    }
                }
            }

            return response()->json([
                'message' => 'Tenants synced successfully',
                'new_count' => count($newTenants),
                'existing_count' => count($existingTenants),
                'created_companies' => $createdCompanies,
                'linked_companies' => $linkedCompanies,
                'skipped_companies' => $skippedCompanies,
                'new_tenants' => $newTenants,
            ]);
        } catch (PbxwareClientException $e) {
            Log::error('Failed to sync tenants', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to sync tenants: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get available (unmapped) tenants for dropdown
     */
    public function availableTenants(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'pbx_provider_id' => ['required', 'integer', 'exists:pbx_providers,id'],
            ]);

            $tenants = PbxwareTenant::where('pbx_provider_id', $validated['pbx_provider_id'])
                ->leftJoin('company_pbx_accounts', function ($join) use ($validated) {
                    $join->on('pbxware_tenants.server_id', '=', 'company_pbx_accounts.server_id')
                        ->where('company_pbx_accounts.pbx_provider_id', '=', $validated['pbx_provider_id']);
                })
                ->whereNull('company_pbx_accounts.id') // Only unmapped tenants
                ->select(
                    'pbxware_tenants.server_id',
                    'pbxware_tenants.tenant_code',
                    'pbxware_tenants.name',
                    'pbxware_tenants.package_name',
                    'pbxware_tenants.synced_at'
                )
                ->orderBy('pbxware_tenants.name')
                ->get();

            return response()->json(['data' => $tenants]);
        } catch (\Exception $e) {
            Log::error('Failed to load available tenants', [
                'error' => $e->getMessage(),
                'pbx_provider_id' => $request->input('pbx_provider_id'),
            ]);
            return response()->json(['data' => []], 200);
        }
    }

    /**
     * Update company, optionally linking/unlinking PBX account
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $company = Company::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', 'unique:companies,name,' . $id],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', 'in:active,inactive'],
            'server_id' => ['sometimes', 'string', 'max:100'],
            'pbx_provider_id' => ['sometimes', 'integer', 'exists:pbx_providers,id'],
            'tenant_code' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $company->update([
            'name' => $validated['name'] ?? $company->name,
            'timezone' => $validated['timezone'] ?? $company->timezone,
            'status' => $validated['status'] ?? $company->status,
        ]);

        if (array_key_exists('server_id', $validated)) {
            if (empty($validated['server_id'])) {
                // Unlink PBX account
                $company->companyPbxAccounts()->delete();
            } else {
                if (empty($validated['pbx_provider_id'])) {
                    throw ValidationException::withMessages([
                        'pbx_provider_id' => 'PBX Provider is required',
                    ]);
                }

                // Link new PBX account
                $tenant = PbxwareTenant::where('server_id', $validated['server_id'])
                    ->where('pbx_provider_id', $validated['pbx_provider_id'])
                    ->first();

                CompanyPbxAccount::updateOrCreate(
                    ['company_id' => $company->id, 'pbx_provider_id' => $validated['pbx_provider_id']],
                    [
                        'server_id' => $validated['server_id'],
                        'tenant_code' => $validated['tenant_code'] ?? $tenant?->tenant_code,
                        'package_name' => $tenant?->package_name,
                        'status' => 'active',
                        'pbx_synced_at' => now(),
                    ]
                );
            }
        }

        return response()->json([
            'data' => $company,
            'message' => 'Company updated successfully',
        ]);
    }

    /**
     * Delete company
     */
    public function destroy(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);
        $company->delete();

        return response()->json(['message' => 'Company deleted successfully']);
    }
}
