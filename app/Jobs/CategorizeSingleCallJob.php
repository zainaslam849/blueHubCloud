<?php

namespace App\Jobs;

use App\Models\Call;
use App\Repositories\AiSettingsRepository;
use App\Services\CallCategorizationPromptService;
use App\Services\CallCategorizationPersistenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CategorizeSingleCallJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 30;
    public $backoff = [60, 300, 900]; // 1min, 5min, 15min

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $callId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(AiSettingsRepository $aiSettingsRepo): void
    {
        $call = Call::find($this->callId);

        if (!$call) {
            Log::warning("Call {$this->callId} not found, skipping categorization");
            return;
        }

        // Skip if no transcript
        if (!$call->transcript_text) {
            Log::info("Call {$this->callId} has no transcript, skipping categorization");
            return;
        }

        // Skip if already categorized
        if ($call->category_id) {
            Log::info("Call {$this->callId} already categorized as category_id={$call->category_id}");
            return;
        }

        // Get active AI settings from database
        $aiSettings = $aiSettingsRepo->getActive();

        if (!$aiSettings || !$aiSettings->enabled) {
            Log::warning("No active AI settings configured, skipping categorization for call {$this->callId}");
            $this->fail(new \Exception('AI settings not configured or disabled'));
            return;
        }

        if (!$aiSettings->api_key) {
            Log::error("AI API key not configured, skipping categorization for call {$this->callId}");
            $this->fail(new \Exception('AI API key not configured'));
            return;
        }

        // Build AI prompt
        $prompt = CallCategorizationPromptService::buildPromptObject(
            transcriptText: $call->transcript_text,
            direction: $call->direction ?? 'inbound',
            status: $call->status ?? 'completed',
            duration: $call->duration_seconds ?? 0,
            isAfterHours: $this->isAfterHours($call),
            companyId: $call->company_id
        );

        $normalizedTranscript = preg_replace('/\s+/', ' ', trim((string) $call->transcript_text));
        $transcriptPreview = mb_substr((string) $normalizedTranscript, 0, 220);

        Log::info('CategorizeSingleCallJob: prompt built with transcript payload', [
            'call_id' => $this->callId,
            'company_id' => $call->company_id,
            'provider' => $aiSettings->provider ?? null,
            'model' => $aiSettings->categorization_model ?? null,
            'direction' => $call->direction ?? 'inbound',
            'status' => $call->status ?? 'completed',
            'duration_seconds' => (int) ($call->duration_seconds ?? 0),
            'transcript_chars' => mb_strlen((string) $call->transcript_text),
            'transcript_words' => str_word_count((string) $call->transcript_text),
            'transcript_sha1' => sha1((string) $call->transcript_text),
            'transcript_preview' => $transcriptPreview,
            'prompt_system_chars' => mb_strlen((string) ($prompt['system'] ?? '')),
            'prompt_user_chars' => mb_strlen((string) ($prompt['user'] ?? '')),
        ]);

        try {
            // Parse provider and model from categorization_model (format: "openai/gpt-4o-mini")
            [$provider, $model] = explode('/', $aiSettings->categorization_model, 2);

            // OpenRouter uses the full model string (openai/gpt-4o-mini) as the model ID
            if ($aiSettings->provider === 'openrouter') {
                $result = $this->callOpenRouter($aiSettings->api_key, $aiSettings->categorization_model, $prompt);
            } elseif ($provider === 'openai') {
                $result = $this->callOpenAI($aiSettings->api_key, $model, $prompt);
            } elseif ($provider === 'anthropic') {
                $result = $this->callAnthropic($aiSettings->api_key, $model, $prompt);
            } else {
                throw new \Exception("Unsupported AI provider: {$aiSettings->provider}");
            }

            Log::info('CategorizeSingleCallJob: raw AI categorization response', [
                'call_id' => $this->callId,
                'company_id' => $call->company_id,
                'category_raw' => $result['category'] ?? null,
                'sub_category_raw' => $result['sub_category'] ?? null,
                'confidence_raw' => $result['confidence'] ?? null,
                'response_keys' => array_keys($result),
            ]);

            $validation = CallCategorizationPromptService::validateCategorization(
                $result,
                $call->company_id
            );

            Log::info('CategorizeSingleCallJob: validation outcome', [
                'call_id' => $this->callId,
                'company_id' => $call->company_id,
                'valid' => $validation['valid'] ?? false,
                'validation_error' => $validation['error'] ?? null,
                'category_validated' => $validation['category_name'] ?? null,
                'sub_category_validated' => $validation['sub_category_name'] ?? null,
                'confidence_validated' => $validation['confidence'] ?? null,
            ]);

            if (! $validation['valid']) {
                $call->category_id = null;
                $call->sub_category_id = null;
                $call->sub_category_label = null;
                $call->category_source = null;
                $call->category_confidence = null;
                $call->categorized_at = null;
                $call->ai_category_status = 'not_generated';
                $call->save();

                Log::warning('AI categorization invalid; leaving call uncategorized for manual retry', [
                    'call_id' => $this->callId,
                    'company_id' => $call->company_id,
                    'category' => $result['category'] ?? null,
                    'sub_category' => $result['sub_category'] ?? null,
                    'error' => $validation['error'] ?? null,
                ]);

                return;
            } else {
                // Persist categorization to database
                $persistResult = CallCategorizationPersistenceService::persistCategorization(
                    callId: $call->id,
                    categoryName: $validation['category_name'] ?? $result['category'],
                    subCategoryName: $validation['sub_category_name'] ?? ($result['sub_category'] ?? null),
                    confidence: (float) ($result['confidence'] ?? 0)
                );
            }

            if ($persistResult['success']) {
                $persistedCall = $persistResult['call'] ?? $call->fresh();
                $call->ai_category_status = $persistedCall?->category_id ? 'completed' : 'not_generated';
                $call->save();

                $logCategory = $validation['category_name'] ?? ($result['category'] ?? 'Unknown');
                $logMsg = "✓ Categorized call {$this->callId} as '{$logCategory}' using {$aiSettings->categorization_model}";
                if ($persistResult['fallback_used']) {
                    $logMsg .= " (fallback: {$persistResult['reason']})";
                }
                Log::info($logMsg, [
                    'call_id' => $this->callId,
                    'category' => $logCategory,
                    'confidence' => $result['confidence'] ?? null,
                    'model' => $aiSettings->categorization_model,
                    'fallback_used' => $persistResult['fallback_used'],
                    'persisted_category_id' => $persistedCall?->category_id,
                    'persisted_sub_category_id' => $persistedCall?->sub_category_id,
                    'ai_category_status' => $call->ai_category_status,
                ]);

                if (strcasecmp((string) $logCategory, 'General') === 0) {
                    Log::warning('CategorizeSingleCallJob: AI assigned General category', [
                        'call_id' => $this->callId,
                        'company_id' => $call->company_id,
                        'category_raw' => $result['category'] ?? null,
                        'confidence_raw' => $result['confidence'] ?? null,
                        'transcript_sha1' => sha1((string) $call->transcript_text),
                        'transcript_preview' => $transcriptPreview,
                    ]);
                }
            } else {
                throw new \Exception("Failed to persist categorization");
            }

        } catch (\Exception $e) {
            if ($this->isOpenRouterCreditLimitError($e)) {
                $call->ai_category_status = 'credit_exhausted';
                $call->save();

                Log::warning("Skipping categorization for call {$this->callId} due to OpenRouter credit/token limit", [
                    'call_id' => $this->callId,
                    'model' => $aiSettings->categorization_model ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            Log::error("Failed to categorize call {$this->callId}: {$e->getMessage()}", [
                'call_id' => $this->callId,
                'exception' => get_class($e),
                'model' => $aiSettings->categorization_model ?? 'unknown',
            ]);
            throw $e;
        }
    }

    /**
     * Call OpenAI API for categorization.
     */
    private function callOpenAI(string $apiKey, string $model, array $prompt): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(25)->post('https://api.openai.com/v1/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $prompt['system']],
                ['role' => 'user', 'content' => $prompt['user']],
            ],
            'temperature' => $prompt['model_parameters']['temperature'],
            // max_tokens intentionally omitted — let the model return a complete response
            'response_format' => ['type' => 'json_object'],
        ]);

        if (!$response->successful()) {
            throw new \Exception("OpenAI API failed ({$response->status()}): " . $response->body());
        }

        $content = $response->json()['choices'][0]['message']['content'] ?? null;
        
        if (!$content) {
            throw new \Exception("No content in OpenAI response");
        }

        $result = json_decode($content, true);

        if (!isset($result['category']) || !isset($result['confidence'])) {
            throw new \Exception("Invalid AI response format: " . $content);
        }

        return $result;
    }

    /**
     * Call Anthropic (Claude) API for categorization.
     */
    private function callAnthropic(string $apiKey, string $model, array $prompt): array
    {
        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'Content-Type' => 'application/json',
            'anthropic-version' => '2023-06-01',
        ])->timeout(25)->post('https://api.anthropic.com/v1/messages', [
            'model' => $model,
            'max_tokens' => 4096,
            'temperature' => $prompt['model_parameters']['temperature'],
            'system' => $prompt['system'],
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt['user'] . "\n\nRespond ONLY with valid JSON. No other text."
                ],
            ],
        ]);

        if (!$response->successful()) {
            throw new \Exception("Anthropic API failed ({$response->status()}): " . $response->body());
        }

        $content = $response->json()['content'][0]['text'] ?? null;
        
        if (!$content) {
            throw new \Exception("No content in Anthropic response");
        }

        $result = json_decode($content, true);

        if (!isset($result['category']) || !isset($result['confidence'])) {
            throw new \Exception("Invalid AI response format: " . $content);
        }

        return $result;
    }

    /**
     * Call OpenRouter API for AI categorization.
     * OpenRouter is a unified gateway for multiple LLM providers.
     * 
     * @param string $apiKey OpenRouter API key
     * @param string $model Full model identifier (e.g., "openai/gpt-4o-mini")
     * @param array $prompt Structured prompt with system/user messages
     * @return array Parsed AI response with category and confidence
     */
    private function callOpenRouter(string $apiKey, string $model, array $prompt): array
    {
        $headers = [
            'Authorization' => "Bearer {$apiKey}",
            'HTTP-Referer' => config('app.url'),
            'X-Title' => 'blueHubCloud Call Categorization',
        ];

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $prompt['system']],
                ['role' => 'user', 'content' => $prompt['user']]
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.3,
            // max_tokens intentionally omitted — let the model return a complete response
        ];

        $response = Http::withHeaders($headers)
            ->timeout(30)
            ->post('https://openrouter.ai/api/v1/chat/completions', $payload);

        if (!$response->successful()) {
            throw new \Exception("OpenRouter API failed ({$response->status()}): " . $response->body());
        }

        $content = $response->json()['choices'][0]['message']['content'] ?? null;
        
        if (!$content) {
            throw new \Exception("No content in OpenRouter response");
        }

        $result = json_decode($content, true);

        if (!isset($result['category']) || !isset($result['confidence'])) {
            throw new \Exception("Invalid AI response format: " . $content);
        }

        return $result;
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

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Job permanently failed for call {$this->callId} after {$this->tries} attempts: {$exception->getMessage()}");

        // Mark call as not_generated so it won't be requeued in normal pipeline
        // Admin can manually regenerate via button/admin UI
        $call = Call::find($this->callId);
        if ($call) {
            $call->category_id = null;
            $call->sub_category_id = null;
            $call->sub_category_label = null;
            $call->category_source = null;
            $call->category_confidence = null;
            $call->categorized_at = null;
            $call->ai_category_status = 'not_generated';
            $call->save();
            Log::info("Marked call {$this->callId} with ai_category_status='not_generated' for manual regeneration", [
                'call_id' => $this->callId,
                'exception' => get_class($exception),
            ]);
        }
    }

    /**
     * Determine if call was after business hours.
     */
    private function isAfterHours(Call $call): bool
    {
        if (!$call->started_at) {
            return false;
        }

        $hour = $call->started_at->hour;
        
        // Business hours: 9 AM to 5 PM
        return $hour < 9 || $hour >= 17;
    }
}
