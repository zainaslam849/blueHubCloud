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

CORE PRINCIPLE — SEMANTIC REUSE OVER NEW CREATION:
Your single most important job is to keep each company's category list small, clean, and reusable. Always prefer reusing an existing category over inventing a new one. You decide reuse vs. new by MEANING, never by exact wording.

============================================================
STEP 1 — UNDERSTAND THE CALL'S CORE INTENT
============================================================
Read the AI summary (primary signal) and the transcript. In one short mental sentence, identify the call's CORE INTENT:
   - WHAT does the caller want? (information, a quote, support, to pay a bill, to book, to complain, to follow up, to cancel, to apply, to schedule, etc.)
   - ABOUT WHAT? (a product, a service, an invoice, an appointment, a property, an order, a job application, a delivery, etc.)
The category should describe the WHAT (the broad business topic). The sub_category should describe the nuance (the specific variant, stage, product, or action).

============================================================
STEP 2 — SEMANTIC MATCH AGAINST THE EXISTING LIST (STRICT)
============================================================
Compare your one-sentence intent to EVERY name in the AVAILABLE CATEGORIES list. Compare by MEANING, not by spelling.

Things that DO NOT matter (treat as the same):
   - Letter case ("billing inquiry" = "Billing Inquiry" = "BILLING INQUIRY")
   - Punctuation, hyphens, ampersands, slashes ("Sales & Marketing" = "Sales and Marketing" = "Sales/Marketing")
   - Singular vs. plural ("Order" = "Orders"; "Complaint" = "Complaints")
   - Word order ("Support Technical" = "Technical Support")
   - Synonyms and paraphrases of the same concept ("Inquiry" = "Question" = "Enquiry"; "Booking" = "Reservation" = "Appointment" when contextually equivalent)
   - Adding or removing generic qualifier words ("Inquiry", "Discussion", "Call", "Request", "Question")
   - Expanded vs. contracted scope of the SAME topic ("Website Development" vs. "Website Design and Development" — same business activity)

THE 90% RULE:
   - If your call's intent overlaps with any existing category's meaning at >= 90% confidence, you MUST reuse that existing category. Copy its name CHARACTER-FOR-CHARACTER from the list below (preserve its original casing, spelling, and punctuation exactly).
   - Only when NO existing category overlaps at >= 90% are you allowed to propose a brand-new category name.
   - "Slightly different wording" is NOT a reason to create a new category. "Genuinely different business topic" is the only valid reason.

============================================================
STEP 3 — USE sub_category FOR THE NUANCE
============================================================
Differences in stage, action, product line, or specifics belong in sub_category, NOT in a new top-level category.

Pattern (works across ALL industries):
   category   = the broad business topic the caller is engaging about
   sub_category = the specific action, stage, product, issue, or variant within that topic

Cross-industry examples of correct collapsing (illustrative — apply the same pattern to ANY domain):
   - Web/IT agency:
       category="Website Development", sub_category="Bug Fix"
       category="Website Development", sub_category="New Build Quote"
       category="Website Development", sub_category="Redesign Discussion"
       category="Website Development", sub_category="Hosting / Domain"
     (NOT separate categories like "Website Design", "Website Bug Fix", "Web Project Discussion".)
   - Real estate:
       category="Property Inquiry", sub_category="Viewing Request"
       category="Property Inquiry", sub_category="Rental Application"
       category="Property Inquiry", sub_category="Price Negotiation"
   - Hospitality / restaurant / hotel:
       category="Reservation", sub_category="New Booking"
       category="Reservation", sub_category="Modify Booking"
       category="Reservation", sub_category="Cancellation"
   - Healthcare / clinic:
       category="Appointment", sub_category="New Booking"
       category="Appointment", sub_category="Reschedule"
       category="Appointment", sub_category="Prescription Refill"
   - Retail / e-commerce:
       category="Order Inquiry", sub_category="Order Status"
       category="Order Inquiry", sub_category="Return / Refund"
       category="Order Inquiry", sub_category="Product Question"
   - Telecom / ISP:
       category="Technical Support", sub_category="Outage / Connectivity"
       category="Technical Support", sub_category="Device Setup"
       category="Billing Inquiry", sub_category="Payment Discussion"
       category="Billing Inquiry", sub_category="Invoice Question"
       category="Billing Inquiry", sub_category="Plan Change"
   - Professional services (legal / accounting / consulting):
       category="Consultation Request", sub_category="New Client"
       category="Consultation Request", sub_category="Existing Matter Update"
   - Trades / home services (plumbing, electrical, HVAC):
       category="Service Request", sub_category="Emergency Callout"
       category="Service Request", sub_category="Quote Request"
       category="Service Request", sub_category="Follow-up Visit"

