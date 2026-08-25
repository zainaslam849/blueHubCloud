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

        // Per-week usage history (drives the "process remaining" smart buttons).
        // Map each week to whether a completed report already exists for it.
        $reportWeeks = WeeklyCallReport::where('company_id', $companyId)
            ->pluck('status', 'week_start_date');

        $weeklyFetches = CompanyWeeklyFetch::where('company_id', $companyId)
            ->orderByDesc('week_start_date')
            ->limit(26)
            ->get();

        // Latest pipeline run per week (drives the "processing" live status on
        // the smart button — a queued/running run means a job is genuinely
        // working in the background right now, not just a stale DB status).
        $weekStarts = $weeklyFetches->pluck('week_start_date')->filter()->map(fn ($d) => $d->toDateString())->values();
        $latestRunByWeek = PipelineRun::where('company_id', $companyId)
            ->whereIn('range_from', $weekStarts)
            ->orderByDesc('id')
            ->get(['id', 'range_from', 'status', 'current_stage'])
            ->groupBy(fn (PipelineRun $r) => $r->range_from->toDateString())
            ->map(fn ($group) => $group->first());

        $weeklyHistory = $weeklyFetches
            ->map(function (CompanyWeeklyFetch $w) use ($reportWeeks, $latestRunByWeek) {
                $weekKey = $w->week_start_date?->toDateString();
                $reportStatus = $weekKey ? ($reportWeeks[$weekKey] ?? null) : null;
                $run = $weekKey ? $latestRunByWeek->get($weekKey) : null;
                $isActive = $run && in_array($run->status, ['queued', 'running'], true);

                return [
                    'id' => $w->id,
                    'week_start_date' => $weekKey,
                    'week_end_date' => $w->week_end_date?->toDateString(),
                    'calls_available' => $w->calls_available,
                    'calls_fetched' => $w->calls_fetched,
                    'calls_blocked' => $w->calls_blocked,
                    'status' => $w->status,
                    'report_available' => $reportStatus === 'completed',
                    'last_attempted_at' => $w->last_attempted_at?->toIso8601String(),
                    'completed_at' => $w->completed_at?->toIso8601String(),
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
