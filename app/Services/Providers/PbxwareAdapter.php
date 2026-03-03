<?php

namespace App\Services\Providers;

use App\Contracts\ProviderAdapterInterface;
use App\Models\PbxRawPayload;
use App\Services\AwsSecretsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PHASE 2: PBXWARE ADAPTER LAYER
 * 
 * Encapsulates all PBXware API endpoints and integrations.
 * Responsibilities:
 * - Call PBXware API endpoints
 * - Store full raw response in pbx_raw_payloads table
 * - Return decoded JSON to normalization layer
 * 
 * API Documentation: https://pbxware.com/api
 * 
 * Authentication: Query parameter apikey={API_KEY}
 * Base URL: https://ip.pbxbluehub.com
 */
class PbxwareAdapter implements ProviderAdapterInterface
{
    private const PROVIDER_NAME = 'pbxware';
    private const API_VERSION = 'v7';
    private const BASE_URL = 'https://ip.pbxbluehub.com';
    private const TIMEOUT = 30; // seconds

    private string $apiKey;
    private ?string $baseUrl;
    private string $secretName = 'pbxware/api-credentials';
    private int $secretTtlSeconds = 600; // 10 minutes
    private ?AwsSecretsService $secretsService = null;

    public function __construct(?AwsSecretsService $secretsService = null)
    {
        $this->secretsService = $secretsService ?? new AwsSecretsService();

        // Credential precedence:
        // 1) AWS Secrets Manager (production preferred)
        // 2) ENV fallback (PBXWARE_API_KEY, PBXWARE_BASE_URL)
        $credentials = $this->resolveCredentials();
        
        $this->apiKey = $credentials['api_key'] 
            ?? throw new RuntimeException('PBXWARE_API_KEY not configured');

        $this->baseUrl = $credentials['base_url'] ?? self::BASE_URL;
    }

    /**
     * Resolve credentials from AWS Secrets Manager with ENV fallback
     */
    private function resolveCredentials(): array
    {
        // Try Secrets Manager first
        $secrets = $this->getCachedCredentials();
        if (!empty($secrets)) {
            Log::info('PbxwareAdapter: credentials loaded from AWS Secrets Manager');
            return $secrets;
        }

        // Fall back to environment variables
        $env = $this->getEnvCredentials();
        if (!empty($env)) {
            Log::info('PbxwareAdapter: credentials loaded from environment variables');
            return $env;
        }

        Log::warning('PbxwareAdapter: no credentials found in Secrets Manager or ENV');
        return [];
    }

    /**
     * Get credentials from environment variables
     */
    private function getEnvCredentials(): array
    {
        $out = [];

        $apiKey = env('PBXWARE_API_KEY');
        $baseUrl = env('PBXWARE_BASE_URL');

        if ($apiKey !== null && trim((string) $apiKey) !== '') {
            $out['api_key'] = (string) $apiKey;
        }
        if ($baseUrl !== null && trim((string) $baseUrl) !== '') {
            $out['base_url'] = (string) $baseUrl;
        }

        return $out;
    }

