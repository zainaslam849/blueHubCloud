<?php

namespace App\Services;

use App\Models\Call;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AiCategoryGenerationService
{
    /**
     * Build a single prompt to generate categories + subcategories from call summaries.
     *
     * @param  int  $companyId
     * @param  int|null  $companyPbxAccountId
     * @param  array{start?: string|null, end?: string|null}  $dateRange
     * @param  string  $model
     * @return array{prompt: string, model: string, summary_count: int}
     */
    public function buildPrompt(
        int $companyId,
        ?int $companyPbxAccountId,
        array $dateRange,
        string $model,
        array $existingCategoryNames = []
    ): array {
        $summaries = $this->fetchSummaries($companyId, $companyPbxAccountId, $dateRange);
        $summariesText = $this->formatSummaries($summaries);

        $prompt = $this->buildPromptText(
            $companyId,
            $companyPbxAccountId,
            $dateRange,
            $summariesText,
            $existingCategoryNames
        );

        return [
            'prompt' => $prompt,
            'model' => $model,
            'summary_count' => $summaries->count(),
        ];
    }

    /**
     * Count summaries available for a date range.
     */
    public function getSummaryCount(int $companyId, array $dateRange): int
    {
        return $this->fetchSummaries($companyId, null, $dateRange)->count();
    }

    /**
     * Fetch call AI summaries only (NO transcripts).
     */
    private function fetchSummaries(
        int $companyId,
        ?int $companyPbxAccountId,
        array $dateRange
    ): Collection {
        $query = Call::query()
            ->where('company_id', $companyId)
            ->whereNotNull('ai_summary')
            ->select('ai_summary')
            ->orderByDesc('started_at');

        if ($companyPbxAccountId) {
            $query->where('company_pbx_account_id', $companyPbxAccountId);
        }

        // calls.started_at is stored in UTC; this is always scoped to one
        // company, so use that company's own timezone for the date-range
        // boundaries instead of comparing local dates against the raw UTC
        // column (whereDate), which shifts the window by the UTC offset.
        if (! empty($dateRange['start']) || ! empty($dateRange['end'])) {
            $companyTimezone = Company::find($companyId)?->timezone;
            $timezone = is_string($companyTimezone) && $companyTimezone !== '' ? $companyTimezone : 'UTC';

            if (! empty($dateRange['start'])) {
                $query->where('started_at', '>=', CarbonImmutable::parse($dateRange['start'], $timezone)->startOfDay()->setTimezone('UTC'));
            }

            if (! empty($dateRange['end'])) {
                $query->where('started_at', '<=', CarbonImmutable::parse($dateRange['end'], $timezone)->endOfDay()->setTimezone('UTC'));
            }
        }

        return $query->pluck('ai_summary')
            ->filter(fn ($summary) => is_string($summary) && trim($summary) !== '')
            ->values();
    }

    /**
     * Format summaries into a numbered list for the prompt.
     */
    private function formatSummaries(Collection $summaries): string
    {
        if ($summaries->isEmpty()) {
            return "(No summaries available for this range.)";
        }

        return $summaries
            ->values()
            ->map(function ($summary, $index) {
                $num = $index + 1;
                return "{$num}. {$summary}";
            })
            ->implode("\n\n");
    }

    /**
     * Build the AI prompt text for category generation.
     */
    private function buildPromptText(
        int $companyId,
        ?int $companyPbxAccountId,
        array $dateRange,
        string $summariesText,
        array $existingCategoryNames = []
    ): string {
        $start = $dateRange['start'] ?? 'N/A';
        $end = $dateRange['end'] ?? 'N/A';

        $existingSection = '';
        if (! empty($existingCategoryNames)) {
            $nameList = implode("\n", array_map(fn ($n) => "- {$n}", $existingCategoryNames));
            $existingSection = "\nEXISTING CATEGORY NAMES (you MUST reuse these exact names when they cover the same topic):\n"
                . $nameList
                . "\n\nREUSE RULE: Before inventing any new category name, check this list. If an existing name adequately describes the call topic, use that EXACT name (same spelling, same punctuation, same capitalisation). Only create a brand-new name when no existing entry is a good fit.\n";
        }

        return <<<PROMPT
You are an AI analyst tasked with generating a client-specific call category system.

CLIENT CONTEXT:
- Company ID: {$companyId}
- Date Range: {$start} to {$end}

INPUT: Call summaries only (no transcripts). Use ONLY the summaries provided.
{$existingSection}
RULES:
- Generate 5-15 client-relevant categories based on the summaries.
- Include 2-5 subcategories under each category.
- Avoid duplicates and overlapping categories.
- Avoid generic categories unless truly necessary.
- Use concise, human-friendly names (max 50 characters).
- Return ONLY valid JSON. No markdown. No code blocks. No explanation. No preamble. No extra text.

SUMMARIES:
{$summariesText}

REQUIRED OUTPUT FORMAT - RETURN ONLY THIS JSON STRUCTURE, NOTHING ELSE:
{"categories":[{"name":"Category Name","sub_categories":["Subcategory 1","Subcategory 2"]},{"name":"Another Category","sub_categories":["Sub 1","Sub 2"]}]}

Do not include any text before or after the JSON object. Return ONLY the JSON.
PROMPT;
    }
}
