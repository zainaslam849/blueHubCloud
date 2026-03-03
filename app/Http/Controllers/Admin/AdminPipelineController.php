<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AdminTestPipelineJob;
use App\Models\CompanyPbxAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminPipelineController extends Controller
{
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

        Log::info('AdminPipelineController::run - Dispatching AdminTestPipelineJob', [
            'company_id' => $companyId,
            'range_days' => $rangeDays,
            'summarize_limit' => $summarizeLimit,
            'categorize_limit' => $categorizeLimit,
        ]);

        AdminTestPipelineJob::dispatch(
            $companyId,
            $rangeDays,
            $summarizeLimit,
            $categorizeLimit,
            'default'
        )->onQueue('default');

        Log::info('AdminPipelineController::run - Job dispatched successfully', ['company_id' => $companyId]);

        return response()->json([
            'message' => 'Pipeline queued. Ingest, summaries, categories, categorization, and reports will run shortly.',
            'data' => [
                'company_id' => $companyId,
                'range_days' => $rangeDays,
            ],
        ], 202);
    }
}
