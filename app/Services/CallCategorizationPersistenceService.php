<?php

namespace App\Services;

use App\Models\Call;
use App\Models\CallCategory;
use App\Models\SubCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CallCategorizationPersistenceService
{
    private const CONFIDENCE_THRESHOLD = 0.90;
    /**
     * Persist AI categorization result to a call record.
     *
     * Note: Validation of strict 90% confidence is now handled by CallCategorizationPromptService::validateCategorization().
     * This service receives pre-validated categories and persists them to the database.
     *
     * Behavior:
        * - If confidence < 0.90 at this stage, keep call uncategorized for manual retry (defensive measure)
     * - If category provided is valid and exists, use it
     * - If category provided is new, it should already be created by validation layer
     * - If sub-category provided but not found, create it
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

            // Defensive guard: strict mode never persists low-confidence categories.
            if ($confidence < self::CONFIDENCE_THRESHOLD) {
                return self::leaveUncategorized($call, 'Low confidence score (<0.90)');
            }

            // Find category by name (only active categories)
            $category = CallCategory::query()
                ->where('is_enabled', true)
                ->where('status', 'active')
                ->where('company_id', $call->company_id)
                ->where('name', $categoryName)
                ->first();

            // If AI returned a new category name, create it for this company.
            if (! $category) {
                $trimmedCategory = trim($categoryName);
                if ($trimmedCategory === '') {
                    return self::leaveUncategorized($call, 'Empty category name from AI');
                }

                $category = CallCategory::create([
                    'company_id' => $call->company_id,
                    'name' => $trimmedCategory,
                    'description' => 'Auto-created by AI during call categorization',
                    'source' => 'ai',
                    'is_enabled' => true,
                    'status' => 'active',
                    'generated_at' => now(),
                    'generated_by_model' => null,
                ]);

                Log::info('Created missing company category from AI categorization result', [
                    'call_id' => $callId,
                    'company_id' => $call->company_id,
                    'category_name' => $trimmedCategory,
                    'confidence' => $confidence,
                ]);
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
                    $trimmedSubCategory = trim($subCategoryName);
                    if ($trimmedSubCategory !== '') {
                        $createdSubCategory = SubCategory::create([
                            'category_id' => $category->id,
                            'name' => $trimmedSubCategory,
                            'description' => 'Auto-created by AI during call categorization',
                            'is_enabled' => true,
                            'source' => 'ai',
                            'status' => 'active',
                        ]);
                        $subCategoryId = $createdSubCategory->id;

                        Log::info('Created missing sub-category from AI categorization result', [
                            'call_id' => $callId,
                            'company_id' => $call->company_id,
                            'category_id' => $category->id,
                            'sub_category_name' => $trimmedSubCategory,
                            'confidence' => $confidence,
                        ]);
                    } else {
                        // Keep empty/invalid sub-category as null rather than storing noisy labels.
                        $subCategoryLabel = null;
                    }
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
     * Keep call uncategorized when AI did not provide a usable category.
     */
    private static function leaveUncategorized(Call $call, string $reason): array
    {
        $call->update([
            'category_id' => null,
            'sub_category_id' => null,
            'sub_category_label' => null,
            'category_source' => null,
            'category_confidence' => null,
            'categorized_at' => null,
        ]);

        DB::commit();

        Log::warning('Left call uncategorized because AI category output was not usable', [
            'call_id' => $call->id,
            'reason' => $reason,
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
