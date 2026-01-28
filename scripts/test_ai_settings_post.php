<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;

$controller = $app->make(App\Http\Controllers\Admin\AdminAiSettingsController::class);

function runTest($payload) {
    global $controller;
    $request = Request::create('/admin/api/ai-settings', 'POST', $payload);
    try {
        $resp = $controller->store($request);
        echo "STATUS: " . $resp->getStatusCode() . PHP_EOL;
        echo $resp->getContent() . PHP_EOL;
    } catch (Throwable $e) {
        echo "EXCEPTION: " . $e->getMessage() . PHP_EOL;
        if (method_exists($e, 'getResponse') && $e->getResponse()) {
            echo $e->getResponse()->getContent() . PHP_EOL;
        }
    }
}

echo "--- VALID SUBMIT (different models) ---\n";
$valid = [
    'provider' => 'openrouter',
    'api_key' => 'sk_test_abc',
    'categorization_model' => 'openai/gpt-4o-mini',
    'report_model' => 'openai/gpt-5.2',
    'enabled' => true,
];
runTest($valid);

echo "\n--- INVALID SUBMIT (same models) ---\n";
$invalid = [
    'provider' => 'openrouter',
    'api_key' => 'sk_test_abc',
    'categorization_model' => 'openai/gpt-4o-mini',
    'report_model' => 'openai/gpt-4o-mini',
    'enabled' => true,
];
runTest($invalid);

echo "\n--- UPDATE SUBMIT (change report_model) ---\n";
$update = [
    'provider' => 'openrouter',
    'api_key' => 'sk_test_def',
    'categorization_model' => 'openai/gpt-4o-mini',
    'report_model' => 'anthropic/claude-3.5-sonnet',
    'enabled' => true,
];
runTest($update);

// Show active
$repo = $app->make(App\Repositories\AiSettingsRepository::class);
$active = $repo->getActive();
echo "\n--- ACTIVE IN DB ---\n";
echo json_encode($active?->toArray() ?? null, JSON_PRETTY_PRINT) . PHP_EOL;
