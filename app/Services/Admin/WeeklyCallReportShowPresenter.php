<?php

namespace App\Services\Admin;

use App\Models\WeeklyCallReport;

class WeeklyCallReportShowPresenter
{
    public function toDetailData(
        WeeklyCallReport $report,
        array $metrics,
        array $categoryBreakdowns,
        array $callEndpoints,
        array $advancedViews,
    ): array {
        return [
            'header' => [
                'id' => $report->id,
                'company' => [
                    'id' => $report->company?->id,
                    'name' => $report->company?->name,
                ],
                'ai_incomplete' => (bool) ($report->ai_incomplete ?? false),
                'ai_incomplete_call_count' => (int) ($report->ai_incomplete_call_count ?? 0),
                'pbx_account' => [
                    'id' => $report->companyPbxAccount?->id,
                    'name' => $report->companyPbxAccount?->tenant_code,
                    'server_id' => $report->companyPbxAccount?->server_id ?? $report->server_id,
                    'pbx_provider_id' => $report->companyPbxAccount?->pbx_provider_id,
                    'display' => $report->companyPbxAccount?->tenant_code
                        ?? ($report->companyPbxAccount?->server_id ? 'Server '.$report->companyPbxAccount->server_id : null)
                        ?? ($report->server_id ? 'Server '.$report->server_id : null),
                ],
                'week_range' => [
                    'start' => $report->week_start_date?->toDateString(),
                    'end' => $report->week_end_date?->toDateString(),
                    'formatted' => $this->formatWeekRange($report),
                ],
                'generated_at' => $report->generated_at?->toIso8601String(),
                'status' => $report->status,
            ],
            'executive_summary' => $report->executive_summary,
            'metrics' => [
                'total_calls' => $report->total_calls,
                'answered_calls' => $report->answered_calls,
                'missed_calls' => $report->missed_calls,
                'answer_rate' => $report->total_calls > 0
                    ? round(($report->answered_calls / $report->total_calls) * 100, 1)
                    : 0,
                'calls_with_transcription' => $report->calls_with_transcription,
                'transcription_rate' => $report->total_calls > 0
                    ? round(($report->calls_with_transcription / $report->total_calls) * 100, 1)
                    : 0,
                'total_call_duration_seconds' => $report->total_call_duration_seconds,
                'avg_call_duration_seconds' => $report->avg_call_duration_seconds,
                'avg_call_duration_formatted' => $this->formatDuration($report->avg_call_duration_seconds ?? 0),
                'first_call_at' => $report->first_call_at?->toIso8601String(),
                'last_call_at' => $report->last_call_at?->toIso8601String(),
            ],
            'category_breakdowns' => $categoryBreakdowns,
            'call_endpoints' => $callEndpoints,
            'insights' => $metrics['insights'] ?? [
                'ai_opportunities' => [],
                'recommendations' => [],
            ],
            'ai_summary' => $metrics['ai_summary'] ?? null,
            'top_extensions' => $report->top_extensions ?? [],
            'top_call_topics' => $report->top_call_topics ?? [],
            'advanced_views' => $advancedViews,
            'exports' => [
                'pdf_available' => ! empty($report->pdf_path),
                'csv_available' => ! empty($report->csv_path),
            ],
        ];
    }

    private function formatWeekRange(WeeklyCallReport $report): string
    {
        $start = $report->week_start_date;
        $end = $report->week_end_date;

        if (! $start || ! $end) {
            return '';
        }

        if ($start->year !== $end->year) {
            return $start->format('F j, Y').' – '.$end->format('F j, Y');
        }

        if ($start->month !== $end->month) {
            return $start->format('F j').' – '.$end->format('F j, Y');
        }

        return $start->format('F j').'–'.$end->format('j, Y');
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds === 1 ? '1 second' : "{$seconds} seconds";
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];

        if ($hours > 0) {
            $parts[] = $hours === 1 ? '1 hour' : "{$hours} hours";
        }

        if ($minutes > 0) {
            $parts[] = $minutes === 1 ? '1 minute' : "{$minutes} minutes";
        }

        return implode(' ', $parts);
    }
}
