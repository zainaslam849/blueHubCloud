<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AdminTestPipelineJob;
use App\Models\Company;
use App\Models\CompanyPbxAccount;
use App\Models\CompanyWeeklyFetch;
use App\Models\PipelineRun;
use App\Services\Billing\CreditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AdminPipelineController extends Controller
{
    private const TERMINAL_STATUSES = ['failed', 'completed', 'cancelled'];

    public function run(Request $request, CreditService $creditService): JsonResponse
    {
        Log::info('AdminPipelineController::run - Request received', ['body' => $request->all()]);

        try {
            $validated = $request->validate([
                'company_id' => ['required', 'integer', 'exists:companies,id'],
                // Preferred: an explicit date range (e.g. a specific week) picked by the admin.
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date', 'after_or_equal:from'],
                // Fallback when from/to aren't given: the last N days from today.
                'range_days' => ['nullable', 'integer', 'min:1', 'max:365'],
                'summarize_limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
                'categorize_limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
            ]);
            Log::info('AdminPipelineController::run - Validation passed', ['validated' => $validated]);
        } catch (\Exception $e) {
            Log::error('AdminPipelineController::run - Validation failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        // Admin users can run pipelines for any company
        $companyId = (int) $validated['company_id'];
        $company = Company::query()->findOrFail($companyId);

        $summarizeLimit = (int) ($validated['summarize_limit'] ?? 500);
        $categorizeLimit = (int) ($validated['categorize_limit'] ?? 500);

        $rangeDays = null;
        if (! empty($validated['from']) && ! empty($validated['to'])) {
            $from = CarbonImmutable::parse($validated['from'])->toDateString();
            $to = CarbonImmutable::parse($validated['to'])->toDateString();
        } else {
            $rangeDays = (int) ($validated['range_days'] ?? 30);
            $to = CarbonImmutable::now('UTC')->toDateString();
            $from = CarbonImmutable::now('UTC')->subDays($rangeDays)->toDateString();
        }

        $activeKey = $this->buildActiveKey($companyId, $from, $to);
        $trackingAvailable = Schema::hasTable('pipeline_runs') && Schema::hasTable('pipeline_run_stages');

        Log::info('AdminPipelineController::run - Checking active PBX account', ['company_id' => $companyId]);

        $hasActivePbxAccount = CompanyPbxAccount::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->exists();

        Log::info('AdminPipelineController::run - Active PBX check result', [
            'company_id' => $companyId,
            'has_active' => $hasActivePbxAccount,
        ]);

        if (! $hasActivePbxAccount) {
            Log::warning('AdminPipelineController::run - No active PBX account', ['company_id' => $companyId]);
            return response()->json([
                'message' => 'Selected company has no active PBX account. Please configure and activate a PBX account first.',
            ], 422);
        }

        // Credit-gate this exactly like the automated weekly run: no budget,
        // no fetch. Record it the same way (CompanyWeeklyFetch marked
        // insufficient_credits) so the customer dashboard's "Weekly activity"
        // and "add credits" banner behave identically whether the run was
        // triggered by the scheduler or by an admin.
        $secondsBudget = $creditService->secondsBudget($company);
        if ($secondsBudget <= 0) {
            Log::warning('AdminPipelineController::run - Company has no credits', ['company_id' => $companyId]);

            CompanyWeeklyFetch::query()->updateOrCreate(
                ['company_id' => $companyId, 'week_start_date' => $from],
                [
                    'week_end_date' => $to,
                    'status' => CompanyWeeklyFetch::STATUS_INSUFFICIENT_CREDITS,
                    'last_attempted_at' => now(),
                ],
            );

            return response()->json([
                'message' => "{$company->name} has no credits available. Add credits for this company before running the pipeline.",
                'status' => 'insufficient_credits',
            ], 422);
        }

        $pipelineRun = null;
        if ($trackingAvailable) {
            $existingActiveRun = PipelineRun::query()
                ->where('active_key', $activeKey)
                ->whereNotIn('status', self::TERMINAL_STATUSES)
                ->latest('id')
                ->first();

            if ($existingActiveRun) {
                return response()->json([
                    'message' => 'A pipeline for this company and date range is already running or queued.',
                    'data' => [
                        'pipeline_run_id' => $existingActiveRun->id,
                        'status' => $existingActiveRun->status,
                        'current_stage' => $existingActiveRun->current_stage,
                    ],
                ], 409);
            }

            $pipelineRun = PipelineRun::query()->create([
                'company_id' => $companyId,
                'range_from' => $from,
                'range_to' => $to,
                'status' => 'queued',
                'current_stage' => 'call_discovery',
                'triggered_by_user_id' => auth()->id(),
                'active_key' => $activeKey,
                'started_at' => now(),
                'metrics' => [
                    'range_days' => $rangeDays,
                    'summarize_limit' => $summarizeLimit,
                    'categorize_limit' => $categorizeLimit,
                ],
            ]);
        }

        Log::info('AdminPipelineController::run - Dispatching AdminTestPipelineJob', [
            'company_id' => $companyId,
            'from' => $from,
            'to' => $to,
            'summarize_limit' => $summarizeLimit,
            'categorize_limit' => $categorizeLimit,
            'pipeline_run_id' => $pipelineRun?->id,
            'tracking_available' => $trackingAvailable,
        ]);

        AdminTestPipelineJob::dispatch(
            companyId: $companyId,
            fromDate: $from,
            toDate: $to,
            summarizeLimit: $summarizeLimit,
            categorizeLimit: $categorizeLimit,
            pipelineQueue: 'default',
            pipelineRunId: $pipelineRun?->id,
            isResume: false,
            maxCalls: null,
            trackCompanyLimit: true,
            maxSeconds: $secondsBudget,
            deductCredits: true,
        )->onQueue('default');

        Log::info('AdminPipelineController::run - Job dispatched successfully', ['company_id' => $companyId]);

        return response()->json([
            'message' => 'Pipeline queued. Ingest, summaries, categories, categorization, and reports will run shortly.',
            'data' => [
                'company_id' => $companyId,
                'range_days' => $rangeDays,
                'from' => $from,
                'to' => $to,
                'pipeline_run_id' => $pipelineRun?->id,
                'tracking_available' => $trackingAvailable,
            ],
        ], 202);
    }

    public function resume(int $pipelineRunId, CreditService $creditService): JsonResponse
    {
        $pipelineRun = PipelineRun::query()->findOrFail($pipelineRunId);

        if (! in_array($pipelineRun->status, ['failed', 'queued'], true)) {
            return response()->json([
                'message' => 'Only failed or queued pipelines can be resumed.',
                'data' => [
                    'pipeline_run_id' => $pipelineRun->id,
                    'status' => $pipelineRun->status,
                ],
            ], 422);
        }

        $company = Company::query()->findOrFail($pipelineRun->company_id);
        $secondsBudget = $creditService->secondsBudget($company);
        if ($secondsBudget <= 0) {
            return response()->json([
                'message' => "{$company->name} has no credits available. Add credits before resuming this pipeline.",
                'status' => 'insufficient_credits',
            ], 422);
        }

        $activeKey = $this->buildActiveKey(
            (int) $pipelineRun->company_id,
            $pipelineRun->range_from?->toDateString() ?? '',
            $pipelineRun->range_to?->toDateString() ?? ''
        );

        $hasAnotherActive = PipelineRun::query()
            ->where('active_key', $activeKey)
            ->where('id', '!=', $pipelineRun->id)
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->exists();

        if ($hasAnotherActive) {
            return response()->json([
                'message' => 'Another active pipeline is already running for this company/date range.',
            ], 409);
        }

        $pipelineRun->forceFill([
            'status' => 'queued',
            'last_error' => null,
            'finished_at' => null,
            'resume_count' => (int) $pipelineRun->resume_count + 1,
            'active_key' => $activeKey,
        ])->save();

        $metrics = is_array($pipelineRun->metrics) ? $pipelineRun->metrics : [];

        AdminTestPipelineJob::dispatch(
            companyId: (int) $pipelineRun->company_id,
            fromDate: $pipelineRun->range_from?->toDateString() ?? CarbonImmutable::now('UTC')->subDay()->toDateString(),
            toDate: $pipelineRun->range_to?->toDateString() ?? CarbonImmutable::now('UTC')->toDateString(),
            summarizeLimit: (int) ($metrics['summarize_limit'] ?? 500),
            categorizeLimit: (int) ($metrics['categorize_limit'] ?? 500),
            pipelineQueue: 'default',
            pipelineRunId: $pipelineRun->id,
            isResume: true,
            maxCalls: null,
            trackCompanyLimit: true,
            maxSeconds: $secondsBudget,
            deductCredits: true,
        )->onQueue('default');

        return response()->json([
            'message' => 'Pipeline resume queued.',
            'data' => [
                'pipeline_run_id' => $pipelineRun->id,
                'status' => 'queued',
            ],
        ]);
    }

    private function buildActiveKey(int $companyId, string $from, string $to): string
    {
        return $companyId . ':' . $from . ':' . $to;
    }
}
