<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtensionPerformanceReport extends Model
{
    protected $table = 'extension_performance_reports';

    protected $fillable = [
        'company_id',
        'weekly_call_report_id',
        'extension',
        'extension_name',
        'department',
        'period_start',
        'period_end',
        'total_calls_answered',
        'total_calls_made',
        'total_minutes',
        'avg_call_duration_seconds',
        'top_categories',
        'repetitive_category_percentage',
        'automation_impact_score',
        'missed_calls_count',
        'short_calls_count',
        'avg_response_time_seconds',
        'category_breakdown',
        'ring_group_breakdown',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'top_categories' => 'array',
        'category_breakdown' => 'array',
        'ring_group_breakdown' => 'array',
        'total_calls_answered' => 'integer',
        'total_calls_made' => 'integer',
        'total_minutes' => 'integer',
        'avg_call_duration_seconds' => 'integer',
        'repetitive_category_percentage' => 'float',
        'automation_impact_score' => 'integer',
        'missed_calls_count' => 'integer',
        'short_calls_count' => 'integer',
        'avg_response_time_seconds' => 'float',
    ];

    public function company(): BelongsTo
   {
        return $this->belongsTo(Company::class);
    }

    public function weeklyCallReport(): BelongsTo
    {
        return $this->belongsTo(WeeklyCallReport::class);
    }
}
