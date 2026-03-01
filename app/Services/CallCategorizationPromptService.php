<?php

namespace App\Services;

use App\Models\CallCategory;
use App\Repositories\AiSettingsRepository;

class CallCategorizationPromptService
{
    private const CONFIDENCE_THRESHOLD = 0.6;

    /**
     * System prompt for AI categorization
     */
    public static function getSystemPrompt(): string
    {
        $override = null;

        try {
            $settings = app(AiSettingsRepository::class)->getActive();
            if ($settings && is_string($settings->categorization_system_prompt)) {
                $override = trim($settings->categorization_system_prompt);
            }
        } catch (\Throwable $e) {
            $override = null;
        }

        if (! empty($override)) {
            return $override;
        }

        return <<<'PROMPT'
You are an intelligent phone call classification engine for a multi-tenant business system serving 200+ companies across diverse industries (telecommunications, real estate, hospitality, web design, healthcare, retail, professional services, manufacturing, legal, etc.).

Your task is to accurately categorize each call based on its content and context.

CATEGORIZATION STRATEGY:
1. MATCH EXISTING CATEGORIES FIRST: Review the provided category list carefully. If the call fits an existing category, use it. This prevents duplicate categories.

2. CREATE NEW CATEGORIES WHEN NEEDED: If the call topic genuinely doesn't fit ANY existing category, you may suggest a NEW category that accurately represents the call's purpose. New categories should be:
   - Clearly distinct from existing ones
   - Industry-appropriate for this company
   - Broad enough to apply to multiple calls
   - Named clearly (e.g., "Technical Support", "Billing Inquiry", "Sales Lead")

3. SPECIAL CASES:
   - GREETING-ONLY/NO RESPONSE: If transcript shows only "Hello [company], how can I help you?" with NO customer response or dialogue → category: "No Response" or "Missed Call" (whichever exists, or create "No Response")
   - ABANDONED CALLS: Caller hung up immediately with no conversation → "Missed Call" or "Abandoned"
   - AFTER HOURS: Calls outside business hours → "After Hours" (if category exists)
   - VOICEMAIL: Only voicemail left → "Voicemail" (if category exists)

4. CONFIDENCE SCORING:
   - High confidence (0.8-1.0): Clear topic, obvious category
   - Medium confidence (0.6-0.79): Reasonable match but ambiguous
   - Low confidence (<0.6): Very unclear → use "Other" or "General"

5. INDUSTRY AWARENESS: Consider the company's industry context:
   - Telecom companies: Technical support, billing, service inquiries, sales
   - Real estate: Property inquiries, viewings, leasing, maintenance
   - Hospitality: Reservations, guest services, complaints
   - Retail: Orders, returns, product questions
   - Professional services: Appointments, consultations, billing

RULES:
- Choose ONE primary category only
- Sub-category is optional (can be null)
- Be consistent: similar calls should get the same category
- Prioritize existing categories to maintain organization
- Only create new categories when truly necessary

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
        bool $isAfterHours = false,
        ?int $companyId = null
    ): string
    {
        if (! $companyId) {
            throw new \InvalidArgumentException('Company ID is required for categorization prompt.');
        }

        $threshold = self::CONFIDENCE_THRESHOLD;

        // Fetch active categories with their active sub-categories
        $categories = self::getActiveCategories($companyId);

        // Build categories section
        $categoriesSection = self::buildCategoriesSection($categories);

        // Format duration
        $durationStr = self::formatDuration($duration);

        return <<<PROMPT
AVAILABLE CATEGORIES FOR THIS COMPANY:
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

ANALYSIS INSTRUCTIONS:
1. READ THE TRANSCRIPT CAREFULLY: Understand what was discussed or if there was any real conversation.

2. CHECK FOR SPECIAL CASES FIRST:
   - Greeting-only with no response? (e.g., only "Hello, how can I help you?") → Use "No Response" or "Missed Call"
   - Abandoned/hung up immediately? → Use "Missed Call" or "Abandoned"
   - Only voicemail left? → Use "Voicemail"
   - Status is "missed"? → Use category for missed calls

3. FOR ACTUAL CONVERSATIONS:
   - Match to existing categories above whenever possible
   - If no existing category fits well, create a NEW appropriate category name
   - Choose relevant sub-category if available, otherwise null

4. CONFIDENCE SCORING:
   - 0.8-1.0: Very clear topic and category match
   - 0.6-0.79: Reasonable match but some ambiguity
   - Below {$threshold}: Very unclear → use "Other" or "General"

5. CONSISTENCY: Similar calls should receive the same category name.

OUTPUT FORMAT (JSON ONLY, NO EXPLANATION):
{
  "category": "<exact category name from list above, or new category name>",
  "sub_category": "<exact sub-category name from list above, or null>",
  "confidence": 0.85
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
        bool $isAfterHours = false,
        ?int $companyId = null
    ): array
    {
        return [
            'system' => self::getSystemPrompt(),
            'user' => self::buildUserPrompt(
                $transcriptText,
                $direction,
                $status,
                $duration,
                $isAfterHours,
                $companyId
            ),
            'model_parameters' => self::getModelParameters(),
        ];
    }

    /**
     * Parse AI response and validate categorization
     */
    public static function validateCategorization(array $aiResponse, ?int $companyId = null): array
    {
        if (! $companyId) {
            throw new \InvalidArgumentException('Company ID is required for categorization validation.');
        }

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

        if ((float) $confidence < self::CONFIDENCE_THRESHOLD) {
            $categoryName = 'Other';
            $subCategoryName = 'Unclear';
        }

        // Verify category exists and is active, or create it if AI suggested a new one
        $category = CallCategory::query()
            ->where('is_enabled', true)
            ->where('status', 'active')
            ->where('company_id', $companyId)
            ->where('name', $categoryName)
            ->first();

        if (!$category) {
            // AI suggested a new category - create it automatically
            \Illuminate\Support\Facades\Log::info("Creating new AI-suggested category", [
                'company_id' => $companyId,
                'category_name' => $categoryName,
                'ai_confidence' => $confidence,
            ]);

            $category = CallCategory::create([
                'company_id' => $companyId,
                'name' => $categoryName,
                'description' => 'Auto-created by AI based on call analysis',
                'source' => 'ai',
                'is_enabled' => true,
                'status' => 'active',
            ]);
        }

        // Verify sub-category if provided
        if ($subCategoryName !== null) {
            $subCategory = $category->subCategories()
                ->where('is_enabled', true)
                ->where('status', 'active')
                ->where('name', $subCategoryName)
                ->first();

            if (!$subCategory) {
                // AI suggested a new sub-category - create it automatically
                \Illuminate\Support\Facades\Log::info("Creating new AI-suggested sub-category", [
                    'company_id' => $companyId,
                    'category_id' => $category->id,
                    'category_name' => $category->name,
                    'sub_category_name' => $subCategoryName,
                    'ai_confidence' => $confidence,
                ]);

                $subCategory = \App\Models\SubCategory::create([
                    'call_category_id' => $category->id,
                    'name' => $subCategoryName,
                    'description' => 'Auto-created by AI based on call analysis',
                    'is_enabled' => true,
                    'status' => 'active',
                ]);
            }

            return [
                'valid' => true,
                'category_id' => $category->id,
                'sub_category_id' => $subCategory->id,
                'category_name' => $category->name,
                'sub_category_name' => $subCategory->name,
                'confidence' => $confidence,
            ];
        }

        return [
            'valid' => true,
            'category_id' => $category->id,
            'sub_category_id' => null,
            'category_name' => $category->name,
            'sub_category_name' => null,
            'confidence' => $confidence,
        ];
    }

    /**
     * Fetch active categories and their active sub-categories.
     */
    private static function getActiveCategories(?int $companyId = null)
    {
        if (! $companyId) {
            throw new \InvalidArgumentException('Company ID is required to fetch categories.');
        }

        return CallCategory::query()
            ->where('is_enabled', true)
            ->where('status', 'active')
            ->where('company_id', $companyId)
            ->with(['subCategories' => function ($query) {
                $query->where('is_enabled', true)
                    ->where('status', 'active');
            }])
            ->get();
    }
}