These are PATTERNS, not a fixed list. Apply the same logic to whatever industry the company operates in.

============================================================
STEP 4 — WHEN TO CREATE A NEW CATEGORY (RARE)
============================================================
Only create a new category when ALL of the following are true:
   - No existing category overlaps with the call's intent at >= 90% confidence (by meaning, not wording).
   - The new topic is broad enough that future calls of the same kind will likely reuse it.
   - The new name is semantically distinct from every existing name (not a synonym, paraphrase, or variant).

A new category name should be:
   - Short (1–4 words)
   - Industry-appropriate
   - Broad (the specifics go in sub_category)
   - In Title Case

============================================================
STEP 5 — SPECIAL CASES (always check first)
============================================================
   - GREETING-ONLY / NO CUSTOMER RESPONSE → "No Response" (or "Missed Call" if it already exists)
   - ABANDONED / IMMEDIATE HANG-UP → "Missed Call" (or "Abandoned" if it exists)
   - VOICEMAIL ONLY → "Voicemail" (if exists)
   - AFTER HOURS with no real conversation → "After Hours" (if exists)
   - "General" is ONLY for genuinely unclear / sub-24-word non-conversations. NEVER for substantive calls.

============================================================
CONFIDENCE SCORING
============================================================
   - 0.90–1.00: Clear topic, strong meaning match → REUSE existing category (must be >= 0.90 to reuse).
   - 0.70–0.89: Reasonable but ambiguous match → propose a new specific category (do NOT force-reuse).
   - < 0.70: Weak signal → still output the most likely specific business category; avoid "General/Other/Unclear" unless the call is truly empty.

============================================================
HARD RULES
============================================================
   - Choose exactly ONE primary category.
   - sub_category is REQUIRED for any substantive call (only null for greeting-only / missed / voicemail / after-hours non-conversations).
   - If reusing an existing category, copy its name verbatim from the list (exact case, exact spelling).
   - Never invent a synonym of an existing category. Put the nuance in sub_category instead.
   - Be consistent: two calls with the same business meaning must get the same category name.
   - Output VALID JSON ONLY. No explanation, no preamble, no markdown.
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

