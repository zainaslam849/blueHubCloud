<?php
/**
 * CDR discovery helper.
 *
 * Mirrors ingest behavior (M-d-Y + status=8) and then tries fallbacks
 * to identify which date format/range returns records for this PBX server.
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

$serverId = 87;
if ($serverId === '') {
    echo "❌ Active PBX account has empty server_id\n";
    exit(1);
}

echo "✓ Using server ID: $serverId\n";
echo "✓ Company: {$account->company->name}\n\n";

$client = new PbxwareClient();

// Fast connectivity preflight so we don't wait on repeated cURL timeouts.
$pbxHost = parse_url((string) config('services.pbxware.base_url', ''), PHP_URL_HOST);
if (!is_string($pbxHost) || trim($pbxHost) === '') {
    $pbxHost = 'ip.pbxbluehub.com';
}

$socket = @fsockopen($pbxHost, 443, $errno, $errstr, 5);
if ($socket === false) {
    echo "❌ Network preflight failed: cannot reach {$pbxHost}:443\n";
    echo "   Error {$errno}: {$errstr}\n\n";
    echo "This is a connectivity issue (firewall/routing/VPN), not a CDR mapping issue.\n";
    echo "Fix network access to {$pbxHost}:443, then rerun this script.\n";
    exit(1);
}
fclose($socket);

$today = now();

// First attempt exactly what IngestPbxCallsJob sends (M-d-Y + status=8).
$attempts = [];

foreach ([170, 1, 7, 30, 90, 365, 1825] as $days) {
    $attempts[] = [
        'name' => "Ingest format M-d-Y, last {$days} days",
        'params' => [
            'server' => $serverId,
            'start' => $today->copy()->subDays($days)->format('M-d-Y'),
            'end' => $today->format('M-d-Y'),
            'status' => 8,
        ],
    ];
}

// Fallback formats in case PBX server expects different date strings.
$fallbackFormats = ['Y-m-d', 'm/d/Y', 'd-m-Y'];
foreach ($fallbackFormats as $format) {
    foreach ([30, 365, 1825] as $days) {
        $attempts[] = [
            'name' => "Fallback format {$format}, last {$days} days",
            'params' => [
                'server' => $serverId,
                'start' => $today->copy()->subDays($days)->format($format),
                'end' => $today->format($format),
                'status' => 8,
            ],
        ];
    }
}

$foundData = false;

foreach ($attempts as $attempt) {
    echo "Trying: {$attempt['name']}\n";
    echo "Params: " . json_encode($attempt['params']) . "\n";
    
    try {
        // Use fetchAction directly so we can inspect raw CDR behavior exactly.
        $result = $client->fetchAction('pbxware.cdr.download', $attempt['params']);
        
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

                if ($count > 1) {
                    echo "Second row preview (for sanity):\n";
                    $second = $result['csv'][1];
                    foreach ([2, 3, 6, 7] as $idx) {
                        $name = $headers[$idx] ?? "col_$idx";
                        $value = $second[$idx] ?? null;
                        echo "[$idx] $name => " . json_encode($value) . "\n";
                    }
                    echo "\n";
                }

                $foundData = true;
                break;
            }
        } else {
            echo "✗ No data\n\n";
        }
    } catch (\Exception $e) {
        $message = $e->getMessage();
        echo "✗ Error: " . substr($message, 0, 140) . "\n\n";

        // Fail fast if host is unreachable; retries with different date formats won't help.
        if (stripos($message, 'Failed to connect') !== false || stripos($message, 'cURL error 28') !== false) {
            echo "❌ Endpoint is unreachable from this machine; stopping additional attempts.\n";
            break;
        }
    }
}

if (!$foundData) {
    echo "❌ Could not find any CDR records with any approach\n\n";
    echo "This might mean:\n";
    echo "1. This server has no CDR history available via API\n";
    echo "2. Date format/range in PBX differs from expected contract\n";
    echo "3. Credentials/base URL point to a different PBX tenant\n\n";
    echo "Try:\n";
    echo "1. In PBX UI, confirm server $serverId has historical CDRs\n";
    echo "2. Validate PBX credentials and base URL in environment/secrets\n";
    echo "3. Ask PBX support for exact cdr.download date format for your instance\n";
} else {
    echo "✓ Please review the columns above and tell me:\n";
    echo "  - Which column [index] has the answering extension?\n";
    echo "  - Which column [index] has the ring group/queue?\n";
    echo "  - Which column [index] has the caller extension?\n";
    echo "  - Any other relevant columns?\n\n";
    echo "Reply with the indices and I'll update the ingest job.\n";
}
?>
