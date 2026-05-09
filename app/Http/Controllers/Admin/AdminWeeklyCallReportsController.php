<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\WeeklyCallReportListPresenter;
use App\Services\Admin\WeeklyCallReportShowPresenter;
use App\Services\Admin\WeeklyCallReportShowQueryService;
use App\Services\WeeklyCallReportQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminWeeklyCallReportsController extends Controller
{
    public function __construct(
        private readonly WeeklyCallReportListPresenter $listPresenter,
        private readonly WeeklyCallReportShowQueryService $showQueryService,
        private readonly WeeklyCallReportShowPresenter $showPresenter,
    ) {}

    public function index(Request $request, WeeklyCallReportQueryService $service): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        // If company_id provided, use service method; otherwise get all reports
        if (! empty($validated['company_id'])) {
            $reports = $service->getByCompanyId((int) $validated['company_id']);
        } else {
            $reports = $service->getAll();
        }

        $mapped = $reports
            ->values()
            ->map(fn ($r) => $this->listPresenter->toIndexRow((array) $r))
            ->values()
            ->all();

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
        $report = $this->showQueryService->getReportOrFail($id);

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
        $callEndpoints = $this->showQueryService->getCallEndpoints($report);
        $categoryBreakdowns = $this->showQueryService->buildCategoryBreakdowns($report, $metrics);

        // Build advanced views with error handling for missing tables
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

        return response()->json([
            'data' => $data,
        ]);
    }
}
