<?php

namespace App\Services;

use App\Models\CallCategory;
use App\Repositories\AiSettingsRepository;

class CallCategorizationPromptService
{
    private const CONFIDENCE_THRESHOLD = 0.90;
    private const SUBCATEGORY_REFINED_THRESHOLD = 0.75;
    private const GENERAL_ALLOWED_MAX_WORDS = 24;
    private const NO_RESPONSE_MAX_WORDS = 80;

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
            return $override . "\n\n" . <<<'PROMPT'
NON-NEGOTIABLE SAFETY RULES:
- Do NOT default to "General" for substantive conversations with clear intent.
- If the available categories are too generic, you MAY propose a specific new category.
- Only output confidence >= 0.90 for final category decisions.
- Return JSON only with keys: category, sub_category, confidence.
PROMPT;
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
    - Low confidence (<0.6): Very unclear → use "Other"

6. IMPORTANT RULE FOR "GENERAL":
    - Do NOT use "General" for real conversations with clear business intent.
    - Use "General" only for very short/unclear interactions where there is no clear topic.

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
        ?string $summaryText = null,
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
        $onlyGeneralCategory = self::hasOnlyGeneralCategory($categories);

        // Build categories section
        $categoriesSection = self::buildCategoriesSection($categories);
        $afterHours = self::boolToYesNo($isAfterHours);
        $categoryListQualityInstruction = $onlyGeneralCategory
            ? '7. CATEGORY LIST QUALITY: The current list only contains "General". For substantive calls, you MUST propose a specific new category and must not return "General" unless it is a greeting-only/no-response/unclear interaction.'
            : '7. CATEGORY LIST QUALITY: Use existing categories first, and only propose a new category when no existing category fits.';

        // Format duration
        $durationStr = self::formatDuration($duration);

          $summarySection = trim((string) $summaryText) !== ''
                ? trim((string) $summaryText)
                : 'No AI summary available for this call. Use transcript intent only.';

