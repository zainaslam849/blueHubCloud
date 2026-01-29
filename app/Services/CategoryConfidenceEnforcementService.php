<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Enforce category confidence thresholds and source tracking (STEP 5).
 *
 * Rules:
 * - If category_confidence < 0.6 → category_id = NULL, sub_category_id = NULL
 * - category_source tracks origin: 'rule' | 'ai' | 'manual'
 * - Manual overrides bypass confidence checks
 *
 * This ensures only high-confidence AI categorization is used in reports.
 */
class CategoryConfidenceEnforcementService
{
    /**
     * Apply confidence threshold to calls.
     *
     * Resets low-confidence categorizations to NULL:
     * - If confidence < 0.6 and source != 'manual' → clear category
     *
     * This is called after categorization (CategorizeSingleCallJob) to clean up
     * low-confidence results before they appear in reports.
     *
     * @param  float  $confidenceThreshold  (default: 0.6 = 60%)
     * @return int  Number of calls reset
     */
    public function enforceThreshold(float $confidenceThreshold = 0.6): int
    {
        $reset = DB::table('calls')
            ->where('category_confidence', '<', $confidenceThreshold)
            ->where(function ($q) {
                // Only reset if NOT manually assigned
                $q->where('category_source', '!=', 'manual')
                    ->orWhereNull('category_source');
            })
            ->update([
                'category_id' => null,
                'sub_category_id' => null,
                'category_confidence' => null,
                'category_source' => null,
                'categorized_at' => null,
            ]);

        Log::info('Applied category confidence threshold', [
            'threshold' => $confidenceThreshold,
            'calls_reset' => $reset,
        ]);

        return $reset;
    }

    /**
     * Manually override a call's category.
     *
     * Sets category_source = 'manual' so it bypasses future confidence checks.
     *
     * @param  int  $callId
     * @param  int|null  $categoryId
     * @param  int|null  $subCategoryId
     * @param  string|null  $subCategoryLabel
     * @return bool
     */
    public function manuallyOverride(
        int $callId,
        ?int $categoryId = null,
        ?int $subCategoryId = null,
        ?string $subCategoryLabel = null
    ): bool {
        try {
            $updated = DB::table('calls')
                ->where('id', $callId)
                ->update([
                    'category_id' => $categoryId,
                    'sub_category_id' => $subCategoryId,
                    'sub_category_label' => $subCategoryLabel,
                    'category_source' => 'manual',
                    'category_confidence' => 1.0, // Manual = 100% confidence
                    'categorized_at' => now(),
                ]);

            Log::info('Manually overrode call category', [
                'call_id' => $callId,
                'category_id' => $categoryId,
                'sub_category_id' => $subCategoryId,
                'updated' => $updated > 0,
            ]);

            return $updated > 0;
        } catch (\Exception $e) {
            Log::error('Failed to manually override category', [
                'call_id' => $callId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get confidence statistics for monitoring.
     *
     * Returns breakdown of:
     * - High confidence (>= 0.8)
     * - Medium confidence (0.6-0.79)
     * - Low confidence (< 0.6)
     * - No confidence (null)
     * - Manual overrides
     *
     * @param  int|null  $companyId  (optional, filter by company)
     * @return array<string, int>
     */
    public function getConfidenceStats(?int $companyId = null): array
    {
        $query = DB::table('calls');

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        $total = $query->count();

        $stats = [
            'total' => $total,
            'high_confidence' => DB::table('calls')
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->where('category_confidence', '>=', 0.8)
                ->count(),
            'medium_confidence' => DB::table('calls')
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->whereBetween('category_confidence', [0.6, 0.79])
                ->count(),
            'low_confidence' => DB::table('calls')
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->where('category_confidence', '<', 0.6)
                ->whereNotNull('category_confidence')
                ->count(),
            'uncategorized' => DB::table('calls')
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->whereNull('category_id')
                ->count(),
            'manual_overrides' => DB::table('calls')
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->where('category_source', 'manual')
                ->count(),
        ];

        return $stats;
    }

    /**
     * Get calls that need review (low confidence or uncertain).
     *
     * @param  float  $threshold  Default: 0.6
     * @param  int|null  $limit
     * @return \Illuminate\Support\Collection
     */
    public function getCallsNeedingReview(float $threshold = 0.6, ?int $limit = 100)
    {
        return DB::table('calls')
            ->join('call_categories', 'calls.category_id', '=', 'call_categories.id')
            ->select([
                'calls.id',
                'calls.from',
                'calls.to',
                'calls.did',
                'calls.started_at',
                'calls.category_confidence',
                'calls.category_source',
                'call_categories.name as category_name',
                'calls.transcript_text',
            ])
            ->where(function ($q) use ($threshold) {
                $q->where('category_confidence', '<', $threshold)
                    ->where('category_source', '!=', 'manual');
            })
            ->orderByDesc('calls.category_confidence')
            ->limit($limit ?? 100)
            ->get();
    }
}
