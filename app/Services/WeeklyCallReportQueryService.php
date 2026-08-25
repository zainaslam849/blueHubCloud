<?php

namespace App\Services;

use App\Models\WeeklyCallReport;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;

class WeeklyCallReportQueryService
{
    /**
     * Fetch weekly call reports across all companies (read-only).
     *
     * Returns raw fields only (no formatting, no labels beyond column names).
     */
    public function getAll(): Collection
    {
        return $this->baseQuery()
            ->orderByDesc('week_start_date')
            ->get($this->baseSelect())
            ->map(fn ($r) => $this->normalizeRow($r))
            ->values();
    }

    /**
     * Fetch weekly call reports for a company (read-only).
     *
     * Returns raw fields only (no formatting, no labels beyond column names).
     */
    public function getByCompanyId(int $companyId): Collection
    {
        return $this->baseQuery()
            ->where('company_id', $companyId)
            ->orderByDesc('week_start_date')
            ->get($this->baseSelect())
            ->map(fn ($r) => $this->normalizeRow($r))
            ->values();
    }

    private function baseQuery(): Builder
    {
        return WeeklyCallReport::query()
            ->leftJoin('companies', 'companies.id', '=', 'weekly_call_reports.company_id');
    }

    /**
     * @return array<int,string>
     */
    private function baseSelect(): array
    {
        return [
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
            'weekly_call_reports.minutes_consumed',
            'weekly_call_reports.first_call_at',
            'weekly_call_reports.last_call_at',
            'weekly_call_reports.created_at',
            'weekly_call_reports.updated_at',
            'companies.name as company_name',
            'companies.slug as company_slug',
        ];
    }

    /**
     * @param mixed $r
     * @return array<string,mixed>
     */
    private function normalizeRow($r): array
    {
        // Capture these as clean Y-m-d before toArray() serializes the 'date'
        // casts (no explicit format) to a full ISO datetime string — the
        // frontend needs the plain date for the /reports/{company}/{week} URL.
        $weekStart = $r->week_start_date?->toDateString();
        $weekEnd = $r->week_end_date?->toDateString();

        if (is_object($r) && method_exists($r, 'toArray')) {
            $arr = $r->toArray();
        } else {
            $arr = (array) $r;
        }

        $arr['week_start_date'] = $weekStart;
        $arr['week_end_date'] = $weekEnd;
        $arr['company'] = ['name' => $arr['company_name'] ?? null, 'slug' => $arr['company_slug'] ?? null];

        return $arr;
    }
}
