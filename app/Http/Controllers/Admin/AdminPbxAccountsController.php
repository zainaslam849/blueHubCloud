<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyPbxAccount;
use App\Models\PbxProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPbxAccountsController extends Controller
{
    /**
     * List all PBX accounts
     */
    public function index(Request $request): JsonResponse
    {
        $accounts = CompanyPbxAccount::with(['company:id,name', 'pbxProvider:id,name'])
            ->orderBy('company_id')
            ->orderBy('server_id')
            ->get()
            ->map(function ($account) {
                return [
                    'id' => $account->id,
                    'server_id' => $account->server_id,
                    'status' => $account->status,
                    'company_id' => $account->company_id,
                    'company_name' => $account->company->name ?? null,
                    'pbx_provider_id' => $account->pbx_provider_id,
                    'pbx_provider_name' => $account->pbxProvider->name ?? null,
                    'created_at' => $account->created_at?->toISOString(),
                    'updated_at' => $account->updated_at?->toISOString(),
                ];
            });

        return response()->json(['data' => $accounts]);
    }

    /**
     * Get single PBX account
     */
    public function show(int $id): JsonResponse
    {
        $account = CompanyPbxAccount::with(['company:id,name', 'pbxProvider:id,name'])
            ->select(['id', 'company_id', 'pbx_provider_id', 'server_id', 'status'])
            ->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $account->id,
                'server_id' => $account->server_id,
                'status' => $account->status,
                'company_id' => $account->company_id,
                'company_name' => $account->company->name ?? null,
                'pbx_provider_id' => $account->pbx_provider_id,
                'pbx_provider_name' => $account->pbxProvider->name ?? null,
                'created_at' => $account->created_at?->toISOString(),
                'updated_at' => $account->updated_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Create new PBX account
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'pbx_provider_id' => ['required', 'integer', 'exists:pbx_providers,id'],
            'server_id' => ['required', 'string', 'max:100'],
        ]);

        $account = CompanyPbxAccount::create([
            'company_id' => $validated['company_id'],
            'pbx_provider_id' => $validated['pbx_provider_id'],
            'server_id' => $validated['server_id'],
            'status' => 'active',
        ]);

        return response()->json([
            'data' => $account,
            'message' => 'PBX account created successfully.',
        ], 201);
    }

    /**
     * Update PBX account
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $account = CompanyPbxAccount::findOrFail($id);

        $validated = $request->validate([
            'company_id' => ['sometimes', 'integer', 'exists:companies,id'],
            'pbx_provider_id' => ['sometimes', 'integer', 'exists:pbx_providers,id'],
            'server_id' => ['sometimes', 'string', 'max:100'],
        ]);

        $account->update($validated);

        return response()->json([
            'data' => $account->fresh(),
            'message' => 'PBX account updated successfully.',
        ]);
    }

    /**
     * Delete PBX account
     */
    public function destroy(int $id): JsonResponse
    {
        $account = CompanyPbxAccount::findOrFail($id);
        $account->delete();

        return response()->json([
            'message' => 'PBX account deleted successfully.',
        ]);
    }

    /**
     * Get available PBX providers for dropdown
     */
    public function providers(): JsonResponse
    {
        $providers = PbxProvider::select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $providers]);
    }
}
