<?php

namespace App\Services;

use App\Models\Call;
use App\Models\CallCategory;
use App\Models\SubCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CallCategorizationPersistenceService
{
    private const CONFIDENCE_THRESHOLD = 0.6;
    /**
     * Persist AI categorization result to a call record.
     *
     * Validation rules:
     * - If category not found by name → fallback to "General"
    * - If confidence < 0.6 → mark as "Other" with sub-category "Unclear"
     * - If sub-category provided but not found → store as sub_category_label
     *
     * @param int $callId
     * @param string $categoryName
     * @param string|null $subCategoryName
     * @param float $confidence
     * @return array { success: bool, call: Call, fallback_used: bool, reason: string|null }
     */
    public static function persistCategorization(
        int $callId,
        string $categoryName,
        ?string $subCategoryName = null,
        float $confidence = 1.0
    ): array {
        DB::beginTransaction();

        try {
            $call = Call::findOrFail($callId);

            // Validation: If confidence < 0.6 → mark as "Other/Unclear"
            if ($confidence < self::CONFIDENCE_THRESHOLD) {
                return self::assignOtherCategory($call, $confidence, 'Low confidence score');
            }

            // Find category by name (only active categories)
            $category = CallCategory::query()
                ->where('is_enabled', true)
                ->where('status', 'active')
                ->where('company_id', $call->company_id)
                ->where('name', $categoryName)
                ->first();

            // Fallback: If category not found → use "General"
            if (!$category) {
                return self::assignGeneralCategory($call, $confidence, "Category '{$categoryName}' not found");
            }

            // Find sub-category by name if provided
            $subCategoryId = null;
            $subCategoryLabel = null;

            if ($subCategoryName) {
                $subCategory = SubCategory::query()
                    ->where('is_enabled', true)
                    ->where('status', 'active')
                    ->where('category_id', $category->id)
                    ->where('name', $subCategoryName)
                    ->first();

                if ($subCategory) {
                    $subCategoryId = $subCategory->id;
                } else {
                    // Store as label if sub-category doesn't exist
                    $subCategoryLabel = $subCategoryName;
                    Log::info("Sub-category '{$subCategoryName}' not found for category '{$categoryName}', storing as label", [
                        'call_id' => $callId,
                        'category_id' => $category->id,
                    ]);
                }
            }

            // Persist categorization
            $call->update([
                'category_id' => $category->id,
                'sub_category_id' => $subCategoryId,
                'sub_category_label' => $subCategoryLabel,
                'category_source' => 'ai',
                'category_confidence' => $confidence,
                'categorized_at' => now(),
            ]);

            DB::commit();

            return [
                'success' => true,
                'call' => $call->fresh(['category', 'subCategory']),
                'fallback_used' => false,
                'reason' => null,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to persist categorization', [
                'call_id' => $callId,
                'category_name' => $categoryName,
                'sub_category_name' => $subCategoryName,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Assign "General" category as fallback.
     */
    private static function assignGeneralCategory(Call $call, float $confidence, string $reason): array
    {
        $generalCategory = CallCategory::query()
            ->where('is_enabled', true)
            ->where('status', 'active')
            ->where('company_id', $call->company_id)
            ->where('name', 'General')
            ->first();

        if (! $generalCategory) {
            $call->update([
                'category_id' => null,
                'sub_category_id' => null,
                'sub_category_label' => null,
                'category_source' => 'ai',
                'category_confidence' => $confidence,
                'categorized_at' => now(),
            ]);

            DB::commit();

            Log::warning('General category not found; skipping fallback assignment', [
                'call_id' => $call->id,
                'reason' => $reason,
                'confidence' => $confidence,
            ]);

            return [
                'success' => true,
                'call' => $call->fresh(['category']),
                'fallback_used' => true,
                'reason' => $reason,
            ];
        }

        $call->update([
            'category_id' => $generalCategory->id,
            'sub_category_id' => null,
            'sub_category_label' => null,
            'category_source' => 'ai',
            'category_confidence' => $confidence,
            'categorized_at' => now(),
        ]);

        DB::commit();

        Log::warning('Used "General" category fallback', [
            'call_id' => $call->id,
            'reason' => $reason,
            'confidence' => $confidence,
        ]);

        return [
            'success' => true,
            'call' => $call->fresh(['category']),
            'fallback_used' => true,
            'reason' => $reason,
        ];
    }

    /**
     * Assign "Other" category for low confidence results.
     */
    private static function assignOtherCategory(Call $call, float $confidence, string $reason): array
    {
        $otherCategory = CallCategory::query()
            ->where('is_enabled', true)
            ->where('status', 'active')
            ->where('company_id', $call->company_id)
            ->where('name', 'Other')
            ->first();

        if (! $otherCategory) {
            $call->update([
                'category_id' => null,
                'sub_category_id' => null,
                'sub_category_label' => null,
                'category_source' => 'ai',
                'category_confidence' => $confidence,
                'categorized_at' => now(),
            ]);

            DB::commit();

            Log::warning('Other category not found; skipping fallback assignment', [
                'call_id' => $call->id,
                'reason' => $reason,
                'confidence' => $confidence,
            ]);

            return [
                'success' => true,
                'call' => $call->fresh(['category']),
                'fallback_used' => true,
                'reason' => $reason,
            ];
        }

        $subCategoryId = null;
        $subCategoryLabel = null;
        $unclearSub = SubCategory::query()
            ->where('is_enabled', true)
            ->where('status', 'active')
            ->where('category_id', $otherCategory->id)
            ->where('name', 'Unclear')
            ->first();

        if ($unclearSub) {
            $subCategoryId = $unclearSub->id;
        } else {
            $subCategoryLabel = 'Unclear';
        }

        $call->update([
            'category_id' => $otherCategory->id,
            'sub_category_id' => $subCategoryId,
            'sub_category_label' => $subCategoryLabel,
            'category_source' => 'ai',
            'category_confidence' => $confidence,
            'categorized_at' => now(),
        ]);

        DB::commit();

        Log::warning('Used "Other" category for low confidence', [
            'call_id' => $call->id,
            'reason' => $reason,
            'confidence' => $confidence,
        ]);

        return [
            'success' => true,
            'call' => $call->fresh(['category']),
            'fallback_used' => true,
            'reason' => $reason,
        ];
    }

    /**
     * Bulk persist categorizations for multiple calls.
     *
     * @param array $categorizations [ ['call_id' => int, 'category' => string, 'sub_category' => string|null, 'confidence' => float], ... ]
     * @return array { success_count: int, failed_count: int, results: array }
     */
    public static function bulkPersist(array $categorizations): array
    {
        $results = [];
        $successCount = 0;
        $failedCount = 0;

        foreach ($categorizations as $item) {
            try {
                $result = self::persistCategorization(
                    $item['call_id'],
                    $item['category'],
                    $item['sub_category'] ?? null,
                    $item['confidence'] ?? 1.0
                );

                $results[] = $result;
                $successCount++;

            } catch (\Exception $e) {
                $results[] = [
                    'success' => false,
                    'call_id' => $item['call_id'],
                    'error' => $e->getMessage(),
                ];
                $failedCount++;
            }
        }

        return [
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'results' => $results,
        ];
    }
}
