<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WeeklyCallReport;
use App\Services\WeeklyCallReportQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class AdminWeeklyCallReportsController extends Controller
{
    public function index(Request $request, WeeklyCallReportQueryService $service): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
        ]);

        $companyId = (int) $validated['company_id'];

        $reports = $service->getByCompanyId($companyId)->values();

        $mapped = $reports->map(function ($r) {
            $total = (int) ($r['total_calls'] ?? 0);
            $answered = (int) ($r['answered_calls'] ?? 0);

            $weekStart = $r['week_start_date'] ?? null;
            $weekEnd = $r['week_end_date'] ?? null;

            if (is_object($weekStart) && method_exists($weekStart, 'toDateString')) {
                $weekStart = $weekStart->toDateString();
            }

            if (is_object($weekEnd) && method_exists($weekEnd, 'toDateString')) {
                $weekEnd = $weekEnd->toDateString();
            }

            $companyName = $r['company']['name'] ?? $r['company_name'] ?? null;

            return [
                'id' => $r['id'] ?? null,

                // Company (both object and flat name for compatibility)
                'company' => ['name' => $companyName],
                'company_name' => $companyName,

                // Week range (both snake_case and camelCase)
                'week_start_date' => $weekStart,
                'week_end_date' => $weekEnd,
                'weekStartDate' => $weekStart,
                'weekEndDate' => $weekEnd,

                // Metrics (both snake_case and camelCase)
                'total_calls' => $total,
                'answered_calls' => $answered,
                'missed_calls' => (int) ($r['missed_calls'] ?? 0),
                'totalCalls' => $total,
                'answeredCalls' => $answered,
                'missedCalls' => (int) ($r['missed_calls'] ?? 0),

                // Derived metric
                'answer_rate' => $total > 0 ? round(($answered / $total) * 100) : 0,
                'answerRate' => $total > 0 ? (int) round(($answered / $total) * 100) : 0,
            ];
        })->values()->all();

        return response()->json(['data' => $mapped]);
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
        $report = WeeklyCallReport::with(['company:id,name', 'companyPbxAccount:id,pbx_provider_id,server_id,status'])
            ->findOrFail($id);

        // Attempt to get authenticated user from admin guard first, fallback to web guard
        $adminGuardUser = Auth::guard('admin')->user();
        $webGuardUser = Auth::user();
        $user = $adminGuardUser ?? $webGuardUser;

        // Debug logging for authentication issues
        Log::channel('daily')->info('Weekly report access attempt', [
            'report_id' => $id,
            'admin_guard_user' => $adminGuardUser ? ['id' => $adminGuardUser->id, 'email' => $adminGuardUser->email, 'role' => $adminGuardUser->role] : null,
            'web_guard_user' => $webGuardUser ? ['id' => $webGuardUser->id, 'email' => $webGuardUser->email, 'role' => $webGuardUser->role] : null,
            'final_user' => $user ? ['id' => $user->id, 'email' => $user->email, 'role' => $user->role, 'is_admin' => $user->isAdmin()] : null,
        ]);

        // Return 401 if completely unauthenticated
        if (!$user) {
            Log::channel('daily')->warning('Report access denied: no authenticated user', ['report_id' => $id]);
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Return 403 if user lacks admin role
        if (!$user->isAdmin()) {
            Log::channel('daily')->warning('Report access denied: user not admin', [
                'report_id' => $id,
                'user_id' => $user->id,
                'user_role' => $user->role,
            ]);
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

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

                // AI business analysis (if available)
                'ai_summary' => $metrics['ai_summary'] ?? null,

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
