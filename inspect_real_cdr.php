<?php
/**
 * Try multiple CDR queries to find actual data
 * Run: php inspect_real_cdr.php
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CompanyPbxAccount;
use App\Services\PbxwareClient;

echo "╔════════════════════════════════════════════════════════╗\n";
echo "║          CDR DATA DISCOVERY                            ║\n";
echo "╚════════════════════════════════════════════════════════╝\n\n";

$account = CompanyPbxAccount::where('status', 'active')->first();
if (!$account) {
    echo "❌ No active PBX accounts found\n";
    exit(1);
}

$serverId = '87';
echo "✓ Using server ID: $serverId\n";
echo "✓ Company: {$account->company->name}\n\n";

$client = new PbxwareClient();

// Try multiple approaches
$approaches = [
    [
        'name' => 'Last 30 days, Status=8 (all)',
        'params' => [
            'server' => $serverId,
            'start' => now()->subDays(30)->format('Y-m-d'),
            'end' => now()->format('Y-m-d'),
            'status' => '8',
        ]
    ],
    [
        'name' => 'Last 30 days, Status=4 (answered)',
        'params' => [
            'server' => $serverId,
            'start' => now()->subDays(30)->format('Y-m-d'),
            'end' => now()->format('Y-m-d'),
            'status' => '4',
        ]
    ],
    [
        'name' => 'Last 60 days, no status filter',
        'params' => [
            'server' => $serverId,
            'start' => now()->subDays(60)->format('Y-m-d'),
            'end' => now()->format('Y-m-d'),
        ]
    ],
];

$foundData = false;

foreach ($approaches as $approach) {
    echo "Trying: {$approach['name']}\n";
    echo "Params: " . json_encode($approach['params']) . "\n";
    
    try {
        $result = $client->fetchCdrRecords($approach['params']);
        
        if (is_array($result) && isset($result['csv'])) {
            $count = count($result['csv']);
            echo "✓ Found $count records!\n\n";
            
            if ($count > 0) {
                $headers = $result['header'] ?? [];
                $firstRow = $result['csv'][0];
                
                echo "╔════════════════════════════════════════════════════════╗\n";
                echo "║          CDR COLUMN STRUCTURE                          ║\n";
                echo "╚════════════════════════════════════════════════════════╝\n\n";

                echo "Total Columns: " . count($headers) . "\n\n";

                foreach ($headers as $idx => $name) {
                    $value = $firstRow[$idx] ?? null;
                    $displayValue = $value;
                    if (is_string($displayValue) && strlen($displayValue) > 50) {
                        $displayValue = substr($displayValue, 0, 50) . '...';
                    }
                    
                    echo "[$idx] $name\n";
                    echo "     Value: " . json_encode($displayValue) . "\n";
                    echo "\n";
                }

                $foundData = true;
                break;
            }
        } else {
            echo "✗ No data\n\n";
        }
    } catch (\Exception $e) {
        echo "✗ Error: " . substr($e->getMessage(), 0, 100) . "\n\n";
    }
}

if (!$foundData) {
    echo "❌ Could not find any CDR records with any approach\n\n";
    echo "This might mean:\n";
    echo "1. Your account/server has no call history in PBX\n";
    echo "2. The calls are older than 60 days\n";
    echo "3. Different status codes are needed\n\n";
    echo "Try:\n";
    echo "1. Check if you have calls in the PBX system\n";
    echo "2. Verify the server ID is correct\n";
    echo "3. Contact PBXware support for available date ranges\n";
} else {
    echo "✓ Please review the columns above and tell me:\n";
    echo "  - Which column [index] has the answering extension?\n";
    echo "  - Which column [index] has the ring group/queue?\n";
    echo "  - Which column [index] has the caller extension?\n";
    echo "  - Any other relevant columns?\n\n";
    echo "Reply with the indices and I'll update the ingest job.\n";
}
?>
