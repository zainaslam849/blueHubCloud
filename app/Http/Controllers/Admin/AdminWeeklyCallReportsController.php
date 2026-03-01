<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\CategoryAnalyticsReport;
use App\Models\ExtensionPerformanceReport;
use App\Models\RingGroupPerformanceReport;
use App\Models\WeeklyCallReport;
use App\Services\WeeklyCallReportQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminWeeklyCallReportsController extends Controller
{
    public function index(Request $request, WeeklyCallReportQueryService $service): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        // If company_id provided, use service method; otherwise get all reports
        if (!empty($validated['company_id'])) {
            $reports = $service->getByCompanyId((int) $validated['company_id']);
        } else {
            // Get all reports across all companies
            $reports = WeeklyCallReport::query()
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

        $reports = $reports->values();

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

        $categoryBreakdowns = [
            'counts' => $metrics['category_counts'] ?? [],
            'details' => $metrics['category_breakdowns'] ?? [],
            'top_dids' => $metrics['top_dids'] ?? [],
            'hourly_distribution' => $metrics['hourly_distribution'] ?? [],
        ];

        $hasCategoryCounts = false;
        if (is_array($categoryBreakdowns['counts'])) {
            if (array_is_list($categoryBreakdowns['counts'])) {
                foreach ($categoryBreakdowns['counts'] as $item) {
                    if (is_array($item) && ((int) ($item['call_count'] ?? 0) > 0)) {
                        $hasCategoryCounts = true;
                        break;
                    }
                }
            } else {
                $hasCategoryCounts = count($categoryBreakdowns['counts']) > 0;
            }
        }

        if (! $hasCategoryCounts) {
            $fallback = $this->buildCategoryBreakdownFromReportCalls($report->id);
            if (! empty($fallback['counts'])) {
                $categoryBreakdowns = $fallback;
            }
        }

        // Build advanced views with error handling for missing tables
        try {
            $advancedViews = $this->buildAdvancedViews($report, $categoryBreakdowns);
        } catch (\Throwable $e) {
            Log::warning('Failed to build advanced views for report', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);
            $advancedViews = $this->getEmptyAdvancedViews();
        }

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
                'category_breakdowns' => $categoryBreakdowns,

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

                // Advanced dashboards (meeting requirements)
                'advanced_views' => $advancedViews,

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

    /**
     * Build category breakdown from calls assigned to a specific weekly report.
     * Used as fallback when persisted metrics are stale/empty.
     */
    private function buildCategoryBreakdownFromReportCalls(int $reportId): array
    {
        $rows = Call::query()
            ->leftJoin('call_categories', 'call_categories.id', '=', 'calls.category_id')
            ->leftJoin('sub_categories', 'sub_categories.id', '=', 'calls.sub_category_id')
            ->where('calls.weekly_call_report_id', $reportId)
            ->whereNotNull('calls.category_id')
            ->select([
                'calls.category_id',
                DB::raw('COALESCE(call_categories.name, CONCAT("Category #", calls.category_id)) as category_name'),
                'calls.sub_category_id',
                DB::raw('COALESCE(sub_categories.name, calls.sub_category_label) as sub_category_name'),
                'calls.did',
                DB::raw('HOUR(calls.started_at) as hour_bucket'),
            ])
            ->get();

        if ($rows->isEmpty()) {
            return [
                'counts' => [],
                'details' => [],
                'top_dids' => [],
                'hourly_distribution' => [],
            ];
        }

        $counts = [];
        $details = [];
        $didCounts = [];
        $hourly = [];

        foreach ($rows as $row) {
            $categoryName = (string) ($row->category_name ?? 'Uncategorized');
            $subCategoryName = is_string($row->sub_category_name ?? null) ? trim((string) $row->sub_category_name) : '';
            $did = is_string($row->did ?? null) ? trim((string) $row->did) : '';
            $hour = isset($row->hour_bucket) ? (string) ((int) $row->hour_bucket) : null;

            $counts[$categoryName] = ($counts[$categoryName] ?? 0) + 1;

            if (! isset($details[$categoryName])) {
                $details[$categoryName] = [
                    'count' => 0,
                    'sub_categories' => [],
                    'sample_calls' => [],
                ];
            }

            $details[$categoryName]['count']++;

            if ($subCategoryName !== '') {
                $details[$categoryName]['sub_categories'][$subCategoryName] = ($details[$categoryName]['sub_categories'][$subCategoryName] ?? 0) + 1;
            }

            if ($did !== '') {
                $didCounts[$did] = ($didCounts[$did] ?? 0) + 1;
            }

            if ($hour !== null) {
                $hourly[$hour] = ($hourly[$hour] ?? 0) + 1;
            }
        }

        arsort($didCounts);
        $topDids = [];
        foreach (array_slice($didCounts, 0, 10, true) as $did => $count) {
            $topDids[] = [
                'did' => $did,
                'calls' => $count,
            ];
        }

        return [
            'counts' => $counts,
            'details' => $details,
            'top_dids' => $topDids,
            'hourly_distribution' => $hourly,
        ];
    }

    /**
     * Build all advanced report views requested in product meetings.
     */
    private function buildAdvancedViews(WeeklyCallReport $report, array $categoryBreakdowns): array
    {
        $extensionReports = ExtensionPerformanceReport::query()
            ->where('weekly_call_report_id', $report->id)
            ->orderByDesc('automation_impact_score')
            ->get();

        $ringGroupReports = RingGroupPerformanceReport::query()
            ->where('weekly_call_report_id', $report->id)
            ->orderByDesc('automation_priority_score')
            ->get();

        $categoryReports = CategoryAnalyticsReport::query()
            ->with('category:id,name')
            ->where('weekly_call_report_id', $report->id)
            ->orderByDesc('total_calls')
            ->get();

        $topCategories = $this->normalizeCategoryCountsForDisplay($categoryBreakdowns['counts'] ?? []);

        $topAutomationOpportunities = $categoryReports
            ->filter(fn ($row) => (bool) $row->is_automation_candidate)
            ->sort(function ($a, $b) {
                $prio = ['high' => 3, 'medium' => 2, 'low' => 1];
                return ($prio[$b->automation_priority] ?? 0) <=> ($prio[$a->automation_priority] ?? 0)
                    ?: (($b->total_minutes ?? 0) <=> ($a->total_minutes ?? 0));
            })
            ->take(10)
            ->map(function ($row) {
                return [
                    'category_id' => $row->category_id,
                    'category_name' => $row->category?->name,
                    'priority' => $row->automation_priority,
                    'total_calls' => (int) ($row->total_calls ?? 0),
                    'total_minutes' => (int) ($row->total_minutes ?? 0),
                    'suggested_automations' => $row->suggested_automations ?? [],
                ];
            })
            ->values()
            ->all();

        $missedByHour = [];
        foreach ($ringGroupReports as $ringGroup) {
            foreach (($ringGroup->peak_missed_times ?? []) as $peak) {
                $hourLabel = (string) ($peak['hour_label'] ?? ($peak['hour'] ?? 'unknown'));
                $missedByHour[$hourLabel] = ($missedByHour[$hourLabel] ?? 0) + (int) ($peak['missed_count'] ?? 0);
            }
        }
        arsort($missedByHour);

        $peakMissedTimes = [];
        foreach (array_slice($missedByHour, 0, 5, true) as $label => $count) {
            $peakMissedTimes[] = [
                'hour_label' => $label,
                'missed_count' => $count,
            ];
        }

        $previous = WeeklyCallReport::query()
            ->where('company_id', $report->company_id)
            ->where('id', '!=', $report->id)
            ->whereDate('week_start_date', '<', $report->week_start_date)
            ->orderByDesc('week_start_date')
            ->first();

        $trend = [
            'has_previous' => (bool) $previous,
            'period' => 'weekly',
            'calls_delta' => null,
            'calls_delta_pct' => null,
            'minutes_delta' => null,
            'minutes_delta_pct' => null,
            'missed_delta' => null,
        ];

        if ($previous) {
            $currentCalls = (int) ($report->total_calls ?? 0);
            $prevCalls = (int) ($previous->total_calls ?? 0);
            $currentMinutes = intdiv((int) ($report->total_call_duration_seconds ?? 0), 60);
            $prevMinutes = intdiv((int) ($previous->total_call_duration_seconds ?? 0), 60);

            $trend['calls_delta'] = $currentCalls - $prevCalls;
            $trend['calls_delta_pct'] = $prevCalls > 0 ? round((($currentCalls - $prevCalls) / $prevCalls) * 100, 1) : null;
            $trend['minutes_delta'] = $currentMinutes - $prevMinutes;
            $trend['minutes_delta_pct'] = $prevMinutes > 0 ? round((($currentMinutes - $prevMinutes) / $prevMinutes) * 100, 1) : null;
            $trend['missed_delta'] = (int) ($report->missed_calls ?? 0) - (int) ($previous->missed_calls ?? 0);
        }

        $ringGroupDashboard = $ringGroupReports->map(function ($row) {
            return [
                'ring_group' => $row->ring_group,
                'ring_group_name' => $row->ring_group_name,
                'department' => $row->department,
                'total_calls' => (int) ($row->total_calls ?? 0),
                'answered_calls' => (int) ($row->answered_calls ?? 0),
                'missed_calls' => (int) ($row->missed_calls ?? 0),
                'abandoned_calls' => (int) ($row->abandoned_calls ?? 0),
                'total_minutes' => (int) ($row->total_minutes ?? 0),
                'top_categories' => $row->top_categories ?? [],
                'time_sink_categories' => $row->time_sink_categories ?? [],
                'automation_opportunities' => $row->automation_opportunities ?? [],
                'automation_priority_score' => (int) ($row->automation_priority_score ?? 0),
                'peak_missed_times' => $row->peak_missed_times ?? [],
            ];
        })->values()->all();

        $extensionLeaderboard = $extensionReports->map(function ($row) {
            return [
                'extension' => $row->extension,
                'department' => $row->department,
                'calls_answered' => (int) ($row->total_calls_answered ?? 0),
                'calls_made' => (int) ($row->total_calls_made ?? 0),
                'total_minutes' => (int) ($row->total_minutes ?? 0),
                'top_categories' => $row->top_categories ?? [],
                'repetitive_percentage' => (float) ($row->repetitive_category_percentage ?? 0),
                'automation_impact_score' => (int) ($row->automation_impact_score ?? 0),
                'follow_up_flags_count' => 0,
            ];
        })->values()->all();

        $topExtensions = $extensionReports->take(5);
        $extensionScorecards = $topExtensions->map(function ($row) use ($report) {
            $timeline = Call::query()
                ->leftJoin('call_categories', 'call_categories.id', '=', 'calls.category_id')
                ->leftJoin('sub_categories', 'sub_categories.id', '=', 'calls.sub_category_id')
                ->where('calls.weekly_call_report_id', $report->id)
                ->where('calls.answered_by_extension', $row->extension)
                ->orderByDesc('calls.started_at')
                ->limit(25)
                ->get([
                    'calls.id',
                    'calls.started_at',
                    'calls.duration_seconds',
                    'calls.did',
                    'calls.pbx_unique_id',
                    'calls.transcript_text',
                    DB::raw('call_categories.name as category_name'),
                    DB::raw('sub_categories.name as sub_category_name'),
                ]);

            $samples = $timeline
                ->filter(fn ($call) => is_string($call->transcript_text ?? null) && trim((string) $call->transcript_text) !== '')
                ->take(5)
                ->map(function ($call) {
                    $snippet = trim((string) $call->transcript_text);
                    if (mb_strlen($snippet) > 180) {
                        $snippet = mb_substr($snippet, 0, 180).'...';
                    }

                    return [
                        'call_id' => $call->id,
                        'started_at' => $call->started_at,
                        'did' => $call->did,
                        'snippet' => $snippet,
                        'recording_or_transcript_link' => '/admin/calls/'.$call->id,
                    ];
                })
                ->values()
                ->all();

            $recommendedActions = [];
            foreach (array_slice($row->top_categories ?? [], 0, 3) as $category) {
                $name = (string) ($category['category_name'] ?? 'Category');
                $recommendedActions[] = "Create template flow for {$name}";
            }

            return [
                'extension' => $row->extension,
                'department' => $row->department,
                'timeline' => $timeline->map(function ($call) {
                    return [
                        'call_id' => $call->id,
                        'started_at' => $call->started_at,
                        'duration_seconds' => (int) ($call->duration_seconds ?? 0),
                        'category_name' => $call->category_name,
                        'sub_category_name' => $call->sub_category_name,
                    ];
                })->values()->all(),
                'top_automation_candidates' => array_slice($row->top_categories ?? [], 0, 3),
                'examples' => $samples,
                'recommended_actions' => $recommendedActions,
            ];
        })->values()->all();

        $categoryDrilldown = $categoryReports->map(function ($row) {
            $topExtension = null;
            $topExtensionCount = 0;
            foreach (($row->extension_breakdown ?? []) as $ext => $count) {
                if ((int) $count > $topExtensionCount) {
                    $topExtension = (string) $ext;
                    $topExtensionCount = (int) $count;
                }
            }

            $topRingGroup = null;
            $topRingGroupCount = 0;
            foreach (($row->ring_group_breakdown ?? []) as $rg => $count) {
                if ((int) $count > $topRingGroupCount) {
                    $topRingGroup = (string) $rg;
                    $topRingGroupCount = (int) $count;
                }
            }

            return [
                'category_id' => $row->category_id,
                'category_name' => $row->category?->name,
                'total_calls' => (int) ($row->total_calls ?? 0),
                'total_minutes' => (int) ($row->total_minutes ?? 0),
                'extension_breakdown' => $row->extension_breakdown ?? [],
                'ring_group_breakdown' => $row->ring_group_breakdown ?? [],
                'daily_trend' => $row->daily_trend ?? [],
                'hourly_trend' => $row->hourly_trend ?? [],
                'trend_direction' => (int) ($row->trend_direction ?? 0),
                'trend_percentage_change' => (float) ($row->trend_percentage_change ?? 0),
                'top_extension' => $topExtension,
                'top_ring_group' => $topRingGroup,
                'suggested_automations' => $row->suggested_automations ?? [],
            ];
        })->values()->all();

        return [
            'company_dashboard' => [
                'summary' => [
                    'total_calls' => (int) ($report->total_calls ?? 0),
                    'total_minutes' => intdiv((int) ($report->total_call_duration_seconds ?? 0), 60),
                    'missed_calls' => (int) ($report->missed_calls ?? 0),
                ],
                'top_categories' => $topCategories,
                'top_automation_opportunities' => $topAutomationOpportunities,
                'peak_missed_times' => $peakMissedTimes,
                'trend_vs_last_period' => $trend,
            ],
            'ring_group_dashboard' => $ringGroupDashboard,
            'extension_leaderboard' => $extensionLeaderboard,
            'extension_scorecards' => $extensionScorecards,
            'category_drilldown' => $categoryDrilldown,
        ];
    }

    /**
     * Normalize category counts to a display-friendly list.
     */
    private function normalizeCategoryCountsForDisplay(array $counts): array
    {
        if (array_is_list($counts)) {
            $normalized = [];
            foreach ($counts as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $name = (string) ($row['category_name'] ?? $row['name'] ?? 'Unknown');
                $count = (int) ($row['call_count'] ?? $row['count'] ?? 0);
                if ($count <= 0) {
                    continue;
                }

                $normalized[] = [
                    'name' => $name,
                    'count' => $count,
                ];
            }

            usort($normalized, fn ($a, $b) => $b['count'] <=> $a['count']);
            return $normalized;
        }

        $normalized = [];
        foreach ($counts as $name => $count) {
            $normalized[] = [
                'name' => (string) $name,
                'count' => (int) $count,
            ];
        }
        usort($normalized, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $normalized;
    }

    /**
     * Return empty advanced views structure when tables don't exist or data unavailable
     */
    private function getEmptyAdvancedViews(): array
    {
        return [
            'company_dashboard' => [
                'summary' => [
                    'total_calls' => 0,
                    'total_minutes' => 0,
                    'answer_rate' => 0,
                    'missed_calls' => 0,
                ],
                'top_categories' => [],
                'top_automation_opportunities' => [],
                'peak_missed_times' => [],
                'trend_vs_last_period' => [
                    'has_previous' => false,
                    'period' => 'weekly',
                    'calls_delta' => null,
                    'calls_delta_pct' => null,
                ],
            ],
            'ring_group_dashboard' => [],
            'extension_leaderboard' => [],
            'extension_scorecards' => [],
            'category_drilldown' => [],
        ];
    }
}
