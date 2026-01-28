<?php
// scripts/call_controller_index.php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;

// create request; leave company_id empty so controller falls back to first company
$request = Request::create('/admin/api/weekly-call-reports', 'GET', []);

$service = $app->make(App\Services\WeeklyCallReportQueryService::class);
$controller = $app->make(App\Http\Controllers\Admin\AdminWeeklyCallReportsController::class);

// Determine resolved company id used by controller fallback
$first = App\Models\WeeklyCallReport::select('company_id')->first();
$resolvedCompanyId = $first?->company_id ?? null;
echo "resolved_company_id: ";
echo json_encode($resolvedCompanyId) . PHP_EOL;

$raw = $service->getByCompanyId((int) ($resolvedCompanyId ?? 0));
echo "\nservice raw output:\n";
echo json_encode($raw->toArray(), JSON_PRETTY_PRINT) . PHP_EOL;

$response = $controller->index($request, $service);

if (is_object($response) && method_exists($response, 'getContent')) {
    echo "\ncontroller response:\n";
    echo $response->getContent();
} else {
    echo json_encode($response, JSON_PRETTY_PRINT);
}
