<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Jobs\AdminTestPipelineJob;
use App\Models\CompanyWeeklyFetch;
use App\Models\PipelineRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class UserCallLimitController extends Controller
{
    /**
     * Process the remaining (blocked) calls for a specific week.
     * POST /api/v1/weekly-fetches/{id}/process-remaining
     */
    public function processWeek(int $id): JsonResponse
    {
        $user = Auth::guard('web')->user();

        if (! $user->company_id) {
            return response()->json(['message' => 'No company assigned to your account yet.'], 403);
        }

        $week = CompanyWeeklyFetch::query()->findOrFail($id);

        if ((int) $week->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $company = $user->company;

        // Already fully processed — nothing to do.
        if ($week->status === CompanyWeeklyFetch::STATUS_COMPLETE && $week->calls_blocked === 0) {
            return response()->json([
                'message' => "Last week's calls and reports are already available — visit Weekly Call Reports.",
                'status' => 'already_complete',
            ]);
        }

        // Credit gating: the remaining calls can only be processed while the
        // company has credits to pay for them.
        $creditService = app(\App\Services\Billing\CreditService::class);
        $secondsBudget = $creditService->secondsBudget($company);

        if ($secondsBudget <= 0) {
            return response()->json([
                'message' => 'Your company has no credits available. Purchase credits (or enable auto top-up) to process these calls.',
                'status' => 'insufficient_credits',
            ], 422);
        }

        $from = $week->week_start_date->toDateString();
        $to   = $week->week_end_date->toDateString();

        $pipelineRun = PipelineRun::query()->create([
            'company_id'    => $company->id,
            'range_from'    => $from,
            'range_to'      => $to,
            'status'        => 'queued',
            'trigger_type'  => 'user',
            'current_stage' => 'call_discovery',
            'triggered_by_user_id' => $user->id,
            'active_key'    => $company->id . ':' . $from . ':' . $to,
            'started_at'    => now(),
            'metrics'       => ['max_seconds' => $secondsBudget, 'source' => 'user-process-remaining'],
        ]);

        AdminTestPipelineJob::dispatch(
            companyId: $company->id,
            fromDate: $from,
            toDate: $to,
            summarizeLimit: 5000,
            categorizeLimit: 5000,
            pipelineQueue: 'default',
            pipelineRunId: $pipelineRun->id,
            isResume: false,
            maxCalls: null,
            trackCompanyLimit: true,
            maxSeconds: $secondsBudget,
            deductCredits: true,
        )->onQueue('default');

        $week->update(['last_attempted_at' => now()]);

        return response()->json([
            'message' => 'Processing started for the selected week. This may take a few minutes.',
            'status' => 'queued',
        ]);
    }
}
