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
        if (! Schema::hasTable('pipeline_runs')) {
            Schema::create('pipeline_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->date('range_from');
                $table->date('range_to');
                $table->string('status', 40)->default('queued')->index();
                $table->string('current_stage', 80)->nullable();
                $table->unsignedBigInteger('resume_count')->default(0);
                $table->unsignedBigInteger('triggered_by_user_id')->nullable();
                $table->unsignedBigInteger('resumed_from_run_id')->nullable();
                $table->string('active_key', 160)->nullable()->index();
                $table->text('last_error')->nullable();
                $table->json('metrics')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'range_from', 'range_to'], 'pipeline_runs_company_range_idx');
            });
        }

        if (! Schema::hasTable('pipeline_run_stages')) {
            Schema::create('pipeline_run_stages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pipeline_run_id')->constrained()->cascadeOnDelete();
                $table->string('stage_key', 80);
                $table->string('status', 40)->default('pending')->index();
                $table->text('error_message')->nullable();
                $table->json('metrics')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->unique(['pipeline_run_id', 'stage_key'], 'pipeline_run_stage_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pipeline_run_stages');
        Schema::dropIfExists('pipeline_runs');
    }
};
