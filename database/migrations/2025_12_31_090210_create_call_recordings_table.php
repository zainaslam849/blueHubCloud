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
        Schema::create('call_recordings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->foreignId('pbx_provider_id')
                ->constrained('pbx_providers')
                ->cascadeOnDelete();

            $table->foreignId('call_id')
                ->constrained('calls')
                ->cascadeOnDelete();

            $table->string('recording_url');
            $table->integer('recording_duration')->default(0);
            $table->string('storage_provider')->default('s3');

            $table->enum('status', ['uploaded', 'stored', 'queued', 'processing', 'completed', 'transcribing', 'transcribed', 'failed'])
                ->default('uploaded');
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('storage_path')->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->text('error_message')->nullable();

            $table->index(['company_id', 'status']);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_recordings');
    }
};
