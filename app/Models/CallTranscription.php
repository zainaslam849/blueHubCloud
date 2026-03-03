<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CallTranscription Model
 * 
 * Stores transcription text and metadata for calls with recordings.
 * Related to: Call model
 * 
 * Fields:
 * - call_id: Foreign key to calls table
 * - transcript_text: Full transcription text
 * - transcript_confidence: Confidence score from transcription service (0-1)
 * - processed_at: When transcription was processed
 */
class CallTranscription extends Model
{
    protected $table = 'call_transcriptions';

    protected $fillable = [
        'call_id',
        'transcript_text',
        'transcript_confidence',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relationship: This transcription belongs to a call.
     */
    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
