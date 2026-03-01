<?php
/**
 * Fetch REAL CDR data and show all columns
 * This will help identify which columns contain extension, ring_group, queue, dept
 * Run: php inspect_real_cdr.php
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CompanyPbxAccount;
use App\Services\PbxwareClient;
use Illuminate\Support\Facades\Log;

echo "╔════════════════════════════════════════════════════════╗\n";
echo "║          REAL CDR COLUMN INSPECTOR                     ║\n";
echo "╚════════════════════════════════════════════════════════╝\n\n";

// Get first active PBX account
$account = CompanyPbxAccount::where('status', 'active')->first();
if (!$account) {
    echo "❌ No active PBX accounts found\n";
    exit(1);
}

$serverId = $account->server_id;
echo "✓ Using server ID: $serverId\n";
echo "✓ Company: {$account->company->name}\n\n";

// Create real client (not mock)
$client = new PbxwareClient();

$now = now();
$params = [
    'server' => $serverId,
    'start' => $now->clone()->subDays(7)->format('Y-m-d H:i:s'),
    'end' => $now->format('Y-m-d H:i:s'),
    'status' => '8', // All calls
];

echo "Fetching CDR records from PBX...\n";
echo "Date range: " . $params['start'] . " to " . $params['end'] . "\n\n";

try {
    $result = $client->fetchCdrRecords($params);
    
    if (!is_array($result) || !isset($result['csv']) || count($result['csv']) === 0) {
        echo "❌ No CDR records returned\n";
        exit(1);
    }

    $headers = $result['header'] ?? [];
    $csvRows = $result['csv'];

    echo "╔════════════════════════════════════════════════════════╗\n";
    echo "║          CDR COLUMN HEADERS                            ║\n";
    echo "╚════════════════════════════════════════════════════════╝\n\n";

    echo "Total columns: " . count($headers) . "\n";
    echo "Total rows: " . count($csvRows) . "\n\n";

    foreach ($headers as $idx => $name) {
        echo "  [$idx] => $name\n";
    }

    echo "\n╔════════════════════════════════════════════════════════╗\n";
    echo "║          FIRST SAMPLE ROW DATA                         ║\n";
    echo "╚════════════════════════════════════════════════════════╝\n\n";

    if (count($csvRows) > 0) {
        $firstRow = $csvRows[0];
        foreach ($firstRow as $idx => $value) {
            $header = $headers[$idx] ?? "col_$idx";
            $displayValue = $value;
            
            // Truncate long values
            if (is_string($displayValue) && strlen($displayValue) > 60) {
                $displayValue = substr($displayValue, 0, 60) . '...';
            }
            
            echo "  [$idx] $header\n";
            echo "       => " . json_encode($displayValue) . "\n\n";
        }
    }

    echo "╔════════════════════════════════════════════════════════╗\n";
    echo "║          NEXT STEP: IDENTIFY COLUMNS                  ║\n";
    echo "╚════════════════════════════════════════════════════════╝\n\n";

    echo "Please identify which columns (indices) contain:\n\n";
    echo "1. Answering Extension: Column [?]  (e.g., 4301, 4302, etc)\n";
    echo "2. Ring Group / Queue:  Column [?]  (e.g., Sales, Support, etc)\n";
    echo "3. Caller Extension:    Column [?]  (e.g., external number, caller ID)\n";
    echo "4. Department:          Column [?]  (optional)\n\n";

    echo "Once you identify these, I'll update the ingest job automatically.\n";
    echo "Reply with the column indices and I'll wire them in.\n\n";

} catch (\Exception $e) {
    echo "❌ Error: {$e->getMessage()}\n";
    echo "\nThis usually means:\n";
    echo "- Network connection issue to PBX\n";
    echo "- Invalid credentials\n";
    echo "- Invalid server ID\n";
    exit(1);
}
?>
