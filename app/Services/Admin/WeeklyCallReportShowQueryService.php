<?php

namespace App\Services\Admin;

use App\Models\Call;
use App\Models\CategoryAnalyticsReport;
use App\Models\ExtensionPerformanceReport;
use App\Models\RingGroupPerformanceReport;
use App\Models\WeeklyCallReport;
use App\Services\AdvancedReportGenerationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WeeklyCallReportShowQueryService
{
    /**
     * A company only ever has one weekly report per period (unique on
     * company_id + reporting period), so company + week start date is a
     * stable, human-readable identifier — used for the /reports/{company}/{week}
     * URL instead of the raw numeric id.
     */
    public function getReportByCompanyAndWeek(int $companyId, string $weekStart): WeeklyCallReport
    {
        return WeeklyCallReport::with(['company:id,name,slug,timezone', 'companyPbxAccount:id,pbx_provider_id,server_id,status'])
            ->where('company_id', $companyId)
            ->whereDate('week_start_date', $weekStart)
            ->firstOrFail();
    }

    public function getCallEndpoints(WeeklyCallReport $report): array
    {
        $timezone = is_string($report->company?->timezone) && $report->company->timezone !== ''
            ? $report->company->timezone
            : 'UTC';

        return Call::query()
            ->where('weekly_call_report_id', $report->id)
            ->where(function ($query) {
                $query->whereNotNull('from')
                    ->orWhereNotNull('to');
            })
            ->orderByDesc('started_at')
            ->limit(100)
            ->get([
                'id',
                'from',
                'to',
                'status',
                'started_at',
                'duration_seconds',
                'pbx_unique_id',
            ])
            ->map(function ($call) use ($timezone) {
                // started_at is stored in UTC; convert to the company's local
                // timezone so this matches the hourly distribution's timezone
                // handling instead of leaking raw UTC to the frontend.
                $startedAt = $call->started_at
                    ? CarbonImmutable::parse($call->started_at, 'UTC')->setTimezone($timezone)->toIso8601String()
                    : null;

                return [
                    'call_id' => (int) $call->id,
                    'from' => $call->from,
                    'to' => $call->to,
                    'status' => $call->status,
                    'started_at' => $startedAt,
                    'duration_seconds' => (int) ($call->duration_seconds ?? 0),
                    'pbx_unique_id' => $call->pbx_unique_id,
                ];
            })
            ->values()
            ->all();
    }

    public function buildCategoryBreakdowns(WeeklyCallReport $report, array $metrics): array
    {
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
            $timezone = is_string($report->company?->timezone) && $report->company->timezone !== ''
                ? $report->company->timezone
                : 'UTC';
            $fallback = $this->buildCategoryBreakdownFromReportCalls((int) $report->id, $timezone);
            if (! empty($fallback['counts'])) {
                $categoryBreakdowns = $fallback;
            }
        }

        $categorizedCalls = 0;
        if (is_array($categoryBreakdowns['counts'] ?? null)) {
            if (array_is_list($categoryBreakdowns['counts'])) {
                foreach ($categoryBreakdowns['counts'] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $categorizedCalls += (int) ($item['call_count'] ?? $item['count'] ?? 0);
                }
            } else {
                foreach ($categoryBreakdowns['counts'] as $count) {
                    $categorizedCalls += (int) $count;
                }
            }
        }

        $categoryBreakdowns['totals'] = [
            'categorized_calls' => $categorizedCalls,
            'report_total_calls' => (int) ($report->total_calls ?? 0),
        ];

        return $categoryBreakdowns;
    }

    public function buildAdvancedViews(WeeklyCallReport $report, array $categoryBreakdowns): array
    {
        $timezone = is_string($report->company?->timezone) && $report->company->timezone !== ''
            ? $report->company->timezone
            : 'UTC';

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

        if ($extensionReports->isEmpty() && $ringGroupReports->isEmpty() && $categoryReports->isEmpty()) {
            try {
                $service = app(AdvancedReportGenerationService::class);
                $service->generateComprehensiveReports(
                    (int) $report->company_id,
                    CarbonImmutable::parse($report->week_start_date),
                    CarbonImmutable::parse($report->week_end_date),
                    (int) $report->id,
                );

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
            } catch (\Throwable $e) {
                Log::warning('Failed lazy generation of advanced views', [
                    'report_id' => $report->id,
                    'company_id' => $report->company_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

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

        $extensionScorecards = $extensionReports->map(function ($row) use ($report, $timezone) {
            $timeline = Call::query()
                ->leftJoin('call_categories', 'call_categories.id', '=', 'calls.category_id')
                ->leftJoin('sub_categories', 'sub_categories.id', '=', 'calls.sub_category_id')
                ->where('calls.weekly_call_report_id', $report->id)
                ->where(function ($query) use ($row) {
                    $query->where('calls.answered_by_extension', $row->extension)
                        ->orWhere('calls.caller_extension', $row->extension)
                        ->orWhere('calls.to', 'like', '%('.$row->extension.')%')
                        ->orWhere('calls.to', $row->extension);
                })
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
                ->map(function ($call) use ($timezone) {
                    $snippet = trim((string) $call->transcript_text);
                    if (mb_strlen($snippet) > 180) {
                        $snippet = mb_substr($snippet, 0, 180).'...';
                    }

                    return [
                        'call_id' => $call->id,
                        'started_at' => $call->started_at
                            ? CarbonImmutable::parse($call->started_at, 'UTC')->setTimezone($timezone)->toIso8601String()
                            : null,
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
                'timeline' => $timeline->map(function ($call) use ($timezone) {
                    return [
                        'call_id' => $call->id,
                        'started_at' => $call->started_at
                            ? CarbonImmutable::parse($call->started_at, 'UTC')->setTimezone($timezone)->toIso8601String()
                            : null,
                        'duration_seconds' => (int) ($call->duration_seconds ?? 0),
                        'category_name' => $call->category_name,
                        'sub_category_name' => $call->sub_category_name,
                    ];
                })->values()->all(),
                'top_automation_candidates' => array_slice($row->top_categories ?? [], 0, 3),
                'examples' => $samples,
                'recommended_actions' => $recommendedActions,
            ];
        })
            ->filter(function (array $card) {
                return count($card['timeline']) > 0
                    || count($card['top_automation_candidates']) > 0
                    || count($card['examples']) > 0
                    || count($card['recommended_actions']) > 0;
            })
            ->take(5)
            ->values()
            ->all();

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

    public function getEmptyAdvancedViews(): array
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

    private function buildCategoryBreakdownFromReportCalls(int $reportId, string $timezone = 'UTC'): array
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
                'calls.started_at',
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
            $hour = $row->started_at
                ? (string) CarbonImmutable::parse($row->started_at, 'UTC')->setTimezone($timezone)->format('G')
                : null;

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
}
