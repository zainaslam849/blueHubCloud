<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallSpeakerSegment extends Model
{
    protected $fillable = [
        'call_id',
        'speaker_label',
        'start_second',
        'end_second',
        'text',
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
