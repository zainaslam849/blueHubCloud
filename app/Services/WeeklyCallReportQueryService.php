<?php

namespace App\Services;

use App\Models\WeeklyCallReport;
use Illuminate\Support\Collection;

class WeeklyCallReportQueryService
{
    /**
     * Fetch weekly call reports for a company (read-only).
     *
     * Returns raw fields only (no formatting, no labels beyond column names).
     */
    public function getByCompanyId(int $companyId): Collection
    {
        return WeeklyCallReport::query()
            ->where('company_id', $companyId)
            ->orderByDesc('week_start_date')
            ->get([
                'id',
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
                'created_at',
                'updated_at',
            ])
            ->map(fn (WeeklyCallReport $r) => $r->toArray());
    }
}
