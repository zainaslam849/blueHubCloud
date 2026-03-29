<?php

namespace App\Jobs;

use App\Models\CallCategory;
use App\Models\PipelineRun;
use App\Models\SubCategory;
use App\Repositories\AiSettingsRepository;
use App\Services\AiCategoryGenerationService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenerateAiCategoriesForCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 90;
    public $backoff = [60, 300, 900];

    /**
     * @param  int  $companyId
     * @param  int  $rangeDays
     */
    public function __construct(
        public int $companyId,
        public int $rangeDays = 30,
        public ?int $pipelineRunId = null,
    ) {}

    public function handle(
        AiSettingsRepository $aiSettingsRepo,
        AiCategoryGenerationService $generationService
    ): void {
        $this->markPipelineStageRunning();

        $aiSettings = $aiSettingsRepo->getActive();

        if (! $aiSettings || ! $aiSettings->enabled) {
            Log::warning('AI settings not configured or disabled for category generation', [
                'company_id' => $this->companyId,
            ]);
            $this->markPipelineStageCompleted([
                'skipped' => true,
                'reason' => 'ai_settings_disabled',
            ]);
            return;
        }

        if (! $aiSettings->api_key) {
            Log::error('AI API key not configured for category generation', [
                'company_id' => $this->companyId,
            ]);
            $this->markPipelineStageCompleted([
                'skipped' => true,
                'reason' => 'missing_api_key',
            ]);
            return;
        }

        if (! $aiSettings->categorization_model) {
            Log::error('Categorization model not configured for category generation', [
                'company_id' => $this->companyId,
            ]);
            $this->markPipelineStageCompleted([
                'skipped' => true,
                'reason' => 'missing_model',
            ]);
            return;
        }

        $end = CarbonImmutable::now()->toDateString();
        $start = CarbonImmutable::now()->subDays($this->rangeDays)->toDateString();

        $promptPayload = $generationService->buildPrompt(
            companyId: $this->companyId,
            companyPbxAccountId: null,
            dateRange: [
                'start' => $start,
                'end' => $end,
            ],
            model: $aiSettings->categorization_model
        );

        if (($promptPayload['summary_count'] ?? 0) === 0) {
            Log::info('Skipping AI category generation - no summaries available yet (this is expected on first run)', [
                'company_id' => $this->companyId,
                'date_range' => ['start' => $start, 'end' => $end],
                'tip' => 'Ensure QueueCallsForSummarizationJob completes before running AI generation',
            ]);
            $this->markPipelineStageCompleted([
                'skipped' => true,
                'reason' => 'no_summaries',
                'summary_count' => 0,
            ]);
            return;
        }

        try {
            $responseText = $this->callProvider(
                $aiSettings->provider,
                $aiSettings->api_key,
                $aiSettings->categorization_model,
                $promptPayload['prompt']
            );

            $parsed = $this->parseJsonResponse($responseText);
            $categories = $parsed['categories'] ?? null;

            if (! is_array($categories)) {
                Log::warning('GenerateAiCategoriesForCompanyJob: AI response missing categories array; skipping category refresh', [
                    'company_id' => $this->companyId,
                    'model' => $aiSettings->categorization_model,
                ]);

                $this->markPipelineStageCompleted([
                    'skipped' => true,
                    'reason' => 'missing_categories_array',
                    'summary_count' => (int) ($promptPayload['summary_count'] ?? 0),
                ]);

                return;
            }

            $normalizedCategories = $this->normalizeCategoriesPayload($categories);
            $existingActiveNames = CallCategory::query()
                ->where('company_id', $this->companyId)
                ->where('is_enabled', true)
                ->where('status', 'active')
                ->pluck('name')
                ->filter(fn ($name) => is_string($name) && trim($name) !== '')
                ->map(fn ($name) => trim((string) $name))
                ->values();

            if (empty($normalizedCategories) || $this->isDegenerateCategorySet($normalizedCategories)) {
                Log::warning('GenerateAiCategoriesForCompanyJob: skipped category refresh due to unusable AI output', [
                    'company_id' => $this->companyId,
                    'existing_active_categories' => $existingActiveNames->all(),
                    'generated_categories' => array_column($normalizedCategories, 'name'),
                ]);

                $this->markPipelineStageCompleted([
                    'skipped' => true,
                    'reason' => empty($normalizedCategories) ? 'empty_category_set' : 'degenerate_category_set',
                    'generated_count' => count($normalizedCategories),
                    'summary_count' => (int) ($promptPayload['summary_count'] ?? 0),
                ]);

                return;
            }

            DB::transaction(function () use ($normalizedCategories, $aiSettings) {
                $aiCategoryIds = CallCategory::query()
                    ->where('company_id', $this->companyId)
                    ->where('source', 'ai')
                    ->where('status', 'active')
                    ->pluck('id');

                if ($aiCategoryIds->isNotEmpty()) {
                    CallCategory::query()
                        ->whereIn('id', $aiCategoryIds)
                        ->update([
                            'status' => 'archived',
                            'is_enabled' => false,
                        ]);

                    SubCategory::query()
                        ->where('source', 'ai')
                        ->where('status', 'active')
                        ->whereIn('category_id', $aiCategoryIds)
                        ->update([
                            'status' => 'archived',
                            'is_enabled' => false,
                        ]);
                }

                foreach ($normalizedCategories as $categoryData) {
                    $categoryName = trim((string) ($categoryData['name'] ?? ''));
                    if ($categoryName === '') {
                        continue;
                    }

                    $existing = CallCategory::query()
                        ->where('company_id', $this->companyId)
                        ->where('name', $categoryName)
                        ->first();

                    if ($existing && $existing->source === 'admin') {
                        continue;
                    }

                    if ($existing) {
                        $existing->fill([
                            'source' => 'ai',
                            'status' => 'active',
                            'is_enabled' => true,
                            'generated_at' => now(),
                            'generated_by_model' => $aiSettings->categorization_model,
                        ])->save();
                        $category = $existing;
                    } else {
                        $category = CallCategory::create([
                            'company_id' => $this->companyId,
                            'name' => $categoryName,
                            'description' => null,
                            'is_enabled' => true,
                            'source' => 'ai',
                            'status' => 'active',
                            'generated_at' => now(),
                            'generated_by_model' => $aiSettings->categorization_model,
                        ]);
                    }

                    $subcategories = $categoryData['sub_categories'] ?? $categoryData['subcategories'] ?? [];
                    if (! is_array($subcategories)) {
                        continue;
                    }

                    foreach ($subcategories as $subNameRaw) {
                        $subName = trim((string) $subNameRaw);
                        if ($subName === '') {
                            continue;
                        }

                        $subCategory = SubCategory::query()
                            ->where('category_id', $category->id)
                            ->where('name', $subName)
                            ->first();

                        if ($subCategory && $subCategory->source === 'admin') {
                            continue;
                        }

                        if ($subCategory) {
                            $subCategory->fill([
                                'source' => 'ai',
                                'status' => 'active',
                                'is_enabled' => true,
                            ])->save();
                        } else {
                            SubCategory::create([
                                'category_id' => $category->id,
                                'name' => $subName,
                                'description' => null,
                                'is_enabled' => true,
                                'source' => 'ai',
                                'status' => 'active',
                            ]);
                        }
                    }
                }
            });

            Log::info('AI categories generated and applied', [
                'company_id' => $this->companyId,
                'model' => $aiSettings->categorization_model,
                'range_days' => $this->rangeDays,
            ]);
            $this->markPipelineStageCompleted([
                'generated' => true,
                'model' => $aiSettings->categorization_model,
                'range_days' => $this->rangeDays,
                'summary_count' => (int) ($promptPayload['summary_count'] ?? 0),
            ]);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Failed to parse AI category JSON response')) {
                Log::warning('GenerateAiCategoriesForCompanyJob: unparseable AI output; skipping category refresh', [
                    'company_id' => $this->companyId,
                    'model' => $aiSettings->categorization_model ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);

                $this->markPipelineStageCompleted([
                    'skipped' => true,
                    'reason' => 'unparseable_ai_output',
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            if ($this->isOpenRouterCreditLimitError($e)) {
                Log::warning('Skipping AI category generation due to OpenRouter credit/token limit', [
                    'company_id' => $this->companyId,
                    'model' => $aiSettings->categorization_model ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);

                $this->markPipelineStageCompleted([
                    'skipped' => true,
                    'reason' => 'credit_limit',
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            Log::error('Failed to generate AI categories', [
                'company_id' => $this->companyId,
                'model' => $aiSettings->categorization_model ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            $this->markPipelineStageFailed($e->getMessage());
            throw $e;
        }
    }

    private function markPipelineStageRunning(): void
    {
        if (! $this->pipelineRunId) {
            return;
        }

        $run = PipelineRun::query()->find($this->pipelineRunId);
        if (! $run) {
            return;
        }

        $run->markRunning('category_generation');
        $run->upsertStage('category_generation', [
            'status' => 'running',
            'error_message' => null,
            'started_at' => now(),
        ]);
    }

    private function markPipelineStageCompleted(array $metrics = []): void
    {
        if (! $this->pipelineRunId) {
            return;
        }

        $run = PipelineRun::query()->find($this->pipelineRunId);
        if (! $run) {
            return;
        }

        $run->upsertStage('category_generation', [
            'status' => 'completed',
            'metrics' => $metrics,
            'finished_at' => now(),
        ]);
        $run->markQueued('call_categorization');
    }

    private function markPipelineStageFailed(string $message): void
    {
        if (! $this->pipelineRunId) {
            return;
        }

        $run = PipelineRun::query()->find($this->pipelineRunId);
        if (! $run) {
            return;
        }

        $run->markFailed('category_generation', $message);
        $run->upsertStage('category_generation', [
            'status' => 'failed',
            'error_message' => $message,
            'finished_at' => now(),
        ]);
    }

    private function callProvider(string $provider, string $apiKey, string $model, string $prompt): string
    {
        if ($provider === 'openrouter') {
            return $this->callOpenRouter($apiKey, $model, $prompt);
        }

        [$modelProvider, $modelName] = $this->splitModel($model);

        if ($modelProvider === 'openai') {
            return $this->callOpenAI($apiKey, $modelName, $prompt);
        }

        if ($modelProvider === 'anthropic') {
            return $this->callAnthropic($apiKey, $modelName, $prompt);
        }

        throw new \Exception("Unsupported AI provider: {$provider}");
    }

    private function splitModel(string $model): array
    {
        if (str_contains($model, '/')) {
            return explode('/', $model, 2);
        }

        return ['openai', $model];
    }

    private function callOpenAI(string $apiKey, string $model, string $prompt): string
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(40)->post('https://api.openai.com/v1/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'Return STRICT JSON only.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
            // max_tokens intentionally omitted — let the model return a complete response
            'response_format' => ['type' => 'json_object'],
        ]);

        if (! $response->successful()) {
            throw new \Exception("OpenAI API failed ({$response->status()}): " . $response->body());
        }

        $content = $response->json()['choices'][0]['message']['content'] ?? null;
        if (! $content) {
            throw new \Exception('No content in OpenAI response');
        }

        return $content;
    }

    private function callAnthropic(string $apiKey, string $model, string $prompt): string
    {
        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'Content-Type' => 'application/json',
            'anthropic-version' => '2023-06-01',
        ])->timeout(40)->post('https://api.anthropic.com/v1/messages', [
            'model' => $model,
            'max_tokens' => 4096,
            'temperature' => 0.2,
            'system' => 'Return STRICT JSON only.',
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

        return $content;
    }

    private function callOpenRouter(string $apiKey, string $model, string $prompt): string
    {
        $headers = [
            'Authorization' => "Bearer {$apiKey}",
            'HTTP-Referer' => config('app.url'),
            'X-Title' => 'blueHubCloud Category Generation',
        ];

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.2,
            // max_tokens intentionally omitted — let the model return a complete response
        ];

        $response = Http::withHeaders($headers)
            ->timeout(45)
            ->post('https://openrouter.ai/api/v1/chat/completions', $payload);

        if (! $response->successful()) {
            throw new \Exception("OpenRouter API failed ({$response->status()}): " . $response->body());
        }

        $content = $response->json()['choices'][0]['message']['content'] ?? null;
        if (! $content) {
            throw new \Exception('No content in OpenRouter response');
        }

        return $content;
    }

    private function extractAffordableTokenLimit(string $body): ?int
    {
        if (preg_match('/can only afford\s+(\d+)\.?/i', $body, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function isOpenRouterCreditLimitError(\Throwable $exception): bool
    {
        $message = $exception->getMessage();
        $lower = strtolower($message);

        return str_contains($message, 'OpenRouter API failed (402)')
            || (str_contains($lower, 'more credits') && str_contains($lower, 'max_tokens'));
    }

    private function parseJsonResponse(string $response): array
    {
        $response = trim($response);

        // Strip markdown fences
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
        if (is_array($parsed)) {
            return $parsed;
        }

        // Fallback: extract the JSON object between the first { and last } in case
        // the model prepended/appended prose or the response was lightly truncated.
        $first = strpos($response, '{');
        $last  = strrpos($response, '}');
        if ($first !== false && $last !== false && $last > $first) {
            $extracted = substr($response, $first, $last - $first + 1);
            $parsed = json_decode($extracted, true);
            if (is_array($parsed)) {
                Log::warning('GenerateAiCategoriesForCompanyJob: parsed JSON via extraction fallback', [
                    'company_id' => $this->companyId,
                    'raw_preview' => mb_substr($response, 0, 300),
                ]);
                return $parsed;
            }
        }

        Log::error('GenerateAiCategoriesForCompanyJob: unparseable AI response', [
            'company_id' => $this->companyId,
            'raw_preview' => mb_substr($response, 0, 500),
            'json_error'  => json_last_error_msg(),
        ]);
        throw new \Exception('Failed to parse AI category JSON response');
    }

    /**
     * @param  array<int, mixed>  $categories
     * @return array<int, array{name:string, sub_categories:array<int, string>}>
     */
    private function normalizeCategoriesPayload(array $categories): array
    {
        $normalized = [];
        $seen = [];

        foreach ($categories as $categoryData) {
            if (! is_array($categoryData)) {
                continue;
            }

            $name = trim((string) ($categoryData['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $key = strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }

            $rawSubcategories = $categoryData['sub_categories'] ?? $categoryData['subcategories'] ?? [];
            $subcategories = [];
            $subSeen = [];

            if (is_array($rawSubcategories)) {
                foreach ($rawSubcategories as $subNameRaw) {
                    $subName = trim((string) $subNameRaw);
                    if ($subName === '') {
                        continue;
                    }

                    $subKey = strtolower($subName);
                    if (isset($subSeen[$subKey])) {
                        continue;
                    }

                    $subSeen[$subKey] = true;
                    $subcategories[] = $subName;
                }
            }

            $normalized[] = [
                'name' => $name,
                'sub_categories' => $subcategories,
            ];
            $seen[$key] = true;
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{name:string, sub_categories:array<int, string>}>  $categories
     */
    private function isDegenerateCategorySet(array $categories): bool
    {
        if (empty($categories)) {
            return true;
        }

        if (count($categories) !== 1) {
            return false;
        }

        return $this->isGenericCategoryName($categories[0]['name'] ?? '');
    }

    private function isGenericCategoryName(string $name): bool
    {
        $value = strtolower(trim($name));

        return in_array($value, ['general', 'other', 'misc', 'miscellaneous', 'uncategorized', 'unknown'], true);
    }
}
