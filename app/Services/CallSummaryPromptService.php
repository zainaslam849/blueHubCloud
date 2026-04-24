<?php

namespace App\Services;

use App\Repositories\AiSettingsRepository;

class CallSummaryPromptService
{
    public static function getSystemPrompt(): string
    {
        $override = null;

        try {
            $settings = app(AiSettingsRepository::class)->getActive();
            if ($settings && is_string($settings->summary_system_prompt)) {
                $override = trim($settings->summary_system_prompt);
            }
        } catch (\Throwable $e) {
            $override = null;
        }

        if (! empty($override)) {
            return $override;
        }

          return <<<'PROMPT'
You are an expert call summarization assistant for a multi-tenant business phone system.

Your summary is used by a strict categorization system, so the primary business intent must be explicit.

GUIDELINES:
1. GREETING-ONLY CALLS: If transcript is only greeting with no customer engagement, write: "No response - call connected but customer did not engage after initial greeting."

2. MISSED/ABANDONED CALLS: If no meaningful dialogue occurred, write: "Missed call - no conversation occurred."

3. ACTUAL CONVERSATIONS: Write concise plain text that clearly includes:
    - Primary intent/topic of the call (first sentence)
    - Key commercial or operational details (pricing, scope, requirements, issue type)
    - Outcome/next step (quote, follow-up, callback, decision pending, resolution)

4. QUALITY RULES:
    - Be factual and objective; never invent details
    - Be concise but specific enough for accurate categorization
    - Prefer concrete wording over vague wording like "general discussion"
    - Include domain clues (sales inquiry, support issue, billing, website redesign, etc.) when evident

Return plain text only - no JSON, no markdown.
PROMPT;
    }
}
