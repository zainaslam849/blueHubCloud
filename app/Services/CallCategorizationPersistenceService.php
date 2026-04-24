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

            // Find category by name (case-insensitive, with normalized fallback to collapse
            // punctuation/word-order/plural variants like "Sales & Marketing" vs "Sales and Marketing"
            // or "Website Design and Development" vs "Website Development").
            $category = self::findExistingCategoryForCompany($call->company_id, $categoryName);

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
                $subCategory = self::findExistingSubCategoryForCategory($category->id, $subCategoryName);

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

    /**
     * Find an existing active category for the company that semantically matches the given name.
     *
     * Tries (in order):
     *   1. Exact case-insensitive match on trimmed name.
     *   2. Normalized match (lower-cased, punctuation stripped, plurals collapsed,
     *      stop-words removed, words sorted) so variants like "Sales & Marketing" and
     *      "Sales and Marketing", or "Website Design and Development" and "Website
     *      Development", resolve to the same row instead of creating a duplicate.
     *
     * Returns null only when no semantically-equivalent active category exists.
     */
    private static function findExistingCategoryForCompany(int $companyId, string $name): ?CallCategory
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return null;
        }

        // 1) Case-insensitive exact match (works under utf8mb4_unicode_ci collation).
        $exact = CallCategory::query()
            ->where('is_enabled', true)
            ->where('status', 'active')
            ->where('company_id', $companyId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($trimmed)])
            ->first();

        if ($exact) {
            return $exact;
        }

        // 2) Normalized match across all active categories for this company.
        $targetKey = self::normalizeForMatch($trimmed);
        if ($targetKey === '') {
            return null;
        }

        return CallCategory::query()
            ->where('is_enabled', true)
            ->where('status', 'active')
            ->where('company_id', $companyId)
            ->get()
            ->first(fn (CallCategory $c) => self::normalizeForMatch((string) $c->name) === $targetKey);
    }

    /**
     * Find an existing active sub-category under the given category whose name
     * semantically matches the given name (same normalization rules as categories).
     */
    private static function findExistingSubCategoryForCategory(int $categoryId, string $name): ?SubCategory
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return null;
        }

        $exact = SubCategory::query()
            ->where('is_enabled', true)
            ->where('status', 'active')
            ->where('category_id', $categoryId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($trimmed)])
            ->first();

        if ($exact) {
            return $exact;
        }

        $targetKey = self::normalizeForMatch($trimmed);
        if ($targetKey === '') {
            return null;
        }

        return SubCategory::query()
            ->where('is_enabled', true)
            ->where('status', 'active')
            ->where('category_id', $categoryId)
            ->get()
            ->first(fn (SubCategory $s) => self::normalizeForMatch((string) $s->name) === $targetKey);
    }

    /**
     * Build a canonical "fingerprint" of a category/sub-category name so that
     * semantically-equivalent variants collapse to the same key.
     *
     * Steps:
     *   - lower-case
     *   - replace common synonyms ("&" -> "and", "/" -> " ")
     *   - strip non-alphanumeric chars
     *   - tokenize, drop stop-words ("and", "the", "of", "for", "a", "an",
     *     "to", "with", "inquiry", "enquiry", "question", "discussion",
     *     "request", "call", "calls", "issue", "issues", "general")
     *   - collapse common plurals (trailing 's' on >3-char tokens)
     *   - sort tokens alphabetically (so word-order doesn't matter)
     */
    private static function normalizeForMatch(string $value): string
    {
        $v = mb_strtolower(trim($value));
        if ($v === '') {
            return '';
        }

        $v = str_replace(['&', '/', '-', '_', ',', '.', '+'], [' and ', ' ', ' ', ' ', ' ', ' ', ' '], $v);
        $v = preg_replace('/[^a-z0-9 ]+/', ' ', $v) ?? '';
        $v = preg_replace('/\s+/', ' ', $v) ?? '';
        $v = trim($v);
        if ($v === '') {
            return '';
        }

        $stop = [
            'and', 'the', 'of', 'for', 'a', 'an', 'to', 'with', 'on', 'in', 'or',
            'inquiry', 'inquiries', 'enquiry', 'enquiries', 'question', 'questions',
            'discussion', 'discussions', 'request', 'requests', 'call', 'calls',
            'issue', 'issues', 'general',
        ];

        $tokens = array_values(array_filter(
            explode(' ', $v),
            fn (string $t) => $t !== '' && ! in_array($t, $stop, true)
        ));

        // Collapse trivial plurals on tokens longer than 3 chars (orders -> order, services -> service).
        $tokens = array_map(function (string $t) {
            if (mb_strlen($t) > 3 && str_ends_with($t, 'ies')) {
                return mb_substr($t, 0, -3) . 'y';
            }
            if (mb_strlen($t) > 3 && str_ends_with($t, 's') && ! str_ends_with($t, 'ss')) {
                return mb_substr($t, 0, -1);
            }
            return $t;
        }, $tokens);

        // De-duplicate and sort so word-order doesn't matter.
        $tokens = array_values(array_unique($tokens));
        sort($tokens);

        return implode(' ', $tokens);
    }
}
