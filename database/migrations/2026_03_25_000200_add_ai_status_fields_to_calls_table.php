<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->string('ai_summary_status')->nullable()->after('ai_summary');
            $table->string('ai_category_status')->nullable()->after('category_confidence');

            $table->index(['company_id', 'ai_summary_status', 'started_at'], 'calls_company_ai_summary_status_started_idx');
            $table->index(['company_id', 'ai_category_status', 'started_at'], 'calls_company_ai_category_status_started_idx');
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropIndex('calls_company_ai_summary_status_started_idx');
            $table->dropIndex('calls_company_ai_category_status_started_idx');

            $table->dropColumn('ai_summary_status');
            $table->dropColumn('ai_category_status');
        });
    }
};
