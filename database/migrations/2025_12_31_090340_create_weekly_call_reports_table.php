<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('weekly_call_reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // Week boundaries (inclusive). Store as DATE for stable weekly grouping.
            $table->date('reporting_period_start');
            $table->date('reporting_period_end');

            $table->unsignedBigInteger('total_calls')->default(0);
            $table->unsignedBigInteger('total_duration_seconds')->default(0);
            $table->unsignedBigInteger('unresolved_calls_count')->default(0);

            // Aggregates for dashboards; store precomputed rankings for fast reads.
            // Example top_extensions: [{"extension":"101","calls":42,"duration_seconds":1234}, ...]
            $table->json('top_extensions')->nullable();

            // Example top_call_topics: [{"topic":"billing","mentions":12}, ...]
            $table->json('top_call_topics')->nullable();

            // Future insights / versioned features.
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Prevent duplicates per company per weekly period.
            $table->unique(['company_id', 'reporting_period_start', 'reporting_period_end'], 'weekly_call_reports_company_period_unique');

            // Fast tenant + period lookups (common for dashboards).
            $table->index(['company_id', 'reporting_period_start'], 'weekly_call_reports_company_start_index');
            $table->index(['company_id', 'reporting_period_end'], 'weekly_call_reports_company_end_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weekly_call_reports');
    }
};
