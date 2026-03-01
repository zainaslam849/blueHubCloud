<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryAnalyticsReport extends Model
{
    protected $table = 'category_analytics_reports';

    protected $fillable = [
        'company_id',
        'category_id',
        'weekly_call_report_id',
        'period_start',
        'period_end',
        'total_calls',
        'total_minutes',
        'average_call_duration_seconds',
        'extension_breakdown',
        'ring_group_breakdown',
        'sub_category_breakdown',
        'daily_trend',
        'hourly_trend',
        'trend_direction',
        'trend_percentage_change',
        'is_automation_candidate',
        'automation_priority',
        'suggested_automations',
        'sample_call_ids',
        'avg_confidence_score',
        'low_confidence_count',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'extension_breakdown' => 'array',
        'ring_group_breakdown' => 'array',
        'sub_category_breakdown' => 'array',
        'daily_trend' => 'array',
        'hourly_trend' => 'array',
        'suggested_automations' => 'array',
        'sample_call_ids' => 'array',
        'total_calls' => 'integer',
        'total_minutes' => 'integer',
        'average_call_duration_seconds' => 'float',
        'trend_direction' => 'integer',
        'trend_percentage_change' => 'float',
        'is_automation_candidate' => 'boolean',
        'avg_confidence_score' => 'float',
        'low_confidence_count' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CallCategory::class, 'category_id');
    }

    public function weeklyCallReport(): BelongsTo
    {
        return $this->belongsTo(WeeklyCallReport::class);
    }
}
