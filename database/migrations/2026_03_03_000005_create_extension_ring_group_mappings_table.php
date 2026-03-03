<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PHASE 8: EXTENSION & QUEUE MAPPING
     * 
     * Maps extensions to ring groups (queues) and departments.
     * Workaround for PBXware CDR not exposing ring_group directly.
     * 
     * This table can be:
     * 1. Auto-populated from pbxware.extension.list (if API endpoint available)
     * 2. Manually maintained by admins in the dashboard
     * 3. Bulk imported from PBX configuration files
     */
    public function up(): void
    {
        Schema::create('extension_ring_group_mappings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // Server/Tenant ID
            $table->string('server_id')->index();

            // Extension number (e.g., "101", "201")
            $table->string('extension')->index();

            // Ring group / Queue name (e.g., "Sales Queue", "Support Escalation")
            $table->string('ring_group')->nullable()->index();

            // Department for routing (e.g., "sales", "support", "billing")
            $table->string('department')->nullable()->index();

            // Whether this mapping is active/valid
            $table->boolean('is_active')->default(true)->index();

            // Timestamps
            $table->timestamps();

            // Composite indexes
            $table->index(['company_id', 'server_id', 'extension']);
            $table->unique(['company_id', 'server_id', 'extension'], 'ext_ring_mapping_unique');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('extension_ring_group_mappings');
    }
};
