<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PHASE 1: RAW INGESTION LAYER
     * 
     * Immutable raw payload storage - never modified after creation.
     * Stores full JSON exactly as returned by PBX API.
     * Enables provider-agnostic normalization and future version compatibility.
     */
    public function up(): void
    {
        Schema::create('pbx_raw_payloads', function (Blueprint $table) {
            $table->id();

            // Provider identifier (e.g., 'pbxware', '3cx', 'zoom_phone', 'twilio')
            $table->string('provider')->index();

            // API endpoint called (e.g., 'tenant.list', 'cdr.download', 'transcription.get')
            $table->string('endpoint')->index();

            // Server/Tenant ID from the provider (nullable for global endpoints like tenant.list)
            $table->string('server_id')->nullable()->index();

            // External unique identifier from the provider (e.g., PBXware uniqueid for CDR)
            $table->string('external_id')->nullable()->index();

            // Full JSON response from provider API - NEVER MODIFIED
            $table->longText('payload_json');

            // API version that returned this payload (default 'v7' for PBXware)
            $table->string('api_version')->default('v7');

            // When this payload was fetched from the provider
            $table->timestamp('fetched_at')->index();

            // Processing status: 'received', 'normalized', 'failed', 'archived'
            $table->string('processing_status')->default('received')->index();

            // Optional error message if processing failed
            $table->text('processing_error')->nullable();

            // Timestamps
            $table->timestamps();

            // Composite indexes for efficient querying
            $table->index(['provider', 'endpoint', 'fetched_at']);
            $table->index(['provider', 'server_id', 'fetched_at']);
            $table->unique(['provider', 'endpoint', 'external_id', 'api_version'], 'pbx_payloads_unique');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('pbx_raw_payloads');
    }
};
