<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallTranscription extends Model
{
    protected $fillable = [
        'call_id',
        'provider_name',
        'language',
        'transcript_text',
        'duration_seconds',
        'confidence_score',
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
