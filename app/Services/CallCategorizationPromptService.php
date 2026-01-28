<?php

namespace App\Services;

use App\Models\CallCategory;

class CallCategorizationPromptService
{
    /**
     * System prompt for AI categorization
     */
    public static function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a phone call classification engine.

Your task is to assign the call to ONE category chosen from a predefined list.
These categories are managed by the system administrator and MUST be followed strictly.

You MUST NOT invent new primary categories.
If intent is unclear, choose the closest matching category or "General".

Return valid JSON only.
PROMPT;
    }

    /**
     * Build dynamic user prompt with enabled categories and sub-categories
     */
    public static function buildUserPrompt(
        string $transcriptText,
        string $direction = 'inbound',
        string $status = 'completed',
        int $duration = 0,
        bool $isAfterHours = false
    ): string
    {
        // Fetch enabled categories with their enabled sub-categories
        $categories = CallCategory::enabled()
            ->with(['subCategories' => function ($query) {
                $query->enabled();
            }])
            ->get();

        // Build categories section
        $categoriesSection = self::buildCategoriesSection($categories);

        // Format duration
        $durationStr = self::formatDuration($duration);

        return <<<PROMPT
AVAILABLE CATEGORIES:
{$categoriesSection}

CALL CONTEXT:
- Direction: {$direction}
- Status: {$status}
- Duration: {$durationStr}
- After hours: {self::boolToYesNo($isAfterHours)}

TRANSCRIPT:
"""
{$transcriptText}
"""

RULES:
1. If status is "missed" → choose the category that represents missed calls.
2. If after hours → choose the category that best fits after-hours handling.
3. Choose ONLY from the available categories above.
4. If no sub-category fits, return null.

OUTPUT FORMAT (JSON ONLY):
{
  "category": "<exact category name>",
  "sub_category": "<exact sub-category name or null>",
  "confidence": 0.0-1.0
}
PROMPT;
    }

    /**
     * Build the categories section with sub-categories
     */
    private static function buildCategoriesSection($categories): string
    {
        if ($categories->isEmpty()) {
            return '- General';
        }

        $lines = [];

        foreach ($categories as $category) {
            $lines[] = "- {$category->name}";

            if ($category->subCategories->isNotEmpty()) {
                foreach ($category->subCategories as $subCategory) {
                    $lines[] = "  - {$subCategory->name}";
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Format duration in seconds to readable format
     */
    private static function formatDuration(int $seconds): string
    {
        if ($seconds === 0) {
            return '0 seconds';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }
        if ($secs > 0 || empty($parts)) {
            $parts[] = "{$secs}s";
        }

        return implode(' ', $parts);
    }

    /**
     * Convert boolean to Yes/No
     */
    private static function boolToYesNo(bool $value): string
    {
        return $value ? 'Yes' : 'No';
    }

    /**
     * Get model parameters for AI API call
     */
    public static function getModelParameters(): array
    {
        return [
            'temperature' => 0.1,
            'max_tokens' => 150,
        ];
    }

    /**
     * Build complete prompt object for API consumption
     */
    public static function buildPromptObject(
        string $transcriptText,
        string $direction = 'inbound',
        string $status = 'completed',
        int $duration = 0,
        bool $isAfterHours = false
    ): array
    {
        return [
            'system' => self::getSystemPrompt(),
            'user' => self::buildUserPrompt(
                $transcriptText,
                $direction,
                $status,
                $duration,
                $isAfterHours
            ),
            'model_parameters' => self::getModelParameters(),
        ];
    }

    /**
     * Parse AI response and validate categorization
     */
    public static function validateCategorization(array $aiResponse): array
    {
        // Ensure required fields
        if (!isset($aiResponse['category'])) {
            return [
                'valid' => false,
                'error' => 'Missing category field',
            ];
        }

        $categoryName = $aiResponse['category'] ?? null;
        $subCategoryName = $aiResponse['sub_category'] ?? null;
        $confidence = $aiResponse['confidence'] ?? 0;

        // Verify category exists and is enabled
        $category = CallCategory::enabled()
            ->where('name', $categoryName)
            ->first();

        if (!$category) {
            return [
                'valid' => false,
                'error' => "Category '{$categoryName}' not found or disabled",
            ];
        }

        // Verify sub-category if provided
        if ($subCategoryName !== null) {
            $subCategory = $category->subCategories()
                ->enabled()
                ->where('name', $subCategoryName)
                ->first();

            if (!$subCategory) {
                return [
                    'valid' => false,
                    'error' => "Sub-category '{$subCategoryName}' not found or disabled",
                ];
            }

            return [
                'valid' => true,
                'category_id' => $category->id,
                'sub_category_id' => $subCategory->id,
                'confidence' => $confidence,
            ];
        }

        return [
            'valid' => true,
            'category_id' => $category->id,
            'sub_category_id' => null,
            'confidence' => $confidence,
        ];
    }
}
