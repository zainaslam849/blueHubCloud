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
            ->leftJoin('companies', 'companies.id', '=', 'weekly_call_reports.company_id')
            ->orderByDesc('week_start_date')
            ->get([
                'weekly_call_reports.id',
                'weekly_call_reports.company_id',
                'weekly_call_reports.server_id',
                'weekly_call_reports.company_pbx_account_id',
                'weekly_call_reports.week_start_date',
                'weekly_call_reports.week_end_date',
                'weekly_call_reports.total_calls',
                'weekly_call_reports.answered_calls',
                'weekly_call_reports.missed_calls',
                'weekly_call_reports.calls_with_transcription',
                'weekly_call_reports.total_call_duration_seconds',
                'weekly_call_reports.avg_call_duration_seconds',
                'weekly_call_reports.first_call_at',
                'weekly_call_reports.last_call_at',
                'weekly_call_reports.created_at',
                'weekly_call_reports.updated_at',
                'companies.name as company_name',
            ])
            ->map(function ($r) {
                if (is_object($r) && method_exists($r, 'toArray')) {
                    $arr = $r->toArray();
                } else {
                    $arr = (array) $r;
                }

                $arr['company'] = ['name' => $arr['company_name'] ?? null];
                return $arr;
            })
            ->values();
    }
}
