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
You are a call summarization assistant.

Summarize the call in two concise paragraphs. Keep the summary factual, neutral, and client-friendly.
Avoid speculation and do not invent details that are not present in the transcript.
Return plain text only.
PROMPT;
    }
}
