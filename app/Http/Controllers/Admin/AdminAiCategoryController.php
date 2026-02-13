<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAiCategoriesForCompanyJob;
use App\Services\AiCategoryGenerationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AdminAiCategoryController extends Controller
{
    /**
     * Trigger AI category generation for a company.
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'range' => ['nullable', 'string', 'in:last_30_days,last_60_days,last_90_days'],
        ]);

        $company = $this->resolveAuthenticatedCompany();

        $rangeDays = $this->resolveRangeDays($data['range'] ?? 'last_30_days');

        GenerateAiCategoriesForCompanyJob::dispatch(
            companyId: $company->id,
            rangeDays: $rangeDays
        );

        return response()->json([
            'status' => 'queued',
            'message' => 'AI category generation job queued.',
        ]);
    }

    /**
     * Preview how many summaries will be used for generation.
     */
    public function preview(Request $request, AiCategoryGenerationService $service): JsonResponse
    {
        $data = $request->validate([
            'range' => ['nullable', 'string', 'in:last_30_days,last_60_days,last_90_days'],
        ]);

        $company = $this->resolveAuthenticatedCompany();

        $rangeDays = $this->resolveRangeDays($data['range'] ?? 'last_30_days');

        $start = now()->subDays($rangeDays)->toDateString();
        $end = now()->toDateString();

        $count = $service->getSummaryCount($company->id, [
            'start' => $start,
            'end' => $end,
        ]);

        return response()->json([
            'data' => [
                'range_days' => $rangeDays,
                'summary_count' => $count,
            ],
        ]);
    }

    private function resolveRangeDays(string $range): int
    {
        return match ($range) {
            'last_60_days' => 60,
            'last_90_days' => 90,
            default => 30,
        };
    }
}
