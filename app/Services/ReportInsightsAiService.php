<?php

namespace App\Services;

use App\Repositories\AiSettingsRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Generate AI-powered business insights from aggregated weekly metrics.
 *
 * ⚠️ IMPORTANT:
 * - Input: ONLY aggregated metrics (counts, percentages, statistics)
 * - Output: Business analysis, recommendations, risks
 * - NO TRANSCRIPTS, NO CALL DETAILS, NO PII
 *
 * This ensures privacy compliance and focuses on actionable business intelligence.
 */
class ReportInsightsAiService
{
    public function __construct(
        private ?AiSettingsRepository $aiSettingsRepo = null
    ) {
    }

    /**
     * Generate business insights from aggregated metrics.
     *
     * Input structure:
     * {
     *   "period": "Jan 5 – Jan 11, 2026",
     *   "total_calls": 354,
     *   "answered_calls": 354,
     *   "answer_rate": 100,
     *   "missed_calls": 0,
     *   "avg_call_duration_seconds": 127,
     *   "calls_with_transcription": 354,
     *   "after_hours_percentage": 12.5,
     *   "peak_hours": [9, 10, 11, 12, 13, 14, 15],
     *   "category_counts": {
     *     "1|Property Enquiry": 261,
     *     "2|Maintenance Request": 13,
     *     "3|Other": 80
     *   },
     *   "top_sub_categories": {
     *     "1|Property Enquiry": [
     *       { "name": "10|Availability/Pricing", "count": 161, "percentage": 45.5 },
     *       { "name": "11|Viewing/Inspection", "count": 41, "percentage": 11.6 }
     *     ]
     *   }
     * }
     *
     * @param  array<string, mixed>  $metrics
     * @return array{ai_summary: string, recommendations: array, risks: array, automation_opportunities: array}
     */
    public function generateInsights(array $metrics): array
    {
        try {
            $repo = $this->aiSettingsRepo ?? app(AiSettingsRepository::class);
            $aiSettings = $repo->getActive();

            if (! $aiSettings || ! $aiSettings->enabled) {
                return $this->emptyInsights();
            }

            if (! $aiSettings->api_key || ! $aiSettings->report_model) {
                return $this->emptyInsights();
            }

            // Build prompt for AI
            $prompt = $this->buildPrompt($metrics);

            $modelParameters = [
                'temperature' => 0.5,
                'max_tokens' => 1500,
            ];

            $response = $this->callProvider(
                $aiSettings->provider,
                $aiSettings->api_key,
                $aiSettings->report_model,
                $prompt,
                $modelParameters
            );

            // Parse AI response
            $parsed = $this->parseResponse($response);

            return [
                'ai_summary' => $parsed['summary'] ?? '',
                'recommendations' => $parsed['recommendations'] ?? [],
                'risks' => $parsed['risks'] ?? [],
                'automation_opportunities' => $parsed['opportunities'] ?? [],
            ];
        } catch (\Exception $e) {
            Log::error('Failed to generate AI insights', [
                'error' => $e->getMessage(),
                'metrics_keys' => array_keys($metrics),
            ]);

            // Graceful fallback - return empty insights
            return $this->emptyInsights();
        }
    }

    /**
     * @return array{ai_summary: string, recommendations: array, risks: array, automation_opportunities: array}
     */
    private function emptyInsights(): array
    {
        return [
            'ai_summary' => '',
            'recommendations' => [],
            'risks' => [],
            'automation_opportunities' => [],
        ];
    }

    /**
     * Build structured prompt for AI analysis.
     *
     * Focus: Business metrics, operational efficiency, staffing recommendations.
     * NO: Transcripts, specific caller details, PII, individual call analysis.
     *
     * @param  array<string, mixed>  $metrics
     */
    private function buildPrompt(array $metrics): string
    {
        $period = $metrics['period'] ?? 'Unknown period';
        $totalCalls = $metrics['total_calls'] ?? 0;
        $answeredCalls = $metrics['answered_calls'] ?? 0;
        $answerRate = $metrics['answer_rate'] ?? 0;
        $missedCalls = $metrics['missed_calls'] ?? 0;
        $afterHoursPerc = $metrics['after_hours_percentage'] ?? 0;
        $peakHours = isset($metrics['peak_hours']) ? implode(', ', $metrics['peak_hours']) : 'None identified';

        $categoryBreakdown = '';
        if (isset($metrics['category_counts']) && is_array($metrics['category_counts'])) {
            foreach ($metrics['category_counts'] as $categoryKey => $count) {
                // Extract readable name from "id|name" format
                $categoryName = $this->extractCategoryName($categoryKey);
                $percentage = $totalCalls > 0 ? round(($count / $totalCalls) * 100, 1) : 0;
                $categoryBreakdown .= "- {$categoryName}: {$count} calls ({$percentage}%)\n";
            }
        }

        $avgDuration = isset($metrics['avg_call_duration_seconds'])
            ? $this->formatDuration($metrics['avg_call_duration_seconds'])
            : 'Unknown';

        return <<<PROMPT
You are a business intelligence analyst reviewing call center metrics for a weekly period.

PERIOD: {$period}

CALL VOLUME METRICS:
- Total Calls: {$totalCalls}
- Answered Calls: {$answeredCalls}
- Answer Rate: {$answerRate}%
- Missed Calls: {$missedCalls}
- Average Call Duration: {$avgDuration}

CATEGORY BREAKDOWN:
{$categoryBreakdown}

OPERATIONAL METRICS:
- Peak Hours: {$peakHours}
- After-Hours Calls: {$afterHoursPerc}%

TASK:
Provide ONLY the following (no preamble, no explanation):

1. EXECUTIVE SUMMARY (2-3 sentences):
   Brief overview of call volume, quality, and trends for this period.

2. RECOMMENDATIONS (3-5 bullet points):
   Specific, actionable recommendations for improving operations.
   Focus: Staffing, routing, efficiency, customer satisfaction.

3. OPERATIONAL RISKS (if any):
   Specific concerns from the metrics (e.g., low answer rate, high after-hours volume).
   If no significant risks, write "No significant operational risks detected."

4. AUTOMATION OPPORTUNITIES (2-4 bullet points):
   Which call categories or patterns could benefit from automation or self-service.

Format your response EXACTLY as JSON (no markdown, no code blocks):
{
  "executive_summary": "...",
  "recommendations": ["...", "..."],
  "risks": ["..."],
  "opportunities": ["...", "..."]
}
PROMPT;
    }

