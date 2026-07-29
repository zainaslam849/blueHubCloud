<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\CompanyWeeklyFetch;
use App\Models\WeeklyCallReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class UserDashboardController extends Controller
{
    public function show(): JsonResponse
    {
        $user = Auth::guard('web')->user();

        $companyId = $user->company_id;

        if (! $companyId) {
            return response()->json([
                'company' => null,
                'call_limit' => null,
                'weekly_history' => [],
                'recent_reports' => [],
                'message' => 'Your account is pending company assignment. Contact your administrator.',
            ]);
        }

        $company = $user->company;

        $recentReports = WeeklyCallReport::where('company_id', $companyId)
            ->orderByDesc('week_start_date')
            ->limit(5)
            ->get(['id', 'week_start_date', 'week_end_date', 'status', 'total_calls', 'answered_calls', 'minutes_consumed', 'generated_at'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'week_start_date' => $r->week_start_date?->toDateString(),
                'week_end_date' => $r->week_end_date?->toDateString(),
                'status' => $r->status,
                'total_calls' => $r->total_calls,
                'answered_calls' => $r->answered_calls,
                'minutes_consumed' => $r->minutes_consumed,
                'generated_at' => $r->generated_at?->toIso8601String(),
            ]);

        // Per-week usage history (drives the "process remaining" smart buttons).
        // Map each week to whether a completed report already exists for it.
        $reportWeeks = WeeklyCallReport::where('company_id', $companyId)
            ->pluck('status', 'week_start_date');

        $weeklyHistory = CompanyWeeklyFetch::where('company_id', $companyId)
            ->orderByDesc('week_start_date')
            ->limit(26)
            ->get()
            ->map(function (CompanyWeeklyFetch $w) use ($reportWeeks) {
                $weekKey = $w->week_start_date?->toDateString();
                $reportStatus = $weekKey ? ($reportWeeks[$weekKey] ?? null) : null;

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
                ];
            });

        return response()->json([
            'company' => $company ? [
                'id' => $company->id,
                'name' => $company->name,
                'timezone' => $company->timezone,
                'status' => $company->status,
            ] : null,
            'call_limit' => $company ? [
                'monthly_call_limit'   => $company->monthly_call_limit,
                'call_limit_used'      => (int) $company->call_limit_used,
                'remaining'            => $company->monthly_call_limit === null ? null : $company->call_limit_remaining,
                'expires_at'           => $company->call_limit_expires_at?->toDateString(),
                'period_completed'     => $company->isCallLimitPeriodCompleted(),
            ] : null,
            'weekly_history' => $weeklyHistory,
            'recent_reports' => $recentReports,
        ]);
    }
}
