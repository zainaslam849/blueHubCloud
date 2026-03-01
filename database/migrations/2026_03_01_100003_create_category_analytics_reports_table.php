<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_analytics_reports', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('call_categories')->cascadeOnDelete();
            $table->foreignId('weekly_call_report_id')->nullable()->constrained('weekly_call_reports')->cascadeOnDelete();
            
            // Time period
            $table->date('period_start')->index();
            $table->date('period_end')->index();
            
            // Volume metrics
            $table->integer('total_calls')->default(0);
            $table->integer('total_minutes')->default(0);
            $table->float('average_call_duration_seconds')->default(0);
            
            // Distribution analysis
            $table->json('extension_breakdown')->nullable(); // Which extensions handle this category most
            $table->json('ring_group_breakdown')->nullable(); // Which ring groups generate this category
            $table->json('sub_category_breakdown')->nullable(); // Sub-category distribution
            
            // Time trend
            $table->json('daily_trend')->nullable(); // Count by day of week
            $table->json('hourly_trend')->nullable(); // Count by hour
            $table->integer('trend_direction')->default(0); // -1 = down, 0 = stable, 1 = up
            $table->float('trend_percentage_change')->default(0); // vs previous period
            
            // Automation analysis
            $table->boolean('is_automation_candidate')->default(false);
            $table->string('automation_priority')->default('low'); // low, medium, high
            $table->json('suggested_automations')->nullable(); // AI-generated automation suggestions
            
            // Example calls (for drill-down)
            $table->json('sample_call_ids')->nullable(); // Array of call IDs for examples
            
            // Quality metrics
            $table->float('avg_confidence_score')->nullable();
            $table->integer('low_confidence_count')->default(0);
            
            $table->timestamps();
            
            $table->unique(['company_id', 'category_id', 'period_start', 'period_end'], 'category_analytics_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_analytics_reports');
    }
};
