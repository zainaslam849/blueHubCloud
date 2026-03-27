<?php

namespace Database\Seeders;

use App\Models\AiSetting;
use Illuminate\Database\Seeder;

class AiSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if AI settings already exist
        if (AiSetting::exists()) {
            $this->command->info('AI settings already exist, skipping.');
            return;
        }

        // Disable all previously enabled settings
        AiSetting::where('enabled', true)->update(['enabled' => false]);

        // Create default AI settings
        // NOTE: You MUST set a valid API key in the .env file as OPENROUTER_API_KEY
        // Get it from https://openrouter.ai/keys
        $apiKey = env('OPENROUTER_API_KEY', '');

        if (! $apiKey) {
            $this->command->warn('WARNING: OPENROUTER_API_KEY not set in .env file.');
            $this->command->info('AI summarization and categorization will be disabled.');
            $this->command->info('To enable AI features:');
            $this->command->info('1. Get an API key from https://openrouter.ai/keys');
            $this->command->info('2. Add to .env: OPENROUTER_API_KEY=your_key_here');
            $this->command->info('3. Run: php artisan db:seed --class=AiSettingsSeeder');
        }

        AiSetting::create([
            'provider'                    => 'openrouter',
            'api_key'                     => $apiKey ?: 'CONFIGURE_ME',
            'categorization_model'        => 'anthropic/claude-3-haiku:beta',
            'report_model'                => 'anthropic/claude-3-haiku:beta',
            'categorization_system_prompt' => $this->getCategorizationPrompt(),
            'summary_system_prompt'       => $this->getSummaryPrompt(),
            'enabled'                     => (bool) $apiKey,
        ]);

        $this->command->info('AI settings seeded successfully.');
        if ($apiKey) {
            $this->command->info('AI features are ENABLED and ready to use.');
        } else {
            $this->command->warn('AI features are DISABLED. Configure OPENROUTER_API_KEY to enable.');
        }
    }

    private function getCategorizationPrompt(): string
    {
        return <<<'PROMPT'
You are an expert call categorizer. Analyze the call transcript and categorize it into one of these categories:
- Sales
- Support
- Billing
- Technical Issue
- General Inquiry
- Other

Respond with ONLY the category name, nothing else.
PROMPT;
    }

    private function getSummaryPrompt(): string
    {
        return <<<'PROMPT'
You are an expert call summarizer. Create a concise 2-3 sentence summary of this call that captures the key points and outcomes.

Summary:
PROMPT;
    }
}
