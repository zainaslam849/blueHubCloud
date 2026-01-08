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
        Schema::create('transcription_usages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->foreignId('call_recording_id')
                ->constrained('call_recordings')
                ->cascadeOnDelete();

            $table->string('provider_name');
            $table->string('language')->default('en');
            $table->integer('duration_seconds')->default(0);

            // Billing-ready: store an estimated cost for reporting and invoicing.
            $table->decimal('cost_estimate', 10, 4)->default(0);
            $table->string('currency', 3)->default('USD');

            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'provider_name']);
            $table->unique(
                ['call_recording_id', 'provider_name', 'language'],
                'transcription_usages_recording_provider_lang_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transcription_usages');
    }
};
