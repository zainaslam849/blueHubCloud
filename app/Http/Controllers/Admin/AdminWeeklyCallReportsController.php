<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WeeklyCallReport;
use App\Services\WeeklyCallReportQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdminWeeklyCallReportsController extends Controller
{
    public function index(Request $request, WeeklyCallReportQueryService $service): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $companyId = $validated['company_id'] ?? null;

        // If no company_id provided from the client, default to the first
        // company that has an existing weekly report so the admin UI can
        // display reports without requiring an explicit company selection.
        if (! $companyId) {
            $first = WeeklyCallReport::select('company_id')->first();
            if (! $first) {
                return response()->json(['data' => []]);
            }

            $companyId = $first->company_id;
        }

        $companyId = (int) $companyId;

        return response()->json([
            'data' => $service->getByCompanyId($companyId),
        ]);
    }

    /**
     * GET /admin/api/weekly-call-reports/{id}
     *
     * Returns full report details including:
     * - Header info (company, week range)
     * - Executive summary
     * - Quantitative metrics
     * - Category breakdowns
     * - Insights
     */
    public function show(int $id): JsonResponse
    {
        $report = WeeklyCallReport::with(['company:id,name', 'companyPbxAccount:id,name'])
            ->findOrFail($id);

        // Policy check for role-based access
        Gate::authorize('view', $report);

        $metrics = $report->metrics ?? [];

        return response()->json([
            'data' => [
                // Header info
                'header' => [
                    'id' => $report->id,
                    'company' => [
                        'id' => $report->company?->id,
                        'name' => $report->company?->name,
                    ],
                    'pbx_account' => [
                        'id' => $report->companyPbxAccount?->id,
                        'name' => $report->companyPbxAccount?->name,
                    ],
                    'week_range' => [
                        'start' => $report->week_start_date?->toDateString(),
                        'end' => $report->week_end_date?->toDateString(),
                        'formatted' => $this->formatWeekRange($report),
                    ],
                    'generated_at' => $report->generated_at?->toIso8601String(),
                    'status' => $report->status,
                ],

                // Executive summary
                'executive_summary' => $report->executive_summary,

                // Quantitative metrics
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

                // Category breakdowns
                'category_breakdowns' => [
                    'counts' => $metrics['category_counts'] ?? [],
                    'details' => $metrics['category_breakdowns'] ?? [],
                    'top_dids' => $metrics['top_dids'] ?? [],
                    'hourly_distribution' => $metrics['hourly_distribution'] ?? [],
                ],

                // Insights
                'insights' => $metrics['insights'] ?? [
                    'ai_opportunities' => [],
                    'recommendations' => [],
                ],

                // Additional data
                'top_extensions' => $report->top_extensions ?? [],
                'top_call_topics' => $report->top_call_topics ?? [],

                // Export info
                'exports' => [
                    'pdf_available' => ! empty($report->pdf_path),
                    'csv_available' => ! empty($report->csv_path),
                ],
            ],
        ]);
    }

    /**
     * Format week range for display.
     */
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

    /**
     * Format duration in seconds to human-readable format.
     */
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
