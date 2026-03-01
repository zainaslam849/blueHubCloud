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
        'answered_by_extension',
        'caller_extension',
        'ring_group',
        'queue_name',
        'department',
        'pbx_metadata',
        'direction',
        'status',
        'started_at',
        'duration_seconds',
        'ended_at',
        'weekly_call_report_id',
        'has_transcription',
        'transcript_text',
        'ai_summary',
        'transcription_checked_at',
        'category_id',
        'sub_category_id',
        'sub_category_label',
        'category_source',
        'category_confidence',
        'categorized_at',
    ];

    protected $casts = [
        'has_transcription' => 'boolean',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'transcription_checked_at' => 'datetime',
        'categorized_at' => 'datetime',
        'category_confidence' => 'float',
        'pbx_metadata' => 'array',
    ];

    // Hide old category columns from serialization
    protected $hidden = [
        'category',
        'sub_category',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companyPbxAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyPbxAccount::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CallCategory::class, 'category_id', 'id');
    }

    public function subCategory(): BelongsTo
    {
        return $this->belongsTo(SubCategory::class, 'sub_category_id', 'id');
    }

    /**
     * Scope to filter calls by weekly report id
     */
    public function scopeWhereWeeklyReport($query, $reportId)
    {
        return $query->where('weekly_call_report_id', $reportId);
    }
}
