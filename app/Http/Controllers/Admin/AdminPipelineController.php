<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AdminTestPipelineJob;
use App\Models\CompanyPbxAccount;
use App\Models\PipelineRun;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AdminPipelineController extends Controller
{
    private const TERMINAL_STATUSES = ['failed', 'completed', 'cancelled'];

    public function run(Request $request): JsonResponse
    {
        Log::info('AdminPipelineController::run - Request received', ['body' => $request->all()]);

        try {
            $validated = $request->validate([
                'company_id' => ['required', 'integer', 'exists:companies,id'],
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

        $rangeDays = (int) ($validated['range_days'] ?? 30);
        $summarizeLimit = (int) ($validated['summarize_limit'] ?? 500);
        $categorizeLimit = (int) ($validated['categorize_limit'] ?? 500);

        $to = CarbonImmutable::now('UTC')->toDateString();
        $from = CarbonImmutable::now('UTC')->subDays($rangeDays)->toDateString();
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
            $companyId,
            $from,
            $to,
            $summarizeLimit,
            $categorizeLimit,
            'default',
            $pipelineRun?->id,
            false
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

    public function resume(int $pipelineRunId): JsonResponse
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
            (int) $pipelineRun->company_id,
            $pipelineRun->range_from?->toDateString() ?? CarbonImmutable::now('UTC')->subDay()->toDateString(),
            $pipelineRun->range_to?->toDateString() ?? CarbonImmutable::now('UTC')->toDateString(),
            (int) ($metrics['summarize_limit'] ?? 500),
            (int) ($metrics['categorize_limit'] ?? 500),
            'default',
            $pipelineRun->id,
            true
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
