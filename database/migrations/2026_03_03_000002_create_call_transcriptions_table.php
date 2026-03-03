<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PHASE 4: CALL TRANSCRIPTIONS TABLE
     * 
     * Stores transcription text and metadata for calls with recordings.
     * Separate from calls table to keep transcriptions optional.
     */
    public function up(): void
    {
        Schema::create('call_transcriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('call_id')
                ->constrained('calls')
                ->cascadeOnDelete();

            // Full transcription text (may be long)
            $table->longText('transcript_text')->nullable();

            // Confidence score from transcription service (0.0 to 1.0)
            $table->decimal('transcript_confidence', 3, 2)->default(0.0);

            // When this transcription was processed/stored
            $table->timestamp('processed_at')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index('call_id');
            $table->index('processed_at');

            // Unique constraint: one transcription per call
            $table->unique('call_id');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_transcriptions');
    }
};
