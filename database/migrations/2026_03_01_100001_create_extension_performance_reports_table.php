<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extension_performance_reports', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('weekly_call_report_id')->nullable()->constrained('weekly_call_reports')->cascadeOnDelete();
            
            // Extension identification
            $table->string('extension')->index();
            $table->string('extension_name')->nullable(); // If available from PBX
            $table->string('department')->nullable()->index();
            
            // Time period
            $table->date('period_start')->index();
            $table->date('period_end')->index();
            
            // Call metrics
            $table->integer('total_calls_answered')->default(0);
            $table->integer('total_calls_made')->default(0);
            $table->integer('total_minutes')->default(0);
            $table->integer('avg_call_duration_seconds')->default(0);
            
            // Category metrics (top 3 categories for this extension)
            $table->json('top_categories')->nullable(); // [{category_id, category_name, count, percentage}]
            
            // Repetitive work analysis
            $table->float('repetitive_category_percentage')->default(0); // % of calls in top 5 categories
            $table->integer('automation_impact_score')->default(0); // minutes * repetitiveness
            
            // Quality indicators
            $table->integer('missed_calls_count')->default(0);
            $table->integer('short_calls_count')->default(0); // < 15 seconds
            $table->float('avg_response_time_seconds')->nullable();
            
            // Detailed breakdown
            $table->json('category_breakdown')->nullable(); // All categories handled
            $table->json('ring_group_breakdown')->nullable(); // Which ring groups they're in
            
            $table->timestamps();
            
            // Unique constraint
            $table->unique(['company_id', 'extension', 'period_start', 'period_end'], 'extension_performance_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_performance_reports');
    }
};
