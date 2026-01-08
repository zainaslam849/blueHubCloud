<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscriptionUsage extends Model
{
    protected $fillable = [
        'company_id',
        'call_recording_id',
        'provider_name',
        'language',
        'duration_seconds',
        'cost_estimate',
        'currency',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function callRecording(): BelongsTo
    {
        return $this->belongsTo(CallRecording::class);
    }
}
