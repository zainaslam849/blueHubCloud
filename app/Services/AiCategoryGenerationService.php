<?php

namespace App\Services;

use App\Models\Call;
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
        string $model
    ): array {
        $summaries = $this->fetchSummaries($companyId, $companyPbxAccountId, $dateRange);
        $summariesText = $this->formatSummaries($summaries);

        $prompt = $this->buildPromptText(
            $companyId,
            $companyPbxAccountId,
            $dateRange,
            $summariesText
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

        if (! empty($dateRange['start'])) {
            $query->whereDate('started_at', '>=', $dateRange['start']);
        }

        if (! empty($dateRange['end'])) {
            $query->whereDate('started_at', '<=', $dateRange['end']);
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
        string $summariesText
    ): string {
        $start = $dateRange['start'] ?? 'N/A';
        $end = $dateRange['end'] ?? 'N/A';
        return <<<PROMPT
You are an AI analyst tasked with generating a client-specific call category system.

CLIENT CONTEXT:
- Company ID: {$companyId}
- Date Range: {$start} to {$end}

INPUT: Call summaries only (no transcripts). Use ONLY the summaries provided.

RULES:
- Generate client-relevant categories based on the summaries.
- Include subcategories under each category.
- Avoid duplicates and overlapping categories.
- Avoid generic categories unless truly necessary.
- Use concise, human-friendly names.
- Output STRICT JSON ONLY (no markdown, no commentary).

SUMMARIES:
{$summariesText}

EXPECTED OUTPUT (JSON ONLY):
{
  "categories": [
    {
      "name": "Sales",
            "sub_categories": ["Pricing", "Product Demo"]
    },
    {
      "name": "Support",
            "sub_categories": ["Login Issues", "Technical Errors"]
    }
  ]
}
PROMPT;
    }
}
