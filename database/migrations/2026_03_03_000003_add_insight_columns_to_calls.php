<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PHASE 5-6: Add insight and deflection scoring columns to calls
     * 
     * Fields for storing extracted insights from transcripts:
     * - call_intent: WHY the customer called
     * - inferred_department: WHERE it should be routed
     * - repetitive_flag: Is this a repeat issue?
     * - deflection_confidence: 0-100 score for automation
     * - suggested_automation: JSON array of automation types
     */
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            if (!Schema::hasColumn('calls', 'call_intent')) {
                $table->string('call_intent')->nullable()->after('transcript_text')->index();
            }
            if (!Schema::hasColumn('calls', 'inferred_department')) {
                $table->string('inferred_department')->nullable()->after('call_intent')->index();
            }
            if (!Schema::hasColumn('calls', 'repetitive_flag')) {
                $table->boolean('repetitive_flag')->default(false)->after('inferred_department')->index();
            }
            if (!Schema::hasColumn('calls', 'deflection_confidence')) {
                $table->integer('deflection_confidence')->nullable()->after('repetitive_flag')->index();
            }
            if (!Schema::hasColumn('calls', 'suggested_automation')) {
                $table->json('suggested_automation')->nullable()->after('deflection_confidence');
            }
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            if (Schema::hasColumn('calls', 'call_intent')) {
                $table->dropColumn('call_intent');
            }
            if (Schema::hasColumn('calls', 'inferred_department')) {
                $table->dropColumn('inferred_department');
            }
            if (Schema::hasColumn('calls', 'repetitive_flag')) {
                $table->dropColumn('repetitive_flag');
            }
            if (Schema::hasColumn('calls', 'deflection_confidence')) {
                $table->dropColumn('deflection_confidence');
            }
            if (Schema::hasColumn('calls', 'suggested_automation')) {
                $table->dropColumn('suggested_automation');
            }
        });
    }
};
