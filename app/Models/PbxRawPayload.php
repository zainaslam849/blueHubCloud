<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PbxRawPayload Model
 * 
 * Represents an immutable raw payload from a PBX provider API.
 * Never modified after creation - all updates are new records.
 * 
 * Fields:
 * - provider: Provider name (pbxware, 3cx, zoom_phone, twilio, etc.)
 * - endpoint: API endpoint (cdr.download, transcription.get, tenant.list, etc.)
 * - server_id: Server/Tenant ID from provider
 * - external_id: Unique identifier from provider (e.g., CDR uniqueid)
 * - payload_json: Full JSON response exactly as received from API
 * - api_version: API version that generated this payload (v7, v8, etc.)
 * - fetched_at: When the API was called
 * - processing_status: received, normalized, failed, archived
 * - processing_error: Error message if processing failed
 */
class PbxRawPayload extends Model
{
    protected $table = 'pbx_raw_payloads';

    protected $fillable = [
        'provider',
        'endpoint',
        'server_id',
        'external_id',
        'payload_json',
        'api_version',
        'fetched_at',
        'processing_status',
        'processing_error',
    ];

    protected $casts = [
        'fetched_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get decoded payload JSON
     */
    public function getDecodedPayload(): array
    {
        return json_decode($this->payload_json, true) ?? [];
    }

    /**
     * Mark this payload as successfully normalized
     */
    public function markAsNormalized(): void
    {
        $this->update(['processing_status' => 'normalized']);
    }

    /**
     * Mark this payload as failed
     */
    public function markAsFailed(string $error): void
    {
        $this->update([
            'processing_status' => 'failed',
            'processing_error' => $error,
        ]);
    }
}
