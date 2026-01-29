<?php

namespace App\Contracts;

/**
 * Contract for AI providers.
 *
 * Implementations should support text generation for various tasks.
 */
interface AiProviderContract
{
    /**
     * Generate text based on a prompt.
     *
     * @param  string  $prompt  The input prompt
     * @param  array<string, mixed>  $options  Options (max_tokens, temperature, etc.)
     * @return string  Generated text response
     *
     * @throws \Exception  If generation fails
     */
    public function generateText(string $prompt, array $options = []): string;
}
