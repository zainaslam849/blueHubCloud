<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$repo = app(\App\Repositories\AiSettingsRepository::class);
$settings = $repo->getActive();

if ($settings) {
    echo "✓ AI Settings Found\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Provider: {$settings->provider}\n";
    echo "Categorization Model: {$settings->categorization_model}\n";
    echo "Report Model: {$settings->report_model}\n";
    echo "Enabled: " . ($settings->enabled ? '✓ Yes' : '✗ No') . "\n";
    echo "API Key: " . ($settings->api_key ? '✓ Configured (encrypted)' : '✗ Not configured') . "\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    if (!$settings->enabled) {
        echo "⚠ Warning: AI settings are disabled. Enable them in /admin/settings/ai\n";
    } elseif (!$settings->api_key) {
        echo "⚠ Warning: API key not configured. Add it in /admin/settings/ai\n";
    } else {
        echo "✓ Ready for call categorization!\n";
    }
} else {
    echo "✗ No AI settings configured\n\n";
    echo "Please configure AI settings at: /admin/settings/ai\n\n";
    echo "Steps:\n";
    echo "1. Log in to admin dashboard\n";
    echo "2. Navigate to Settings > AI Settings\n";
    echo "3. Select provider (OpenAI or Anthropic)\n";
    echo "4. Add API key\n";
    echo "5. Select models for categorization and reports\n";
    echo "6. Enable the settings\n";
}
