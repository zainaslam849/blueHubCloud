<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RingGroupPerformanceReport extends Model
{
    protected $table = 'ring_group_performance_reports';

    protected $fillable = [
        'company_id',
        'weekly_call_report_id',
        'ring_group',
        'ring_group_name',
        'department',
        'period_start',
        'period_end',
        'total_calls',
        'answered_calls',
        'missed_calls',
        'abandoned_calls',
        'total_minutes',
        'top_categories',
        'time_sink_categories',
        'automation_opportunities',
        'automation_priority_score',
        'peak_missed_times',
        'hourly_distribution',
        'extension_stats',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'top_categories' => 'array',
        'time_sink_categories' => 'array',
        'automation_opportunities' => 'array',
        'peak_missed_times' => 'array',
        'hourly_distribution' => 'array',
        'extension_stats' => 'array',
        'total_calls' => 'integer',
        'answered_calls' => 'integer',
        'missed_calls' => 'integer',
        'abandoned_calls' => 'integer',
        'total_minutes' => 'integer',
        'automation_priority_score' => 'integer',
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
