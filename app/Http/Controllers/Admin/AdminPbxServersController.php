<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePbxServerRequest;
use App\Http\Requests\Admin\UpdatePbxServerRequest;
use App\Models\PbxProvider;
use App\Services\AwsSecretsService;
use App\Services\Pbx\PbxClientResolver;
use App\Services\Pbx\PbxCredentialResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Admin CRUD for PBX servers (pbx_providers rows).
 *
 * API keys are never stored in the database and never returned by any
 * endpoint: a pasted key is written to AWS Secrets Manager under
 * pbxware/servers/{slug} and only the secret name is persisted.
 */
class AdminPbxServersController extends Controller
{
    public function index(): JsonResponse
    {
        $servers = PbxProvider::query()
            ->withCount(['companyPbxAccounts', 'pbxwareTenants'])
            ->with('tenantSyncSetting:id,pbx_provider_id,last_synced_at,last_sync_count')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (PbxProvider $server) => $this->presentServer($server));

        return response()->json(['data' => $servers]);
    }

    public function store(StorePbxServerRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $slug = $this->uniqueSlug($validated['name']);
        $secretName = trim((string) ($validated['secret_name'] ?? ''));

        if (! empty($validated['api_key'])) {
            $secretName = 'pbxware/servers/' . $slug;

            try {
                app(AwsSecretsService::class)->put($secretName, [
                    'api_key' => $validated['api_key'],
                    'base_url' => $validated['base_url'],
                    'auth_type' => 'query',
                ]);
            } catch (\Throwable $e) {
                Log::error('AdminPbxServersController: failed to write server secret', [
                    'secret' => $secretName,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'message' => 'Could not store the API key in AWS Secrets Manager. Verify the app has secretsmanager:CreateSecret / PutSecretValue permissions, or create the secret manually and enter its name. (' . $e->getMessage() . ')',
                ], 422);
            }
        }

        $server = PbxProvider::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'provider_type' => 'pbxware',
            'secret_name' => $secretName,
            'base_url' => $validated['base_url'] ?? null,
            'is_default' => false,
            'status' => $validated['status'] ?? 'active',
        ]);

        return response()->json([
            'data' => $this->presentServer($server),
            'message' => 'PBX server created successfully.',
        ], 201);
    }

    public function update(UpdatePbxServerRequest $request, int $id): JsonResponse
    {
        $server = PbxProvider::findOrFail($id);
        $validated = $request->validated();

        $attributes = [];
        foreach (['name', 'base_url', 'status'] as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = $validated[$field];
            }
        }

        // Secret reference change (advanced): only when explicitly non-empty.
        if (! empty($validated['secret_name'])) {
            $attributes['secret_name'] = trim($validated['secret_name']);
        }

        // Key rotation: write-only, only when a non-empty key is submitted.
        if (! empty($validated['api_key'])) {
            $secretName = $attributes['secret_name']
                ?? ($server->secret_name ?: 'pbxware/servers/' . $server->slug);

            // Never overwrite the legacy shared secret from this screen; give
            // the default server its own secret on first rotation instead.
            if ($server->is_default && $secretName === 'pbxware/api-credentials') {
                $secretName = 'pbxware/servers/' . $server->slug;
            }

            $baseUrl = $attributes['base_url'] ?? $server->base_url;
            if (empty($baseUrl)) {
                return response()->json([
                    'message' => 'A server hostname (base URL) is required when rotating the API key.',
                ], 422);
            }

            try {
                app(AwsSecretsService::class)->put($secretName, [
                    'api_key' => $validated['api_key'],
                    'base_url' => $baseUrl,
                    'auth_type' => 'query',
                ]);
            } catch (\Throwable $e) {
                Log::error('AdminPbxServersController: failed to rotate server secret', [
                    'secret' => $secretName,
                    'pbx_provider_id' => $server->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'message' => 'Could not update the API key in AWS Secrets Manager. Verify IAM permissions, or update the secret manually. (' . $e->getMessage() . ')',
                ], 422);
            }

            $attributes['secret_name'] = $secretName;
        }

        $server->update($attributes);
        PbxCredentialResolver::forget($server);

        return response()->json([
            'data' => $this->presentServer($server->fresh()),
            'message' => 'PBX server updated successfully.',
        ]);
    }

    /**
     * Soft-disable by default; hard delete only for unreferenced, non-default
     * servers. Inactive servers are skipped by tenant sync and call ingest.
     */
    public function destroy(int $id): JsonResponse
    {
        $server = PbxProvider::withCount(['companyPbxAccounts', 'pbxwareTenants'])->findOrFail($id);

        if ($server->is_default) {
            return response()->json([
                'message' => 'The default PBX server cannot be deleted. Disable it instead.',
            ], 422);
        }

        if ($server->company_pbx_accounts_count === 0 && $server->pbxware_tenants_count === 0) {
            $server->delete();

            return response()->json(['message' => 'PBX server deleted.']);
        }

        $server->update(['status' => 'inactive']);

        return response()->json([
            'message' => 'PBX server disabled. It still has linked accounts or tenants, so it was deactivated instead of deleted; tenant sync and call ingestion will skip it.',
        ]);
    }

    /**
     * Verify the stored credentials by fetching the tenant list.
     *
     * Always responds 200: proxies (Cloudflare) replace origin 5xx responses
     * with their own error page, which would hide the real failure message.
     * The outcome is carried in data.ok.
     */
    public function testConnection(int $id): JsonResponse
    {
        $server = PbxProvider::findOrFail($id);

        try {
            PbxCredentialResolver::forget($server);
            $tenants = PbxClientResolver::resolve($server)->fetchTenantList();

            return response()->json([
                'data' => [
                    'ok' => true,
                    'tenant_count' => count($tenants),
                ],
                'message' => 'Connection successful: ' . count($tenants) . ' tenant(s) visible to this API key.',
            ]);
        } catch (\Throwable $e) {
            Log::warning('AdminPbxServersController: test connection failed', [
                'pbx_provider_id' => $server->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'data' => ['ok' => false],
                'message' => 'Connection failed: ' . $e->getMessage(),
            ]);
        }
    }

    private function presentServer(PbxProvider $server): array
    {
        return [
            'id' => $server->id,
            'name' => $server->name,
            'slug' => $server->slug,
            'provider_type' => $server->provider_type,
            'base_url' => $server->base_url,
            'secret_name' => $server->secret_name,
            'has_credentials' => trim((string) $server->secret_name) !== '',
            'is_default' => (bool) $server->is_default,
            'status' => $server->status,
            'accounts_count' => $server->company_pbx_accounts_count ?? $server->companyPbxAccounts()->count(),
            'tenants_count' => $server->pbxware_tenants_count ?? $server->pbxwareTenants()->count(),
            'last_synced_at' => $server->tenantSyncSetting?->last_synced_at?->toISOString(),
            'created_at' => $server->created_at?->toISOString(),
            'updated_at' => $server->updated_at?->toISOString(),
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'server';
        $slug = $base;
        $suffix = 2;

        while (PbxProvider::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
