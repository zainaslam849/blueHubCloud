<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\BulkOverrideRequest;
use App\Http\Requests\Admin\CallsNeedingReviewRequest;
use App\Http\Requests\Admin\EnforceThresholdRequest;
use App\Http\Requests\Admin\OverrideCategoryRequest;
use App\Models\Call;
use App\Models\CallCategory;
use App\Services\CategoryConfidenceEnforcementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Admin endpoint for manually overriding call categories (STEP 5).
 *
 * Allows administrators to:
 * 1. Review low-confidence categorizations
 * 2. Manually override categories
 * 3. Set category_source = 'manual' for auditing
 * 4. View confidence statistics
 */
class CategoryOverrideController
{
    public function __construct(
        private CategoryConfidenceEnforcementService $enforcementService
    ) {
    }

    /**
     * Get calls needing manual review (low confidence).
     *
     * @queryParam threshold float (default: 0.6) Confidence threshold
     * @queryParam limit int (default: 50) Number of results
     */
    public function getCallsNeedingReview(CallsNeedingReviewRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $threshold = (float) ($validated['threshold'] ?? 0.6);
        $limit = (int) ($validated['limit'] ?? 50);

        $calls = $this->enforcementService->getCallsNeedingReview($threshold, $limit);

        return response()->json([
            'success' => true,
            'threshold' => $threshold,
            'count' => $calls->count(),
            'calls' => $calls,
        ]);
    }

    /**
     * Get category confidence statistics for monitoring.
     *
     * @queryParam company_id int (optional) Filter by company
     */
    public function getConfidenceStats(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id') ? (int) $request->query('company_id') : null;
        $stats = $this->enforcementService->getConfidenceStats($companyId);

        return response()->json([
            'success' => true,
            'stats' => $stats,
        ]);
    }

    /**
     * Manually override a call's category (ADMIN ONLY).
     *
     * This sets category_source = 'manual', bypassing confidence thresholds
     * and preserving the override in future report generations.
     *
     * @bodyParam call_id int required
     * @bodyParam category_id int|null
     * @bodyParam sub_category_id int|null
     * @bodyParam sub_category_label string|null
     */
    public function overrideCallCategory(OverrideCategoryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $updated = $this->enforcementService->manuallyOverride(
            $validated['call_id'],
            $validated['category_id'] ?? null,
            $validated['sub_category_id'] ?? null,
            $validated['sub_category_label'] ?? null,
        );

        if (! $updated) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update call category',
            ], 400);
        }

        // Return updated call with new category info
        $call = Call::with(['category', 'company'])->find($validated['call_id']);

        return response()->json([
            'success' => true,
            'message' => 'Category overridden successfully',
            'call' => [
                'id' => $call->id,
                'from' => $call->from,
                'to' => $call->to,
                'did' => $call->did,
                'started_at' => $call->started_at,
                'category' => $call->category?->name,
                'category_source' => $call->category_source,
                'category_confidence' => $call->category_confidence,
            ],
        ]);
    }

    /**
     * Bulk override categories from a CSV upload or list.
     *
     * @bodyParam overrides array required Array of {call_id, category_id, sub_category_id}
     */
    public function bulkOverride(BulkOverrideRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $results = [
            'total' => count($validated['overrides']),
            'successful' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($validated['overrides'] as $index => $override) {
            try {
                $updated = $this->enforcementService->manuallyOverride(
                    $override['call_id'],
                    $override['category_id'] ?? null,
                    $override['sub_category_id'] ?? null,
                );

                if ($updated) {
                    $results['successful']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = [
                        'index' => $index,
                        'call_id' => $override['call_id'],
                        'message' => 'Update failed',
                    ];
                }
            } catch (\Exception $e) {
                $correlationId = (string) Str::uuid();
                Log::error('CategoryOverrideController::bulkOverride item failed', [
                    'correlation_id' => $correlationId,
                    'index' => $index,
                    'call_id' => $override['call_id'] ?? null,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
                $results['failed']++;
                $results['errors'][] = [
                    'index' => $index,
                    'call_id' => $override['call_id'],
                    'message' => 'Override failed for this call. See server logs.',
                    'correlation_id' => $correlationId,
                ];
            }
        }

        return response()->json([
            'success' => $results['failed'] === 0,
            'results' => $results,
        ], $results['failed'] === 0 ? 200 : 207);
    }

    /**
     * Enforce confidence threshold on all calls.
     *
     * This resets low-confidence (< threshold) categorizations back to NULL,
     * unless they are manually overridden.
     *
     * ADMIN ONLY - potentially destructive operation.
     *
     * @queryParam threshold float (default: 0.6)
     * @queryParam dry_run bool (default: false) Preview without making changes
     */
    public function enforceThreshold(EnforceThresholdRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $threshold = (float) ($validated['threshold'] ?? 0.6);
        $dryRun = $request->boolean('dry_run', false);
        $confirm = $request->boolean('confirm', false);

        if ($dryRun) {
            // Preview: count calls that would be affected
            $wouldBeReset = \Illuminate\Support\Facades\DB::table('calls')
                ->where('category_confidence', '<', $threshold)
                ->where(function ($q) {
                    $q->where('category_source', '!=', 'manual')
                        ->orWhereNull('category_source');
                })
                ->count();

            return response()->json([
                'success' => true,
                'dry_run' => true,
                'threshold' => $threshold,
                'would_be_reset' => $wouldBeReset,
                'message' => "DRY RUN: {$wouldBeReset} calls would be reset if threshold is enforced",
            ]);
        }

        // Destructive operation: require explicit confirm=true to proceed.
        if (! $confirm) {
            return response()->json([
                'success' => false,
                'message' => 'Refusing to enforce threshold without explicit confirmation. Re-issue the request with confirm=true (and consider running dry_run=true first).',
                'threshold' => $threshold,
            ], 422);
        }

        // Actually enforce the threshold
        $reset = $this->enforcementService->enforceThreshold($threshold);

        return response()->json([
            'success' => true,
            'threshold' => $threshold,
            'calls_reset' => $reset,
            'message' => "{$reset} calls were reset due to low confidence",
        ]);
    }
}