    /**
     * @param  array{temperature: float, max_tokens: int}  $modelParameters
     */
    private function callProvider(
        string $provider,
        string $apiKey,
        string $model,
        string $prompt,
        array $modelParameters
    ): string {
        if ($provider === 'openrouter') {
            return $this->callOpenRouter($apiKey, $model, $prompt, $modelParameters);
        }

        [$modelProvider, $modelName] = array_pad(explode('/', $model, 2), 2, null);

        if ($provider === 'openai' || $modelProvider === 'openai') {
            return $this->callOpenAI($apiKey, $modelName ?? $model, $prompt, $modelParameters);
        }

        if ($provider === 'anthropic' || $modelProvider === 'anthropic') {
            return $this->callAnthropic($apiKey, $modelName ?? $model, $prompt, $modelParameters);
        }

        throw new \Exception("Unsupported AI provider: {$provider}");
    }

    private function callOpenAI(string $apiKey, string $model, string $prompt, array $modelParameters): string
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $modelParameters['temperature'],
            'max_tokens' => $modelParameters['max_tokens'],
        ]);

        if (! $response->successful()) {
            throw new \Exception("OpenAI API failed ({$response->status()}): " . $response->body());
        }

        $content = $response->json()['choices'][0]['message']['content'] ?? null;

        if (! $content) {
            throw new \Exception('No content in OpenAI response');
        }

        return (string) $content;
    }

    private function callAnthropic(string $apiKey, string $model, string $prompt, array $modelParameters): string
    {
        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'Content-Type' => 'application/json',
            'anthropic-version' => '2023-06-01',
        ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
            'model' => $model,
            'max_tokens' => $modelParameters['max_tokens'],
            'temperature' => $modelParameters['temperature'],
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        if (! $response->successful()) {
            throw new \Exception("Anthropic API failed ({$response->status()}): " . $response->body());
        }

        $content = $response->json()['content'][0]['text'] ?? null;

        if (! $content) {
            throw new \Exception('No content in Anthropic response');
        }

        return (string) $content;
    }

    private function callOpenRouter(string $apiKey, string $model, string $prompt, array $modelParameters): string
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'HTTP-Referer' => config('app.url'),
            'X-Title' => 'blueHubCloud Report Insights',
        ])->timeout(30)->post('https://openrouter.ai/api/v1/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $modelParameters['temperature'],
            'max_tokens' => $modelParameters['max_tokens'],
        ]);

        if (! $response->successful()) {
            throw new \Exception("OpenRouter API failed ({$response->status()}): " . $response->body());
        }

        $content = $response->json()['choices'][0]['message']['content'] ?? null;

        if (! $content) {
            throw new \Exception('No content in OpenRouter response');
        }

        return (string) $content;
    }

    /**
     * Parse JSON response from AI.
     *
     * @param  string  $response
     * @return array<string, mixed>
     */
    private function parseResponse(string $response): array
    {
        $response = trim($response);

        // Remove markdown code blocks if present
        if (str_starts_with($response, '```json')) {
            $response = substr($response, 7);
        }
        if (str_starts_with($response, '```')) {
            $response = substr($response, 3);
        }
        if (str_ends_with($response, '```')) {
            $response = substr($response, 0, -3);
        }

        $response = trim($response);

        $parsed = json_decode($response, true);

        if (! is_array($parsed)) {
            Log::warning('Failed to parse AI insights response', [
                'response' => $response,
            ]);

            return [];
        }

        return $parsed;
    }

    /**
     * Extract readable category name from "id|name" format.
     */
    private function extractCategoryName(string $categoryKey): string
    {
        if (strpos($categoryKey, '|') !== false) {
            [, $name] = explode('|', $categoryKey, 2);

            return trim($name);
        }

        return trim($categoryKey);
    }

    /**
     * Format seconds to human-readable duration.
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds === 1 ? '1 second' : "{$seconds} seconds";
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];

        if ($hours > 0) {
            $parts[] = $hours === 1 ? '1 hour' : "{$hours} hours";
        }

        if ($minutes > 0) {
            $parts[] = $minutes === 1 ? '1 minute' : "{$minutes} minutes";
        }

        return implode(' ', $parts) ?: '0 seconds';
    }
}