          return <<<PROMPT
AVAILABLE CATEGORIES FOR THIS COMPANY:
{$categoriesSection}

CALL CONTEXT:
- Direction: {$direction}
- Status: {$status}
- Duration: {$durationStr}
- After hours: {$afterHours}

AI SUMMARY (PRIMARY SIGNAL):
"""
{$summarySection}
"""

TRANSCRIPT:
"""
{$transcriptText}
"""

ANALYSIS INSTRUCTIONS:
1. SUMMARY-FIRST CLASSIFICATION:
    - Use AI SUMMARY as the primary intent signal when it is present
    - Use transcript details to verify and disambiguate
    - If summary and transcript disagree, prioritize the transcript evidence

2. READ THE TRANSCRIPT CAREFULLY: Understand what was discussed or if there was any real conversation.

3. CHECK FOR SPECIAL CASES FIRST:
   - Greeting-only with no response? (e.g., only "Hello, how can I help you?") → Use "No Response" or "Missed Call"
   - Abandoned/hung up immediately? → Use "Missed Call" or "Abandoned"
   - Only voicemail left? → Use "Voicemail"
   - Status is "missed"? → Use category for missed calls

4. FOR ACTUAL CONVERSATIONS (STRICT MATCHING):
   - ONLY assign an existing category if you are >= 90% confident the transcript matches that category's intent/topic
   - If NO existing category reaches 90% confidence, create a NEW appropriate category name instead
   - Never force a weak/ambiguous match to an existing category
    - Sub-category is REQUIRED for substantive calls (do not return null for substantive calls)
    - If no existing sub-category fits, propose a new specific sub-category under the chosen category
    - Do NOT use "General", "Other", or "Unclear" for substantive business discussions

5. CONFIDENCE SCORING (STRICT THRESHOLDS):
   - 0.90-1.0: Very clear topic, obvious category match → USE EXISTING CATEGORY
    - 0.70-0.89: Reasonable match but some ambiguity → CREATE NEW SPECIFIC CATEGORY instead of guessing
    - Below 0.70: If transcript has business intent, still output the best specific category hypothesis (avoid "General/Other/Unclear")

6. CONSISTENCY: Similar calls should receive the same category name.

7. STRICT RULES FOR "GENERAL":
   - Do NOT use "General" for real conversations with clear business intent
   - Only use "General" for greeting-only/no-response/unclear interactions where transcript is < 24 words AND no substantive intent detected
   - For substantive calls: either find specific existing category (>=90%) or create new appropriate category

{$categoryListQualityInstruction}

OUTPUT FORMAT (JSON ONLY, NO EXPLANATION):
{
  "category": "<exact category name from list above, or new category name>",
    "sub_category": "<exact sub-category name from list above, or a new specific sub-category name>",
    "confidence": 0.95
}
PROMPT;
    }

    /**
     * Detect whether the company currently has only a "General" category active.
     */
    private static function hasOnlyGeneralCategory($categories): bool
    {
        if ($categories->count() !== 1) {
            return false;
        }

        $name = trim((string) ($categories->first()->name ?? ''));

        return strcasecmp($name, 'General') === 0;
    }

    /**
     * Build the categories section with sub-categories
     */
    private static function buildCategoriesSection($categories): string
    {
        if ($categories->isEmpty()) {
            return '- (no active categories configured)';
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
        ?string $summaryText = null,
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
                $summaryText,
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
     * Parse AI response and validate categorization with STRICT 90% confidence enforcement.
     *
     * STRICT MATCHING RULES:
     * - Existing categories are ONLY accepted if confidence >= 0.90
     * - If confidence < 0.90 for an existing category, reject it and create new category instead
     * - If AI suggests a new category, create it (allows flexibility for unmatched cases)
     * - Never force weak/ambiguous matches to unrelated existing categories
     */
    public static function validateCategorization(
        array $aiResponse,
        ?int $companyId = null,
        ?string $transcriptText = null,
        ?string $summaryText = null
    ): array
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

        $categoryName = trim((string) ($aiResponse['category'] ?? ''));
        $subCategoryName = $aiResponse['sub_category'] ?? null;
        $confidence = (float) ($aiResponse['confidence'] ?? 0);

        if ($categoryName === '') {
            return [
                'valid' => false,
                'error' => 'Empty category field',
            ];
        }

        if (is_string($subCategoryName)) {
            $subCategoryName = trim($subCategoryName);
            if ($subCategoryName === '') {
                $subCategoryName = null;
            }
        }

        $normalizedTranscript = preg_replace('/\s+/', ' ', trim((string) $transcriptText));
        $transcriptWordCount = str_word_count((string) $normalizedTranscript);

        // Special validation for "General" category
        if (strcasecmp($categoryName, 'General') === 0) {
            $containsSubstantiveIntent = self::hasSubstantiveIntent((string) $normalizedTranscript);
            $looksLikeNoResponse = self::looksLikeNoResponseInteraction((string) $normalizedTranscript);

            $allowGeneral = (! $containsSubstantiveIntent && $transcriptWordCount <= self::GENERAL_ALLOWED_MAX_WORDS)
                || $looksLikeNoResponse;

            if (! $allowGeneral) {
                \Illuminate\Support\Facades\Log::warning('Rejecting AI General category for substantive transcript', [
                    'company_id' => $companyId,
                    'confidence' => $confidence,
                    'transcript_words' => $transcriptWordCount,
                    'contains_substantive_intent' => $containsSubstantiveIntent,
                    'looks_like_no_response' => $looksLikeNoResponse,
                    'transcript_preview' => mb_substr((string) $normalizedTranscript, 0, 220),
                ]);

                return [
                    'valid' => false,
                    'error' => 'General category rejected for substantive transcript',
                ];
            }
        }

        // "Other/Unclear" must never be used for substantive conversations.
        if (strcasecmp($categoryName, 'Other') === 0) {
            $containsSubstantiveIntent = self::hasSubstantiveIntent((string) $normalizedTranscript);
            $looksLikeNoResponse = self::looksLikeNoResponseInteraction((string) $normalizedTranscript);
            $isUnclearSub = is_string($subCategoryName)
                && strcasecmp(trim($subCategoryName), 'Unclear') === 0;

            if ($containsSubstantiveIntent && ! $looksLikeNoResponse && $transcriptWordCount > self::GENERAL_ALLOWED_MAX_WORDS) {
                return [
                    'valid' => false,
                    'error' => 'Other category rejected for substantive transcript',
                ];
            }

            if ($isUnclearSub && ! $looksLikeNoResponse && $transcriptWordCount > self::GENERAL_ALLOWED_MAX_WORDS) {
                return [
                    'valid' => false,
                    'error' => 'Other/Unclear rejected for substantive transcript',
                ];
            }
        }

        // Check if the suggested category is an EXISTING category in the database.
        // Search by company_id + name only (no status/enabled filter) so that archived
        // or disabled categories are found and re-activated rather than causing a
        // unique-constraint collision on CREATE.
        $existingCategory = CallCategory::withTrashed()
            ->where('company_id', $companyId)
            ->where('name', $categoryName)
            ->first();

        // Existing category candidate found.
        if ($existingCategory) {
            $category = $existingCategory;
            // Re-activate if archived/disabled/soft-deleted so AI can assign it again.
            if (!$category->is_enabled || $category->status !== 'active' || $category->trashed()) {
                $category->restore(); // no-op if not soft-deleted
                $category->is_enabled = true;
                $category->status = 'active';
                $category->save();
                \Illuminate\Support\Facades\Log::info("Re-activated archived/disabled category for AI use", [
                    'company_id' => $companyId,
                    'category_id' => $category->id,
                    'category_name' => $category->name,
                ]);
            }
        } else {
            // No existing category with this name - will create new one below
            $category = null;
        }

        // If no existing category found, create a new one.
        if (!$category) {
            $trimmedCategory = trim($categoryName);
            if ($trimmedCategory === '') {
                return [
                    'valid' => false,
                    'error' => 'Empty category name after trimming',
                ];
            }

            \Illuminate\Support\Facades\Log::info("Creating new AI-suggested category (no existing match)", [
                'company_id' => $companyId,
                'category_name' => $trimmedCategory,
                'ai_confidence' => $confidence,
            ]);

            try {
                $category = CallCategory::create([
                    'company_id' => $companyId,
                    'name' => $trimmedCategory,
                    'description' => 'Auto-created by AI based on call analysis',
                    'source' => 'ai',
                    'is_enabled' => true,
                    'status' => 'active',
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Race condition: another process created the category between our check and insert.
                // Re-fetch without status filters to handle archived/disabled records too.
                if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate')) {
                    $category = CallCategory::withTrashed()
                        ->where('company_id', $companyId)
                        ->where('name', $trimmedCategory)
                        ->first();

                    if (!$category) {
                        return [
                            'valid' => false,
                            'error' => "Cannot assign category '$trimmedCategory' - duplicate detected but not found",
                        ];
                    }

                    // Re-activate if needed.
                    if (!$category->is_enabled || $category->status !== 'active' || $category->trashed()) {
                        $category->restore();
                        $category->is_enabled = true;
                        $category->status = 'active';
                        $category->save();
                    }
                } else {
                    throw $e;
                }
            }
        }

        $containsSubstantiveIntent = self::hasSubstantiveIntent((string) $normalizedTranscript);
        $looksLikeNoResponse = self::looksLikeNoResponseInteraction((string) $normalizedTranscript);

        if ($subCategoryName === null && $containsSubstantiveIntent && ! $looksLikeNoResponse) {
            $subCategoryName = self::buildAutoSubCategoryName(
                $category->name,
                (string) $normalizedTranscript,
                (string) $summaryText
            );

            \Illuminate\Support\Facades\Log::info('Auto-generated sub-category for substantive call', [
                'company_id' => $companyId,
                'category_id' => $category->id,
                'category_name' => $category->name,
                'sub_category_name' => $subCategoryName,
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
                    'category_id' => $category->id,
                    'name' => $subCategoryName,
                    'description' => 'Auto-created by AI based on call analysis',
                    'is_enabled' => true,
                    'status' => 'active',
                ]);
            }

            if ($confidence < self::CONFIDENCE_THRESHOLD) {
                if (! $containsSubstantiveIntent || $looksLikeNoResponse || $confidence < self::SUBCATEGORY_REFINED_THRESHOLD) {
                    return [
                        'valid' => false,
                        'error' => 'Confidence too low for sub-category-assisted assignment',
                    ];
                }

                \Illuminate\Support\Facades\Log::info('Accepted low-confidence categorization due to specific sub-category', [
                    'company_id' => $companyId,
                    'category_id' => $category->id,
                    'sub_category_id' => $subCategory->id,
                    'confidence' => $confidence,
                    'refined_threshold' => self::SUBCATEGORY_REFINED_THRESHOLD,
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

        // Confidence gates:
        // - >= 0.90 always valid
        // - 0.75..0.89 valid only for substantive calls that have a specific sub-category
        if ($confidence < self::CONFIDENCE_THRESHOLD) {
            return [
                'valid' => false,
                'error' => 'Confidence below strict threshold and no specific sub-category available',
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

    private static function buildAutoSubCategoryName(string $categoryName, string $transcriptText, string $summaryText = ''): string
    {
        $source = strtolower(trim($summaryText . ' ' . $transcriptText));

        $rules = [
            'payment|invoice|billing|card|charge|refund' => 'Payment and Billing',
            'quote|pricing|price|budget|cost' => 'Pricing and Quotes',
            'booking|schedule|appointment|calendar|reschedule' => 'Booking and Scheduling',
            'website|web\s?design|hosting|domain|seo|content|landing\s?page' => 'Website Development',
            'support|issue|problem|error|bug|fix|troubleshoot' => 'Technical Support',
            'voicemail|no response|unavailable|missed call|leave a message' => 'Voicemail or No Response',
            'sales|lead|proposal|inquiry|enquiry' => 'Sales Inquiry',
        ];

        foreach ($rules as $pattern => $label) {
            if (preg_match('/' . $pattern . '/i', $source) === 1) {
                return $label;
            }
        }

        return 'General ' . trim($categoryName);
    }

    /**
     * Fetch active categories and their active sub-categories.
     * Excludes AI-generated categories to avoid contaminating candidate matching with low-quality auto-created categories.
     * Only approved (manually-created or system-default) categories are passed to the AI for matching.
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
            ->where('source', '!=', 'ai')  // Only include admin-created categories, exclude AI-generated to avoid test/noise contamination
            ->orderBy('name')  // Stable ordering to avoid non-deterministic prompt context
            ->with(['subCategories' => function ($query) {
                $query->where('is_enabled', true)
                    ->where('status', 'active');
            }])
            ->get();
    }

    private static function looksLikeNoResponseInteraction(string $transcript): bool
    {
        if ($transcript === '') {
            return true;
        }

        $value = strtolower($transcript);
        $wordCount = str_word_count($value);

        if ($wordCount > self::NO_RESPONSE_MAX_WORDS) {
            return false;
        }

        if (self::hasSubstantiveIntent($value)) {
            return false;
        }

        $signals = [
            'no response',
            'no answer',
            'voicemail',
            'please leave a message',
            'please record your message',
            'at the tone',
            'mailbox',
            'is not available',
            'call ended',
            'wrong number',
        ];

        foreach ($signals as $signal) {
            if (str_contains($value, $signal)) {
                return true;
            }
        }

        return false;
    }

    private static function hasSubstantiveIntent(string $transcript): bool
    {
        if ($transcript === '') {
            return false;
        }

        $value = strtolower($transcript);

        $intentSignals = [
            'interested in',
            'want to',
            'would like to',
            'need',
            'help with',
            'support',
            'issue',
            'problem',
            'billing',
            'invoice',
            'payment',
            'price',
            'quote',
            'booking',
            'appointment',
            'schedule',
            'demo',
            'trial',
            'order',
            'service',
            'complaint',
            'follow up',
            'website',
            'development',
            'technical',
            'integration',
            'feature',
            'request',
        ];

        foreach ($intentSignals as $signal) {
            if (str_contains($value, $signal)) {
                return true;
            }
        }

        return false;
    }
}
