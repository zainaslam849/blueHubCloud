<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyCallReport extends Model
{
    protected $table = 'weekly_call_reports';

    protected $fillable = [
        'company_id',
        'company_pbx_account_id',
        'week_start_date',
        'week_end_date',
        'total_calls',
        'answered_calls',
        'missed_calls',
        'calls_with_transcription',
        'total_call_duration_seconds',
        'avg_call_duration_seconds',
        'first_call_at',
        'last_call_at',
        'executive_summary',
        'metrics',
        'generated_at',
        'status',
        'pdf_disk',
        'pdf_path',
        'csv_disk',
        'csv_path',
        'error_message',
        'top_extensions',
        'top_call_topics',
    ];

    protected $casts = [
        'week_start_date' => 'date',
        'week_end_date' => 'date',
        'first_call_at' => 'datetime',
        'last_call_at' => 'datetime',
        'generated_at' => 'datetime',
        'total_calls' => 'integer',
        'answered_calls' => 'integer',
        'missed_calls' => 'integer',
        'calls_with_transcription' => 'integer',
        'total_call_duration_seconds' => 'integer',
        'avg_call_duration_seconds' => 'integer',
        'metrics' => 'array',
        'top_extensions' => 'array',
        'top_call_topics' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companyPbxAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyPbxAccount::class);
    }
}
