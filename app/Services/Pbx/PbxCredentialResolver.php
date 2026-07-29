<?php

namespace App\Services\Pbx;

use App\Exceptions\PbxwareClientException;
use App\Models\PbxProvider;
use App\Services\AwsSecretsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for per-server PBXware credentials.
 *
 * Precedence per server:
 *   1. AWS Secrets Manager secret named by pbx_providers.secret_name
 *   2. ENV fallback (PBXWARE_API_KEY / PBXWARE_BASE_URL / PBXWARE_SERVER_ID)
 *      — ONLY for the is_default row, preserving the legacy single-server
 *      recovery path.
 *
 * A non-empty pbx_providers.base_url overrides the base_url from the secret.
 *
 * Normalized shape: { api_key, base_url, server_id (nullable), timeout }.
 * server_id (a default tenant id) is optional for per-server secrets — call
 * sites always pass the tenant explicitly; only the legacy global secret
 * carries one.
 */
class PbxCredentialResolver
{
    protected const CACHE_TTL_SECONDS = 600;

    public static function resolve(PbxProvider $server, ?AwsSecretsService $secretsService = null): array
    {
        $secretCreds = static::getCachedSecret($server, $secretsService ?? new AwsSecretsService());

        try {
            return static::applyServerOverrides($server, static::normalizeAndValidate($secretCreds, 'secrets-manager', $server));
        } catch (PbxwareClientException $e) {
            Log::warning('PbxCredentialResolver: secret unavailable or incomplete', [
                'pbx_provider_id' => $server->id,
                'secret' => $server->secret_name,
                'is_default' => (bool) $server->is_default,
                'error' => $e->getMessage(),
            ]);
        }

        if ($server->is_default) {
            try {
                $normalized = static::applyServerOverrides($server, static::normalizeAndValidate(static::envCredentials(), 'env', $server));
                Log::info('PbxCredentialResolver: resolved from ENV fallback for default server', [
                    'pbx_provider_id' => $server->id,
                ]);

                return $normalized;
            } catch (PbxwareClientException $e) {
                // fall through to the combined error below
            }
        }

        $msg = sprintf(
            'PBXware credentials missing for server "%s" (id=%d): unable to load from AWS Secrets Manager secret "%s"%s.',
            $server->name,
            $server->id,
            (string) $server->secret_name,
            $server->is_default ? ' and ENV fallback (PBXWARE_API_KEY, PBXWARE_BASE_URL)' : ''
        );
        Log::error('PbxCredentialResolver: ' . $msg);

        throw new PbxwareClientException($msg);
    }

    /**
     * Bust the cached secret for a server (call after rotating its API key).
     */
    public static function forget(PbxProvider $server): void
    {
        Cache::forget(static::cacheKey($server));
    }

    protected static function cacheKey(PbxProvider $server): string
    {
        return 'pbxware_creds:' . $server->id . ':v1';
    }

    protected static function getCachedSecret(PbxProvider $server, AwsSecretsService $secretsService): array
    {
        $secretName = trim((string) $server->secret_name);
        if ($secretName === '') {
            return [];
        }

        $cacheKey = static::cacheKey($server);
        $disableCache = filter_var(config('services.pbxware.disable_secrets_cache', false), FILTER_VALIDATE_BOOLEAN);

        if ($disableCache) {
            Cache::forget($cacheKey);
        } else {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && $cached !== []) {
                return $cached;
            }
        }

        try {
            $decoded = $secretsService->get($secretName);
            // Never cache failures or empty payloads.
            if (is_array($decoded) && $decoded !== [] && ! $disableCache) {
                Cache::put($cacheKey, $decoded, static::CACHE_TTL_SECONDS);
            }

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            Log::error('PbxCredentialResolver: failed to fetch secret', [
                'pbx_provider_id' => $server->id,
                'secret' => $secretName,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    protected static function envCredentials(): array
    {
        $out = [];

        foreach (['api_key', 'base_url', 'server_id'] as $key) {
            $value = config('services.pbxware.' . $key);
            if ($value !== null && trim((string) $value) !== '') {
                $out[$key] = (string) $value;
            }
        }

        $timeout = config('services.pbxware.timeout');
        if ($timeout !== null && trim((string) $timeout) !== '') {
            $out['timeout'] = (int) $timeout;
        }

        return $out;
    }

    protected static function normalizeAndValidate(array $creds, string $source, PbxProvider $server): array
    {
        // Normalize common variant key names from Secrets Manager.
        if (! isset($creds['api_key']) && isset($creds['apikey'])) {
            $creds['api_key'] = $creds['apikey'];
        }
        if (! isset($creds['base_url']) && isset($creds['baseUrl'])) {
            $creds['base_url'] = $creds['baseUrl'];
        }
        if (! isset($creds['server_id']) && isset($creds['server'])) {
            $creds['server_id'] = $creds['server'];
        }
        if (! isset($creds['server_id']) && isset($creds['serverId'])) {
            $creds['server_id'] = $creds['serverId'];
        }

        // PBXware uses query-based auth ONLY.
        $authType = strtolower((string) ($creds['auth_type'] ?? $creds['auth'] ?? 'query'));
        if ($authType !== 'query') {
            $msg = 'Unsupported PBXware auth_type: expected "query" for query-based API.';
            Log::error('PbxCredentialResolver: ' . $msg, ['auth_type' => $authType, 'source' => $source, 'pbx_provider_id' => $server->id]);
            throw new PbxwareClientException($msg);
        }

        $normalized = [
            'api_key' => $creds['api_key'] ?? null,
            'base_url' => $creds['base_url'] ?? null,
            'server_id' => $creds['server_id'] ?? null,
            'timeout' => isset($creds['timeout']) ? (int) $creds['timeout'] : (int) (config('pbx.providers.pbxware.timeout') ?? 30),
        ];

        // The row-level base_url can satisfy a secret that only holds the key.
        if (empty($normalized['base_url']) && trim((string) $server->base_url) !== '') {
            $normalized['base_url'] = (string) $server->base_url;
        }

        if (empty($normalized['api_key'])) {
            $msg = 'Credentials validation failed: api_key is missing.';
            Log::error('PbxCredentialResolver: ' . $msg, ['available_keys' => array_keys($creds), 'source' => $source, 'pbx_provider_id' => $server->id]);
            throw new PbxwareClientException($msg);
        }

        if (empty($normalized['base_url'])) {
            $msg = 'Credentials validation failed: base_url is missing.';
            Log::error('PbxCredentialResolver: ' . $msg, ['available_keys' => array_keys($creds), 'source' => $source, 'pbx_provider_id' => $server->id]);
            throw new PbxwareClientException($msg);
        }

        $normalized['api_key'] = (string) $normalized['api_key'];
        $normalized['base_url'] = rtrim((string) $normalized['base_url'], '/');
        $normalized['server_id'] = $normalized['server_id'] !== null ? (string) $normalized['server_id'] : null;

        return $normalized;
    }

    protected static function applyServerOverrides(PbxProvider $server, array $normalized): array
    {
        if (trim((string) $server->base_url) !== '') {
            $normalized['base_url'] = rtrim((string) $server->base_url, '/');
        }

        return $normalized;
    }
}
