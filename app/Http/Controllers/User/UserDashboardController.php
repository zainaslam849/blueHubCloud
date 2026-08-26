<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\CompanyWeeklyFetch;
use App\Models\PipelineRun;
use App\Models\WeeklyCallReport;
use App\Services\Billing\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class UserDashboardController extends Controller
{
    public function show(CreditService $creditService): JsonResponse
    {
        $user = Auth::guard('web')->user();

        $companyId = $user->company_id;

        if (! $companyId) {
            return response()->json([
                'company' => null,
                'weekly_history' => [],
                'recent_reports' => [],
                'credit_balance' => 0,
                'top_categories' => [],
                'message' => 'Your account is pending company assignment. Contact your administrator.',
            ]);
        }

        $company = $user->company;

        $recentReports = WeeklyCallReport::where('company_id', $companyId)
            ->orderByDesc('week_start_date')
            ->limit(5)
            ->get(['id', 'week_start_date', 'week_end_date', 'status', 'total_calls', 'answered_calls', 'minutes_consumed', 'generated_at', 'metrics'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'week_start_date' => $r->week_start_date?->toDateString(),
                'week_end_date' => $r->week_end_date?->toDateString(),
                'status' => $r->status,
                'total_calls' => $r->total_calls,
                'answered_calls' => $r->answered_calls,
                'minutes_consumed' => $r->minutes_consumed,
                'generated_at' => $r->generated_at?->toIso8601String(),
                'category_count' => count($r->metrics['category_counts'] ?? []),
            ]);

        // "What callers wanted" — top categories from the most recent
        // completed report. Breakdown keys are "{id}|{name}" (see
        // GenerateWeeklyPbxReportsJob) to keep same-named categories
        // distinct; strip the id here so the API never leaks it.
        $latestCompleted = WeeklyCallReport::where('company_id', $companyId)
            ->where('status', 'completed')
            ->orderByDesc('week_start_date')
            ->first(['metrics']);

        $topCategories = [];
        $categoryCounts = $latestCompleted?->metrics['category_counts'] ?? [];
        if ($categoryCounts !== []) {
            arsort($categoryCounts);
            $total = array_sum($categoryCounts);
            $topCategories = collect($categoryCounts)
                ->take(4)
                ->map(function (int $count, string $key) use ($total) {
                    $sep = strpos($key, '|');
                    $name = $sep === false ? $key : substr($key, $sep + 1);

                    return [
                        'name' => $name,
                        'count' => $count,
                        'percent' => $total > 0 ? round($count / $total * 100, 1) : 0,
                    ];
                })
                ->values()
                ->all();
        }

        // Per-week usage history (drives the "process remaining" smart buttons
        // and the "Weekly activity" dashboard card). Built from the union of
        // company_weekly_fetches (tracked runs: scheduled / user-resume) and
        // weekly_call_reports directly — admin manual/test runs deliberately
        // don't write a company_weekly_fetches row (trackCompanyLimit=false),
        // but the customer should still see that a report exists for that week.
        $reports = WeeklyCallReport::where('company_id', $companyId)
            ->orderByDesc('week_start_date')
            ->limit(26)
            ->get(['id', 'week_start_date', 'week_end_date', 'status', 'total_calls']);
        $reportsByWeek = $reports->keyBy(fn (WeeklyCallReport $r) => $r->week_start_date?->toDateString());

        $weeklyFetches = CompanyWeeklyFetch::where('company_id', $companyId)
            ->orderByDesc('week_start_date')
            ->limit(26)
            ->get();
        $fetchesByWeek = $weeklyFetches->keyBy(fn (CompanyWeeklyFetch $w) => $w->week_start_date?->toDateString());

        // A report-only week (no company_weekly_fetches row) only has something
        // useful to show once its report has actually finished — a still-
        // processing/failed admin run has no real fetch record to act on and
        // nothing viewable yet, so it's left out rather than shown misleadingly.
        $completedReportOnlyWeeks = $reportsByWeek
            ->filter(fn (WeeklyCallReport $r, string $week) => $r->status === 'completed' && ! $fetchesByWeek->has($week))
            ->keys();

        $weekKeys = $fetchesByWeek->keys()->merge($completedReportOnlyWeeks)->unique()->sortDesc()->take(26)->values();

        // Latest pipeline run per week (drives the "processing" live status on
        // the smart button — a queued/running run means a job is genuinely
        // working in the background right now, not just a stale DB status).
        $latestRunByWeek = PipelineRun::where('company_id', $companyId)
            ->whereIn('range_from', $weekKeys)
            ->orderByDesc('id')
            ->get(['id', 'range_from', 'status', 'current_stage'])
            ->groupBy(fn (PipelineRun $r) => $r->range_from->toDateString())
            ->map(fn ($group) => $group->first());

        $weeklyHistory = $weekKeys
            ->map(function (string $weekKey) use ($fetchesByWeek, $reportsByWeek, $latestRunByWeek) {
                $w = $fetchesByWeek->get($weekKey);
                $report = $reportsByWeek->get($weekKey);
                $run = $latestRunByWeek->get($weekKey);
                $isActive = $run && in_array($run->status, ['queued', 'running'], true);

                // Report-only weeks (admin manual/test run — no company_weekly_fetches
                // row) have no real fetch record for the "process remaining" action to
                // target, so they're always shown as a completed, non-actionable row
                // regardless of the underlying report's own status — this card is a
                // visibility view for the customer, not a control for admin-run weeks.
                $isTracked = $w !== null;

                return [
                    'id' => $w?->id ?? (-1 * $report?->id),
                    'week_start_date' => $weekKey,
                    'week_end_date' => $w?->week_end_date?->toDateString() ?? $report?->week_end_date?->toDateString(),
                    'calls_available' => $w?->calls_available ?? $report?->total_calls ?? 0,
                    'calls_fetched' => $w?->calls_fetched ?? $report?->total_calls ?? 0,
                    'calls_blocked' => $isTracked ? ($w->calls_blocked ?? 0) : 0,
                    'status' => $isTracked ? $w->status : 'complete',
                    'report_available' => $isTracked ? ($report?->status === 'completed') : true,
                    'last_attempted_at' => $w?->last_attempted_at?->toIso8601String(),
                    'completed_at' => $w?->completed_at?->toIso8601String(),
                    'pipeline_status' => $isActive ? $run->status : null,
                    'pipeline_stage' => $isActive ? $run->current_stage : null,
                ];
            });

        return response()->json([
            'company' => $company ? [
                'id' => $company->id,
                'name' => $company->name,
                'timezone' => $company->timezone,
                'status' => $company->status,
            ] : null,
            'weekly_history' => $weeklyHistory,
            'recent_reports' => $recentReports,
            'credit_balance' => $company ? $creditService->availableCredits($company) : 0,
            'top_categories' => $topCategories,
        ]);
    }
}
