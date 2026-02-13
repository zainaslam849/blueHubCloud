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
            'company_id' => ['nullable', 'integer', 'min:1'],
            'range_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'summarize_limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'categorize_limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        $company = $this->resolveAuthenticatedCompany();

        if (isset($validated['company_id']) && (int) $validated['company_id'] !== $company->id) {
            abort(403, 'Company mismatch.');
        }

        $rangeDays = (int) ($validated['range_days'] ?? 30);
        $summarizeLimit = (int) ($validated['summarize_limit'] ?? 500);
        $categorizeLimit = (int) ($validated['categorize_limit'] ?? 500);

        AdminTestPipelineJob::dispatch(
            (int) $company->id,
            $rangeDays,
            $summarizeLimit,
            $categorizeLimit,
            'default'
        )->onQueue('default');

        return response()->json([
            'message' => 'Pipeline queued. Ingest, summaries, categories, categorization, and reports will run shortly.',
            'data' => [
                'company_id' => (int) $company->id,
                'range_days' => $rangeDays,
            ],
        ], 202);
    }
}
