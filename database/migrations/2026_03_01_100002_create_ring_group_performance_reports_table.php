<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ring_group_performance_reports', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('weekly_call_report_id')->nullable()->constrained('weekly_call_reports')->cascadeOnDelete();
            
            // Ring group identification
            $table->string('ring_group')->index();
            $table->string('ring_group_name')->nullable();
            $table->string('department')->nullable()->index();
            
            // Time period
            $table->date('period_start')->index();
            $table->date('period_end')->index();
            
            // Call metrics
            $table->integer('total_calls')->default(0);
            $table->integer('answered_calls')->default(0);
            $table->integer('missed_calls')->default(0);
            $table->integer('abandoned_calls')->default(0);
            $table->integer('total_minutes')->default(0);
            
            // Category analysis
            $table->json('top_categories')->nullable(); // [{category_id, category_name, count, minutes, percentage}]
            $table->json('time_sink_categories')->nullable(); // Categories sorted by total minutes
            
            // Automation opportunities
            $table->json('automation_opportunities')->nullable(); // High-volume repetitive categories
            $table->integer('automation_priority_score')->default(0);
            
            // Peak times analysis
            $table->json('peak_missed_times')->nullable(); // When most missed calls occur
            $table->json('hourly_distribution')->nullable(); // Call distribution by hour
            
            // Extensions in this ring group
            $table->json('extension_stats')->nullable(); // Performance by extension
            
            $table->timestamps();
            
            $table->unique(['company_id', 'ring_group', 'period_start', 'period_end'], 'ring_group_performance_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ring_group_performance_reports');
    }
};
