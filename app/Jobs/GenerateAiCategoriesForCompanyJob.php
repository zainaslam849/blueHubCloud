<?php

namespace App\Jobs;

use App\Models\CallCategory;
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
        public int $rangeDays = 30
    ) {}

    public function handle(
        AiSettingsRepository $aiSettingsRepo,
        AiCategoryGenerationService $generationService
    ): void {
        $aiSettings = $aiSettingsRepo->getActive();

        if (! $aiSettings || ! $aiSettings->enabled) {
            Log::warning('AI settings not configured or disabled for category generation', [
                'company_id' => $this->companyId,
            ]);
            return;
        }

        if (! $aiSettings->api_key) {
            Log::error('AI API key not configured for category generation', [
                'company_id' => $this->companyId,
            ]);
            return;
        }

        if (! $aiSettings->categorization_model) {
            Log::error('Categorization model not configured for category generation', [
                'company_id' => $this->companyId,
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
                throw new \Exception('AI category generation response missing categories array');
            }

            DB::transaction(function () use ($categories, $aiSettings) {
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
        } catch (\Exception $e) {
            Log::error('Failed to generate AI categories', [
                'company_id' => $this->companyId,
                'model' => $aiSettings->categorization_model ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
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
            'max_tokens' => 1200,
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
            'max_tokens' => 1200,
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
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'HTTP-Referer' => config('app.url'),
            'X-Title' => 'blueHubCloud Category Generation',
        ])->timeout(45)->post('https://openrouter.ai/api/v1/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.2,
            'max_tokens' => 1200,
        ]);

        if (! $response->successful()) {
            throw new \Exception("OpenRouter API failed ({$response->status()}): " . $response->body());
        }

        $content = $response->json()['choices'][0]['message']['content'] ?? null;
        if (! $content) {
            throw new \Exception('No content in OpenRouter response');
        }

        return $content;
    }

    private function parseJsonResponse(string $response): array
    {
        $response = trim($response);

        if (str_starts_with($response, '```json')) {
            $response = substr($response, 7);
        }
        if (str_starts_with($response, '```')) {
            $response = substr($response, 3);
        }
        if (str_ends_with($response, '```')) {
            $response = substr($response, 0, -3);
        }

        $parsed = json_decode(trim($response), true);
        if (! is_array($parsed)) {
            throw new \Exception('Failed to parse AI category JSON response');
        }

        return $parsed;
    }
}
