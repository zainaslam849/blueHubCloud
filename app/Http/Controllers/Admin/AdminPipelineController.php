<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AdminTestPipelineJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPipelineController extends Controller
{
    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'range_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'summarize_limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'categorize_limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        // Admin users can run pipelines for any company
        $companyId = (int) $validated['company_id'];

        $rangeDays = (int) ($validated['range_days'] ?? 30);
        $summarizeLimit = (int) ($validated['summarize_limit'] ?? 500);
        $categorizeLimit = (int) ($validated['categorize_limit'] ?? 500);

        AdminTestPipelineJob::dispatch(
            $companyId,
            $rangeDays,
            $summarizeLimit,
            $categorizeLimit,
            'default'
        )->onQueue('default');

        return response()->json([
            'message' => 'Pipeline queued. Ingest, summaries, categories, categorization, and reports will run shortly.',
            'data' => [
                'company_id' => $companyId,
                'range_days' => $rangeDays,
            ],
        ], 202);
    }
}
