<?php

namespace App\Contracts;

/**
 * Provider Adapter Interface
 * 
 * All PBX provider adapters must implement this interface.
 * Enables support for multiple PBX systems (PBXware, 3CX, Zoom Phone, Twilio, etc.)
 * Each adapter is responsible for:
 * - Calling provider APIs
 * - Storing raw payloads in pbx_raw_payloads
 * - Returning decoded JSON to normalization layer
 */
interface ProviderAdapterInterface
{
    /**
     * List all tenants/servers available to this provider instance.
     *
     * @return array Array of tenant/server data from provider
     * @throws \Exception If API call fails
     */
    public function listTenants(): array;

    /**
     * Fetch CDR (Call Detail Records) for a specific server/date range.
     *
     * @param string $serverId Server/Tenant ID from provider
     * @param string $startDate Date in format YYYY-MM-DD
     * @param string $endDate Date in format YYYY-MM-DD
     * @return array Array of CDR records from provider
     * @throws \Exception If API call fails
     */
    public function fetchCdr(string $serverId, string $startDate, string $endDate): array;

    /**
     * Fetch transcription for a specific call.
     *
     * @param string $serverId Server/Tenant ID from provider
     * @param string $externalCallId Unique call identifier from provider (e.g., uniqueid)
     * @return array Transcription data from provider, or empty array if not available
     * @throws \Exception If API call fails
     */
    public function fetchTranscription(string $serverId, string $externalCallId): array;

    /**
     * Get the name of this adapter's provider.
     *
     * @return string Provider name (e.g., 'pbxware', '3cx', 'zoom_phone')
     */
    public function getProviderName(): string;

    /**
     * Get the API version this adapter targets.
     *
     * @return string API version (e.g., 'v7', 'v8')
     */
    public function getApiVersion(): string;
}
