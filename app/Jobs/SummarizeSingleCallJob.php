<?php

namespace App\Jobs;

use App\Models\Call;
use App\Jobs\CategorizeSingleCallJob;
use App\Repositories\AiSettingsRepository;
use App\Services\CallSummaryPromptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SummarizeSingleCallJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;
    public array $backoff = [60, 300, 900];

    public function __construct(public int $callId) {}

    public function handle(AiSettingsRepository $aiSettingsRepo): void
    {
        $call = Call::find($this->callId);

        if (! $call) {
            Log::warning("Call {$this->callId} not found, skipping summarization");
            return;
        }

        if (! $call->transcript_text) {
            Log::info("Call {$this->callId} has no transcript, skipping summarization");
            return;
        }

        if (! empty($call->ai_summary)) {
            Log::info("Call {$this->callId} already has ai_summary, skipping summarization");
            return;
        }

        $aiSettings = $aiSettingsRepo->getActive();

        if (! $aiSettings || ! $aiSettings->enabled) {
            Log::warning("No active AI settings configured, skipping summarization for call {$this->callId}");
            $this->fail(new \Exception('AI settings not configured or disabled'));
            return;
        }

        if (! $aiSettings->api_key) {
            Log::error("AI API key not configured, skipping summarization for call {$this->callId}");
            $this->fail(new \Exception('AI API key not configured'));
            return;
        }

        if (! $aiSettings->categorization_model) {
            Log::error("Categorization model not configured, skipping summarization for call {$this->callId}");
            $this->fail(new \Exception('Categorization model not configured'));
            return;
        }

        $prompt = [
            'system' => CallSummaryPromptService::getSystemPrompt(),
            'user' => $this->buildUserPrompt($call),
            'model_parameters' => [
                'temperature' => 0.3,
                'max_tokens' => 600,
            ],
        ];

        try {
            [$provider, $model] = array_pad(explode('/', $aiSettings->categorization_model, 2), 2, null);

            if ($aiSettings->provider === 'openrouter') {
                $summary = $this->callOpenRouter($aiSettings->api_key, $aiSettings->categorization_model, $prompt);
            } elseif ($provider === 'openai') {
                $summary = $this->callOpenAI($aiSettings->api_key, $model ?? $aiSettings->categorization_model, $prompt);
            } elseif ($provider === 'anthropic') {
                $summary = $this->callAnthropic($aiSettings->api_key, $model ?? $aiSettings->categorization_model, $prompt);
            } else {
                throw new \Exception("Unsupported AI provider: {$aiSettings->provider}");
            }

            $summary = trim((string) $summary);

            if ($summary === '') {
                throw new \Exception('Empty AI summary returned');
            }

            $call->ai_summary = $summary;
            $call->save();

            Log::info("✓ Summarized call {$this->callId} using {$aiSettings->categorization_model}");

        } catch (\Exception $e) {
            Log::error("Failed to summarize call {$this->callId}: {$e->getMessage()}", [
                'call_id' => $this->callId,
                'exception' => get_class($e),
                'model' => $aiSettings->categorization_model ?? 'unknown',
            ]);
            throw $e;
        }
    }

    private function buildUserPrompt(Call $call): string
    {
        $direction = $call->direction ?? 'inbound';
        $status = $call->status ?? 'completed';
        $duration = $this->formatDuration((int) ($call->duration_seconds ?? 0));

        return <<<PROMPT
CALL CONTEXT:
- Direction: {$direction}
- Status: {$status}
- Duration: {$duration}

TRANSCRIPT:
"""
{$call->transcript_text}
"""
PROMPT;
    }

    private function callOpenAI(string $apiKey, string $model, array $prompt): string
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
            'max_tokens' => $prompt['model_parameters']['max_tokens'],
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

    private function callAnthropic(string $apiKey, string $model, array $prompt): string
    {
        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'Content-Type' => 'application/json',
            'anthropic-version' => '2023-06-01',
        ])->timeout(25)->post('https://api.anthropic.com/v1/messages', [
            'model' => $model,
            'max_tokens' => $prompt['model_parameters']['max_tokens'],
            'temperature' => $prompt['model_parameters']['temperature'],
            'system' => $prompt['system'],
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt['user'],
                ],
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

    private function callOpenRouter(string $apiKey, string $model, array $prompt): string
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'HTTP-Referer' => config('app.url'),
            'X-Title' => 'blueHubCloud Call Summarization',
        ])->timeout(30)->post('https://openrouter.ai/api/v1/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $prompt['system']],
                ['role' => 'user', 'content' => $prompt['user']],
            ],
            'temperature' => $prompt['model_parameters']['temperature'],
            'max_tokens' => $prompt['model_parameters']['max_tokens'],
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

    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return "{$hours}h {$minutes}m {$secs}s";
        }

        if ($minutes > 0) {
            return "{$minutes}m {$secs}s";
        }

        return "{$secs}s";
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Job permanently failed for call {$this->callId} after {$this->tries} attempts: {$exception->getMessage()}");
    }
}
