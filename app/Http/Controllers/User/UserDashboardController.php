<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\CompanyMinuteBalance;
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
                'minute_balance' => null,
                'recent_reports' => [],
                'message' => 'Your account is pending company assignment. Contact your administrator.',
            ]);
        }

        $balance = CompanyMinuteBalance::with('plan')
            ->where('company_id', $companyId)
            ->first();

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

        $company = $user->company;

        return response()->json([
            'company' => $company ? [
                'id' => $company->id,
                'name' => $company->name,
                'timezone' => $company->timezone,
                'status' => $company->status,
            ] : null,
            'minute_balance' => $balance ? [
                'plan_name' => $balance->plan?->name,
                'purchased_minutes' => $balance->purchased_minutes,
                'used_minutes' => $balance->used_minutes,
                'available_minutes' => $balance->available_minutes,
            ] : null,
            'recent_reports' => $recentReports,
        ]);
    }
}
