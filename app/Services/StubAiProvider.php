<?php

namespace App\Services;

use App\Contracts\AiProviderContract;
use Illuminate\Support\Facades\Log;

/**
 * Fallback AI provider that returns empty/stub responses.
 *
 * Used when no actual AI provider is configured.
 * Allows the system to run without external AI dependencies.
 */
class StubAiProvider implements AiProviderContract
{
    /**
     * Return a stub response instead of calling external API.
     *
     * @param  string  $prompt
     * @param  array<string, mixed>  $options
     * @return string
     */
    public function generateText(string $prompt, array $options = []): string
    {
        Log::warning('StubAiProvider: Returning empty stub response. Configure a real AI provider in .env');

        // Return empty JSON so the service can parse it safely
        return json_encode([
            'executive_summary' => '',
            'recommendations' => [],
            'risks' => [],
            'opportunities' => [],
        ]);
    }
}
