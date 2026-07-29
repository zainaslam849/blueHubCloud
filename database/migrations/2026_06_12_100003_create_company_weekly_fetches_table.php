<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_weekly_fetches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_pbx_account_id')->nullable()->constrained()->nullOnDelete();
            $table->date('week_start_date');
            $table->date('week_end_date');
            $table->unsignedInteger('calls_available')->default(0);
            $table->unsignedInteger('calls_fetched')->default(0);
            $table->unsignedInteger('calls_blocked')->default(0);
            // complete | partial (blocked by limit) | paused (period expired)
            $table->string('status')->default('complete');
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'week_start_date']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_weekly_fetches');
    }
};
