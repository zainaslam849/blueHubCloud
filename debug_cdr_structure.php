<?php
/**
 * Debug CDR structure - see what data the real PBX API returns
 * Run: php debug_cdr_structure.php
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Log;
use App\Services\Pbx\PbxClientResolver;
use App\Models\CompanyPbxAccount;

echo "╔════════════════════════════════════════════════╗\n";
echo "║     CDR STRUCTURE DEBUG                        ║\n";
echo "╚════════════════════════════════════════════════╝\n\n";

$client = PbxClientResolver::resolve();

// Get first active PBX account
$account = CompanyPbxAccount::where('status', 'active')->first();
if (!$account) {
    echo "❌ No active PBX accounts found\n";
    exit(1);
}

$serverId = $account->server_id;
echo "✓ Using server: $serverId\n";
echo "✓ Company ID: {$account->company_id}\n\n";

// Fetch CDR for last 7 days
$now = now();
$params = [
    'server' => $serverId,
    'start' => $now->clone()->subDays(7)->format('Y-m-d H:i:s'),
    'end' => $now->format('Y-m-d H:i:s'),
    'status' => '4',
];

echo "Fetching CDR with params:\n";
echo json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

try {
    $result = method_exists($client, 'fetchCdrRecords')
        ? $client->fetchCdrRecords($params)
        : (method_exists($client, 'fetchAction') 
            ? $client->fetchAction('pbxware.cdr.download', $params) 
            : []);

    echo "Response Type: " . gettype($result) . "\n";
    
    if (is_array($result)) {
        echo "\n═══ HEADERS ═══\n";
        if (isset($result['header'])) {
            foreach ($result['header'] as $idx => $colName) {
                echo "[$idx] => $colName\n";
            }
        } else {
            echo "(No header provided)\n";
        }

        echo "\n═══ FIRST ROW SAMPLE ═══\n";
        if (isset($result['csv']) && is_array($result['csv']) && count($result['csv']) > 0) {
            $firstRow = $result['csv'][0];
            echo "Total Columns: " . count($firstRow) . "\n";
            foreach ($firstRow as $idx => $value) {
                $header = $result['header'][$idx] ?? "col$idx";
                $displayValue = strlen((string)$value) > 100 
                    ? substr((string)$value, 0, 100) . '...' 
                    : $value;
                echo "[$idx] $header => " . json_encode($displayValue) . "\n";
            }
        } else {
            echo "(No CSV rows in response)\n";
        }

        echo "\n═══ ROW COUNT ═══\n";
        echo "Total rows: " . (isset($result['csv']) ? count($result['csv']) : 0) . "\n";

        echo "\n═══ LOOKING FOR EXTENSION/RING_GROUP COLUMNS ═══\n";
        if (isset($result['header'])) {
            $found = [];
            foreach ($result['header'] as $idx => $colName) {
                $lower = strtolower($colName);
                if (strpos($lower, 'extension') !== false || 
                    strpos($lower, 'ext') !== false ||
                    strpos($lower, 'ring') !== false ||
                    strpos($lower, 'queue') !== false ||
                    strpos($lower, 'department') !== false ||
                    strpos($lower, 'group') !== false) {
                    $found[] = "[$idx] $colName";
                }
            }
            if (count($found) > 0) {
                echo "Found potentially relevant columns:\n";
                foreach ($found as $f) {
                    echo "  $f\n";
                }
            } else {
                echo "⚠ No extension/ring_group/queue/department columns detected\n";
            }
        }
    } else {
        echo "Response:\n";
        echo $result . "\n";
    }

} catch (\Exception $e) {
    echo "❌ Error: {$e->getMessage()}\n";
    exit(1);
}

echo "\n✓ Done. Check logs for detailed response.\n";
?>
