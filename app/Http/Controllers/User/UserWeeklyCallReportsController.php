<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\WeeklyCallReport;
use App\Services\Admin\WeeklyCallReportShowPresenter;
use App\Services\Admin\WeeklyCallReportShowQueryService;
use App\Services\Billing\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class UserWeeklyCallReportsController extends Controller
{
    public function __construct(
        private readonly WeeklyCallReportShowQueryService $showQueryService,
        private readonly WeeklyCallReportShowPresenter $showPresenter,
    ) {}

    public function index(CreditService $creditService): JsonResponse
    {
        $user = Auth::guard('web')->user();

        if (! $user->company_id) {
            return response()->json(['data' => [], 'message' => 'No company assigned to your account yet.']);
        }

        $reports = WeeklyCallReport::where('company_id', $user->company_id)
            ->orderByDesc('week_start_date')
            ->get([
                'id', 'week_start_date', 'week_end_date', 'status',
                'total_calls', 'answered_calls', 'missed_calls',
                'total_call_duration_seconds', 'minutes_consumed',
                'pdf_path', 'pdf_disk', 'csv_path', 'csv_disk',
                'generated_at',
            ])
            ->map(fn ($r) => [
                'id' => $r->id,
                'week' => ($r->week_start_date?->toDateString() ?? '') . ' → ' . ($r->week_end_date?->toDateString() ?? ''),
                'week_start_date' => $r->week_start_date?->toDateString(),
                'week_end_date' => $r->week_end_date?->toDateString(),
                'status' => $r->status,
                'total_calls' => $r->total_calls,
                'answered_calls' => $r->answered_calls,
                'missed_calls' => $r->missed_calls,
                'minutes_consumed' => $r->minutes_consumed,
                'credits_used' => $creditService->costForSeconds((int) ($r->total_call_duration_seconds ?? 0)),
                'generated_at' => $r->generated_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $reports]);
    }

    public function show(int $id): JsonResponse
    {
        $user = Auth::guard('web')->user();

        if (! $user->company_id) {
            return response()->json(['message' => 'No company assigned to your account yet.'], 403);
        }

        $report = $this->showQueryService->getReportOrFail($id);

        if ((int) $report->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $metrics = $report->metrics ?? [];
        $callEndpoints = $this->showQueryService->getCallEndpoints($report);
        $categoryBreakdowns = $this->showQueryService->buildCategoryBreakdowns($report, $metrics);

        try {
            $advancedViews = $this->showQueryService->buildAdvancedViews($report, $categoryBreakdowns);
        } catch (\Throwable $e) {
            Log::warning('Failed to build advanced views for report', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);
            $advancedViews = $this->showQueryService->getEmptyAdvancedViews();
        }

        $data = $this->showPresenter->toDetailData(
            $report,
            $metrics,
            $categoryBreakdowns,
            $callEndpoints,
            $advancedViews,
        );

        return response()->json(['data' => $data]);
    }
}
