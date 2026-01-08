<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Call extends Model
{
    protected $fillable = [
        'company_id',
        'company_pbx_account_id',
        'call_uid',
        'direction',
        'from_number',
        'to_number',
        'started_at',
        'ended_at',
        'duration_seconds',
        'status',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companyPbxAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyPbxAccount::class);
    }

    public function callRecordings(): HasMany
    {
        return $this->hasMany(CallRecording::class);
    }

    public function callTranscriptions(): HasMany
    {
        return $this->hasMany(CallTranscription::class);
    }

    public function callSpeakerSegments(): HasMany
    {
        return $this->hasMany(CallSpeakerSegment::class);
    }
}
