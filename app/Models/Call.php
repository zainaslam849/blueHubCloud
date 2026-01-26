<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Call extends Model
{
    protected $fillable = [
        'company_id',
        'company_pbx_account_id',
        'server_id',
        'pbx_unique_id',
        'from',
        'to',
        'did',
        'category',
        'sub_category',
        'direction',
        'status',
        'started_at',
        'duration_seconds',
        'ended_at',
        'weekly_call_report_id',
        'has_transcription',
        'transcript_text',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companyPbxAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyPbxAccount::class);
    }

    /**
     * Scope to filter calls by weekly report id
     */
    public function scopeWhereWeeklyReport($query, $reportId)
    {
        return $query->where('weekly_call_report_id', $reportId);
    }
}
