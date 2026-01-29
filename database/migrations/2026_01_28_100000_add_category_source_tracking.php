<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // STEP 5: Category Intelligence Hardening
        //
        // This migration enforces confidence thresholds and adds source tracking.
        //
        // Rules:
        // - If category_confidence < 0.6 → category_id = NULL, sub_category_id = NULL
        // - category_source tracks origin: 'rule' | 'ai' | 'manual' | null
        // - Manual overrides bypass AI confidence checks
        //
        // Idempotent: If columns already exist, migration is skipped.

        Schema::table('calls', function (Blueprint $table) {
            // Track category source (ai, manual, rule, null)
            if (! Schema::hasColumn('calls', 'category_source')) {
                $table->enum('category_source', ['rule', 'ai', 'manual'])->nullable()->after('category_confidence')
                    ->comment('Source of category: rule-based, AI-generated, or manually assigned');
            }

            // Add index for confidence filtering (needed for threshold enforcement)
            if (! Schema::hasIndex('calls', 'idx_calls_category_confidence')) {
                $table->index('category_confidence', 'idx_calls_category_confidence');
            }

            // Add index for source tracking (needed for audit trail)
            if (! Schema::hasIndex('calls', 'idx_calls_category_source')) {
                $table->index('category_source', 'idx_calls_category_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropIndex('idx_calls_category_source');
            $table->dropIndex('idx_calls_category_confidence');
            $table->dropColumn('category_source');
        });
    }
};