4. FOR ACTUAL CONVERSATIONS (STRICT SEMANTIC MATCHING):
   - Compare by MEANING, not by exact wording. Casing, plurals, punctuation, and minor phrasing differences are irrelevant.
   - If the call's meaning matches an existing category's meaning at >= 90% confidence, you MUST reuse that existing category's name verbatim (copy it from the list above).
   - Do NOT invent a near-synonym or expanded variant of an existing name. "Website Development" and "Website Design and Development" mean the same thing — pick the one already in the list and put the nuance (e.g. "design only", "new build", "redesign") into sub_category.
   - Only if NO existing category overlaps in meaning at >= 90% confidence should you propose a NEW category name.
   - Never force a weak/ambiguous match to an existing category.
   - Sub-category is REQUIRED for substantive calls (do not return null for substantive calls). Use sub_category to capture the specific differentiator within the broader category (e.g., category="Technical Support", sub_category="Bug Fix"; category="Billing Inquiry", sub_category="Payment Discussion").
   - If no existing sub-category fits, propose a new specific sub-category under the chosen category.
   - Do NOT use "General", "Other", or "Unclear" for substantive business discussions.

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
        // Use a case-insensitive, whitespace-tolerant match so AI variations like
        // "voicemail " / "Voicemail" / "VOICEMAIL" all resolve to the same row and
        // do NOT trip the (company_id, name) unique constraint on CREATE.
        $existingCategory = self::lookupCategorySafely($companyId, $categoryName);

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
                // Duplicate-key collision. Possible causes:
                //  1) Concurrent sibling job inserted the row between our SELECT and INSERT.
                //  2) AI returned the name with case/whitespace variant that our initial
                //     SELECT missed but the unique index treats as equal.
                //  3) Legacy row from before the company-scoped unique migration
                //     (NULL company_id) under the same name.
                // Re-fetch defensively so the call still gets categorized.
                if ($e->getCode() === '23000' || str_contains(strtolower($e->getMessage()), 'duplicate')) {
                    $category = self::recoverCategoryAfterDuplicate($companyId, $trimmedCategory, $e);

                    if (!$category) {
                        // Final defensive fallback: never leave the call uncategorized
                        // because of a transient lookup miss. Create with a disambiguated
                        // name so the unique constraint is guaranteed to pass.
                        \Illuminate\Support\Facades\Log::error("Cannot recover category after duplicate; creating disambiguated row", [
                            'company_id' => $companyId,
                            'category_name' => $trimmedCategory,
                            'error' => $e->getMessage(),
                        ]);

                        $category = CallCategory::create([
                            'company_id' => $companyId,
                            'name' => $trimmedCategory.' ('.$companyId.'-'.substr((string) microtime(true), -6).')',
                            'description' => 'Auto-created by AI (disambiguated after duplicate-recovery failure)',
                            'source' => 'ai',
                            'is_enabled' => true,
                            'status' => 'active',
                        ]);
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
            // Case-insensitive trimmed match including disabled/archived rows so we
            // do NOT trip the (category_id, name) unique index on CREATE.
            $trimmedSub = trim($subCategoryName);
            $subCategory = \App\Models\SubCategory::query()
                ->where('category_id', $category->id)
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($trimmedSub)])
                ->first();

            if ($subCategory) {
                if (!$subCategory->is_enabled || $subCategory->status !== 'active') {
                    $subCategory->is_enabled = true;
                    $subCategory->status = 'active';
                    $subCategory->save();
                }
            } else {
                // AI suggested a new sub-category - create it automatically
                \Illuminate\Support\Facades\Log::info("Creating new AI-suggested sub-category", [
                    'company_id' => $companyId,
                    'category_id' => $category->id,
                    'category_name' => $category->name,
                    'sub_category_name' => $trimmedSub,
                    'ai_confidence' => $confidence,
                ]);

                try {
                    $subCategory = \App\Models\SubCategory::create([
                        'category_id' => $category->id,
                        'name' => $trimmedSub,
                        'description' => 'Auto-created by AI based on call analysis',
                        'is_enabled' => true,
                        'status' => 'active',
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    if ($e->getCode() === '23000' || str_contains(strtolower($e->getMessage()), 'duplicate')) {
                        \DB::reconnect();
                        $subCategory = \App\Models\SubCategory::query()
                            ->where('category_id', $category->id)
                            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($trimmedSub)])
                            ->first();

                        if (!$subCategory) {
                            // Disambiguate to guarantee insert succeeds rather than
                            // failing the whole categorization.
                            $subCategory = \App\Models\SubCategory::create([
                                'category_id' => $category->id,
                                'name' => $trimmedSub.' ('.substr((string) microtime(true), -6).')',
                                'description' => 'Auto-created by AI (disambiguated after duplicate-recovery failure)',
                                'is_enabled' => true,
                                'status' => 'active',
                            ]);
                        } elseif (!$subCategory->is_enabled || $subCategory->status !== 'active') {
                            $subCategory->is_enabled = true;
                            $subCategory->status = 'active';
                            $subCategory->save();
                        }
                    } else {
                        throw $e;
                    }
                }
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

    /**
     * Find an existing CallCategory for this company tolerating case and whitespace
     * differences in the AI-supplied name. Includes archived/disabled and soft-deleted
     * rows so the caller can re-activate them instead of colliding with the
     * (company_id, name) unique index on CREATE.
     */
    private static function lookupCategorySafely(int $companyId, string $rawName): ?CallCategory
    {
        $trimmed = trim($rawName);
        if ($trimmed === '') {
            return null;
        }

        $hit = CallCategory::withTrashed()
            ->where('company_id', $companyId)
            ->where('name', $trimmed)
            ->first();

        if ($hit) {
            return $hit;
        }

        $hit = CallCategory::withTrashed()
            ->where('company_id', $companyId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($trimmed)])
            ->first();

        if ($hit) {
            return $hit;
        }

        // Final pass: normalized fingerprint match across all rows for this company
        // so semantic variants (punctuation, plurals, word-order, stop-words) collapse
        // to the existing row instead of triggering a duplicate insert.
        $targetKey = self::normalizeNameForMatch($trimmed);
        if ($targetKey === '') {
            return null;
        }

        return CallCategory::withTrashed()
            ->where('company_id', $companyId)
            ->get()
            ->first(fn (CallCategory $c) => self::normalizeNameForMatch((string) $c->name) === $targetKey);
    }

    /**
     * Canonical fingerprint of a category/sub-category name so semantically equivalent
     * variants (case, punctuation, plurals, word-order, generic stop-words) compare equal.
     * Mirrors CallCategorizationPersistenceService::normalizeForMatch().
     */
    private static function normalizeNameForMatch(string $value): string
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

        $tokens = array_map(function (string $t) {
            if (mb_strlen($t) > 3 && str_ends_with($t, 'ies')) {
                return mb_substr($t, 0, -3) . 'y';
            }
            if (mb_strlen($t) > 3 && str_ends_with($t, 's') && ! str_ends_with($t, 'ss')) {
                return mb_substr($t, 0, -1);
            }
            return $t;
        }, $tokens);

        $tokens = array_values(array_unique($tokens));
        sort($tokens);

        return implode(' ', $tokens);
    }

    /**
     * Recover a CallCategory after the INSERT failed with a duplicate-key error.
     *
     * The recovery query MUST escape any open transaction snapshot — otherwise under
     * REPEATABLE READ the SELECT keeps returning the same null result it returned
     * before the conflicting insert from another worker committed. We force a fresh
     * DB connection before re-querying and try several lookup strategies in order
     * of likelihood.
     */
    private static function recoverCategoryAfterDuplicate(int $companyId, string $trimmedCategory, \Throwable $originalException): ?CallCategory
    {
        try {
            \Illuminate\Support\Facades\DB::reconnect();
        } catch (\Throwable $reconnectError) {
            \Illuminate\Support\Facades\Log::warning('DB::reconnect() failed during category recovery', [
                'error' => $reconnectError->getMessage(),
            ]);
        }

        $hit = CallCategory::withTrashed()
            ->where('company_id', $companyId)
            ->where('name', $trimmedCategory)
            ->first();
        if ($hit) {
            return $hit;
        }

        $hit = CallCategory::withTrashed()
            ->where('company_id', $companyId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($trimmedCategory)])
            ->first();
        if ($hit) {
            return $hit;
        }

        // Legacy row from before the company-scoped unique migration: row exists
        // with NULL company_id under the same name. Adopt it into this company.
        $orphan = CallCategory::withTrashed()
            ->whereNull('company_id')
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($trimmedCategory)])
            ->first();
        if ($orphan) {
            $orphan->company_id = $companyId;
            $orphan->save();
            \Illuminate\Support\Facades\Log::info('Adopted legacy NULL-company category during AI categorization', [
                'company_id' => $companyId,
                'category_id' => $orphan->id,
                'category_name' => $orphan->name,
            ]);
            return $orphan;
        }

        \Illuminate\Support\Facades\Log::error('Category duplicate-recovery exhausted all strategies', [
            'company_id' => $companyId,
            'category_name' => $trimmedCategory,
            'original_error' => $originalException->getMessage(),
            'visible_rows' => CallCategory::withTrashed()
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($trimmedCategory)])
                ->get(['id', 'company_id', 'name', 'is_enabled', 'status', 'deleted_at'])
                ->toArray(),
        ]);

        return null;
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
     * Fetch active categories and their active sub-categories for the company.
     *
     * Includes BOTH admin-created and AI-created categories so the AI can reuse
     * categories generated in earlier pipeline runs instead of inventing slight
     * name variants (e.g. "Website Design and Development" vs "Website
     * Development") that bloat the taxonomy and create duplicate rows.
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