    /**
     * Get cached credentials from Secrets Manager
     */
    private function getCachedCredentials(): array
    {
        $cacheKey = 'pbxware_adapter_credentials';
        
        try {
            $decoded = $this->secretsService->get($this->secretName);
            if (is_array($decoded) && !empty($decoded)) {
                Cache::put($cacheKey, $decoded, $this->secretTtlSeconds);
                return $decoded;
            }
        } catch (\Throwable $e) {
            Log::debug('PbxwareAdapter: failed to fetch from Secrets Manager', [
                'secret' => $this->secretName,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * ENDPOINT: pbxware.tenant.list
     * 
     * List all tenants (servers) available to this API key.
     * Used for tenant auto-sync and multi-tenant discovery.
     *
     * @return array List of tenants/servers
     * @throws RuntimeException If API call fails
     */
    public function listTenants(): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->get($this->baseUrl, [
                    'action' => 'pbxware.tenant.list',
                    'apikey' => $this->apiKey,
                ])
                ->throw()
                ->json();

            // Store raw payload for audit/debugging
            $this->storeRawPayload(
                endpoint: 'tenant.list',
                serverId: null,
                externalId: null,
                payload: $response
            );

            return $response;
        } catch (\Exception $e) {
            Log::error('PbxwareAdapter: listTenants failed', [
                'error' => $e->getMessage(),
            ]);

            $this->storeFailedPayload(
                endpoint: 'tenant.list',
                serverId: null,
                error: $e->getMessage()
            );

            throw new RuntimeException("Failed to fetch PBXware tenants: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * ENDPOINT: pbxware.cdr.download
     * 
     * Fetch CDR records for a date range from a specific server.
     * Returns 12 fields: From, To, Date/Time, Duration, Status, Unique ID, etc.
     * 
     * Query Parameters:
     * - server={server_id}: Required
     * - start={M-d-Y}: Date range start
     * - end={M-d-Y}: Date range end
     * - status=8: Filter for completed calls
     *
     * @param string $serverId PBXware server ID
     * @param string $startDate YYYY-MM-DD format
     * @param string $endDate YYYY-MM-DD format
     * @return array List of CDR records
     * @throws RuntimeException If API call fails
     */
    public function fetchCdr(string $serverId, string $startDate, string $endDate): array
    {
        try {
            // Validate and convert date format
            $start = Carbon::parse($startDate)->format('n-d-Y');
            $end = Carbon::parse($endDate)->format('n-d-Y');

            $response = Http::timeout(self::TIMEOUT)
                ->get($this->baseUrl, [
                    'action' => 'pbxware.cdr.download',
                    'server' => $serverId,
                    'start' => $start,
                    'end' => $end,
                    'status' => 8, // Completed calls only
                    'apikey' => $this->apiKey,
                ])
                ->throw()
                ->json();

            // Store raw payload for audit/debugging
            $this->storeRawPayload(
                endpoint: 'cdr.download',
                serverId: $serverId,
                externalId: null,
                payload: $response
            );

            return $response;
        } catch (\Exception $e) {
            Log::error('PbxwareAdapter: fetchCdr failed', [
                'server_id' => $serverId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'error' => $e->getMessage(),
            ]);

            $this->storeFailedPayload(
                endpoint: 'cdr.download',
                serverId: $serverId,
                error: $e->getMessage()
            );

            throw new RuntimeException(
                "Failed to fetch CDR for server {$serverId}: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * ENDPOINT: pbxware.transcription.get
     * 
     * Fetch transcription for a specific call by unique ID.
     * Returns transcription text if available.
     * 
     * Query Parameters:
     * - uniqueid={unique_id}: The call's unique identifier from CDR
     * - server={server_id}: Server ID
     *
     * @param string $serverId PBXware server ID
     * @param string $externalCallId Unique ID from CDR
     * @return array Transcription data or empty array if not found
     * @throws RuntimeException If API call fails
     */
    public function fetchTranscription(string $serverId, string $externalCallId): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->get($this->baseUrl, [
                    'action' => 'pbxware.transcription.get',
                    'uniqueid' => $externalCallId,
                    'server' => $serverId,
                    'apikey' => $this->apiKey,
                ])
                ->throw()
                ->json();

            // Store raw payload for audit/debugging
            $this->storeRawPayload(
                endpoint: 'transcription.get',
                serverId: $serverId,
                externalId: $externalCallId,
                payload: $response
            );

            return $response;
        } catch (\Exception $e) {
            Log::warning('PbxwareAdapter: fetchTranscription failed', [
                'server_id' => $serverId,
                'external_call_id' => $externalCallId,
                'error' => $e->getMessage(),
            ]);

            // Don't throw here - transcriptions are optional
            // Store failed attempt for debugging
            $this->storeFailedPayload(
                endpoint: 'transcription.get',
                serverId: $serverId,
                error: $e->getMessage()
            );

            return [];
        }
    }

    /**
     * Get this adapter's provider name.
     */
    public function getProviderName(): string
    {
        return self::PROVIDER_NAME;
    }

    /**
     * Get this adapter's API version.
     */
    public function getApiVersion(): string
    {
        return self::API_VERSION;
    }

    /**
     * Store a raw API payload in the database.
     * 
     * Raw payloads are immutable - never modified after creation.
     * Enables provider-agnostic normalization and version compatibility.
     *
     * @param string $endpoint Endpoint name (e.g., 'cdr.download')
     * @param string|null $serverId Server ID from provider
     * @param string|null $externalId External unique identifier
     * @param array $payload Full API response
     * @return PbxRawPayload
     */
    private function storeRawPayload(
        string $endpoint,
        ?string $serverId,
        ?string $externalId,
        array $payload
    ): PbxRawPayload {
        return PbxRawPayload::create([
            'provider' => self::PROVIDER_NAME,
            'endpoint' => $endpoint,
            'server_id' => $serverId,
            'external_id' => $externalId,
            'payload_json' => json_encode($payload),
            'api_version' => self::API_VERSION,
            'fetched_at' => now(),
            'processing_status' => 'received',
        ]);
    }

    /**
     * Store a failed API attempt for debugging.
     *
     * @param string $endpoint Endpoint name
     * @param string|null $serverId Server ID
     * @param string $error Error message
     * @return PbxRawPayload
     */
    private function storeFailedPayload(
        string $endpoint,
        ?string $serverId,
        string $error
    ): PbxRawPayload {
        return PbxRawPayload::create([
            'provider' => self::PROVIDER_NAME,
            'endpoint' => $endpoint,
            'server_id' => $serverId,
            'external_id' => null,
            'payload_json' => json_encode(['error' => $error]),
            'api_version' => self::API_VERSION,
            'fetched_at' => now(),
            'processing_status' => 'failed',
            'processing_error' => $error,
        ]);
    }
}
