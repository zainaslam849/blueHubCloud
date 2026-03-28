<?php

namespace App\Jobs;

use App\Models\CallCategory;
use App\Models\SubCategory;
use App\Repositories\AiSettingsRepository;
use App\Services\AiCategoryGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenerateAiCategoriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    public $backoff = [60, 300, 900];

    /**
     * @param  int  $companyId
     * @param  int|null  $companyPbxAccountId
     * @param  array{start?: string|null, end?: string|null}  $dateRange
     * @param  string  $model
     */
    public function __construct(
        public int $companyId,
        public ?int $companyPbxAccountId,
        public array $dateRange,
        public string $model
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        AiSettingsRepository $aiSettingsRepo,
        AiCategoryGenerationService $generationService
    ): void {
        $aiSettings = $aiSettingsRepo->getActive();

        if (!$aiSettings || !$aiSettings->enabled) {
            Log::warning('AI settings not configured or disabled for category generation', [
                'company_id' => $this->companyId,
            ]);
            return;
        }

        if (!$aiSettings->api_key) {
            Log::error('AI API key not configured for category generation', [
                'company_id' => $this->companyId,
            ]);
            return;
        }

        $promptPayload = $generationService->buildPrompt(
            companyId: $this->companyId,
            companyPbxAccountId: $this->companyPbxAccountId,
            dateRange: $this->dateRange,
            model: $this->model
        );

        if (($promptPayload['summary_count'] ?? 0) === 0) {
            Log::warning('No AI summaries available for category generation', [
                'company_id' => $this->companyId,
                'company_pbx_account_id' => $this->companyPbxAccountId,
                'date_range' => $this->dateRange,
            ]);
            return;
        }

        try {
            $responseText = $this->callProvider(
                $aiSettings->provider,
                $aiSettings->api_key,
                $this->model,
                $promptPayload['prompt']
            );

            $parsed = $this->parseJsonResponse($responseText);
            $categories = $parsed['categories'] ?? null;

            if (!is_array($categories)) {
                throw new \Exception('AI category generation response missing categories array');
            }

            DB::transaction(function () use ($categories) {
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

                foreach ($categories as $categoryData) {
                    $categoryName = trim((string) ($categoryData['name'] ?? ''));
                    if ($categoryName === '') {
                        continue;
                    }

                    $category = CallCategory::query()
                        ->where('company_id', $this->companyId)
                        ->where('name', $categoryName)
                        ->first();

                    if ($category) {
                        if ($category->source === 'admin') {
                            continue;
                        } else {
                            $category->fill([
                                'source' => 'ai',
                                'status' => 'active',
                                'is_enabled' => true,
                                'generated_by_model' => $this->model,
                                'generated_at' => now(),
                            ])->save();
                            $categoryId = $category->id;
                        }
                    } else {
                        $category = CallCategory::create([
                            'company_id' => $this->companyId,
                            'name' => $categoryName,
                            'description' => null,
                            'is_enabled' => true,
                            'source' => 'ai',
                            'status' => 'active',
                            'generated_by_model' => $this->model,
                            'generated_at' => now(),
                        ]);
                        $categoryId = $category->id;
                    }

                    $subcategories = $categoryData['subcategories'] ?? $categoryData['sub_categories'] ?? [];
                    if (!is_array($subcategories)) {
                        continue;
                    }

                    foreach ($subcategories as $subNameRaw) {
                        $subName = trim((string) $subNameRaw);
                        if ($subName === '') {
                            continue;
                        }

                        $subCategory = SubCategory::query()
                            ->where('category_id', $categoryId)
                            ->where('name', $subName)
                            ->first();
                        if ($subCategory) {
                            if ($subCategory->source === 'admin') {
                                continue;
                            }

                            $subCategory->fill([
                                'category_id' => $categoryId,
                                'source' => 'ai',
                                'status' => 'active',
                                'is_enabled' => true,
                            ])->save();
                        } else {
                            SubCategory::create([
                                'category_id' => $categoryId,
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
                'company_pbx_account_id' => $this->companyPbxAccountId,
                'model' => $this->model,
                'date_range' => $this->dateRange,
            ]);
        } catch (\Exception $e) {
            if ($this->isOpenRouterCreditLimitError($e)) {
                Log::warning('Skipping AI category generation due to OpenRouter credit/token limit', [
                    'company_id' => $this->companyId,
                    'company_pbx_account_id' => $this->companyPbxAccountId,
                    'model' => $this->model,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            Log::error('Failed to generate AI categories', [
                'company_id' => $this->companyId,
                'company_pbx_account_id' => $this->companyPbxAccountId,
                'model' => $this->model,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Call configured AI provider to generate categories.
     */
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

        if (!$response->successful()) {
            throw new \Exception("OpenAI API failed ({$response->status()}): " . $response->body());
        }

        $content = $response->json()['choices'][0]['message']['content'] ?? null;
        if (!$content) {
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

        if (!$response->successful()) {
            throw new \Exception("Anthropic API failed ({$response->status()}): " . $response->body());
        }

        $content = $response->json()['content'][0]['text'] ?? null;
        if (!$content) {
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

        if (!$response->successful()) {
            throw new \Exception("OpenRouter API failed ({$response->status()}): " . $response->body());
        }

        $content = $response->json()['choices'][0]['message']['content'] ?? null;
        if (!$content) {
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

        // Fallback: extract JSON between the first { and last }
        $first = strpos($response, '{');
        $last  = strrpos($response, '}');
        if ($first !== false && $last !== false && $last > $first) {
            $extracted = substr($response, $first, $last - $first + 1);
            $parsed = json_decode($extracted, true);
            if (is_array($parsed)) {
                Log::warning('GenerateAiCategoriesJob: parsed JSON via extraction fallback', [
                    'company_id' => $this->companyId,
                    'raw_preview' => mb_substr($response, 0, 300),
                ]);
                return $parsed;
            }
        }

        Log::error('GenerateAiCategoriesJob: unparseable AI response', [
            'company_id' => $this->companyId,
            'raw_preview' => mb_substr($response, 0, 500),
            'json_error'  => json_last_error_msg(),
        ]);
        throw new \Exception('Failed to parse AI category JSON response');
    }
}
