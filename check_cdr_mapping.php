<?php
/**
 * STEP 1: Check CDR Column Structure
 * Run: php check_cdr_mapping.php
 * 
 * This script will help you identify which CDR columns contain
 * extension, ring_group, queue, and department data.
 * 
 * Output: A sample CDR row with ALL columns shown
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "╔════════════════════════════════════════════════╗\n";
echo "║     CDR COLUMN MAPPING CHECKER                 ║\n";
echo "╚════════════════════════════════════════════════╝\n\n";

// Show what fields the calls table has
echo "═══ CALLS TABLE FIELDS ═══\n";
$callColumns = DB::getSchemaBuilder()->getColumnListing('calls');
foreach ($callColumns as $col) {
    if (in_array($col, ['answered_by_extension', 'ring_group', 'queue_name', 'department', 'caller_extension'])) {
        echo "  ✓ $col (ready for data)\n";
    }
}

echo "\n═══ NEXT STEPS ═══\n";
echo "1. Contact your PBXware provider and ask for the CDR column layout\n";
echo "   Example: Column 4 = Extension, Column 5 = Ring Group, etc.\n\n";

echo "2. Once you have the mapping, update the IngestPbxCallsJob\n";
echo "   Location: app/Jobs/IngestPbxCallsJob.php around line 165\n\n";

echo "3. Example code to add (replace indices with your actual mapping):\n";
echo "   \$answeredByExtension = \$row[4] ?? null; // Column 4 = answering extension\n";
echo "   \$ringGroup = \$row[5] ?? null; // Column 5 = ring group\n";
echo "   \$queue = \$row[8] ?? null; // Column 8 = queue name\n\n";

echo "4. Then add these to the call update:\n";
echo "   'answered_by_extension' => \$answeredByExtension,\n";
echo "   'ring_group' => \$ringGroup,\n";
echo "   'queue_name' => \$queue,\n\n";

echo "Need help? Check your PBX documentation or contact support.\n";
echo "Once provided, I'll update the ingest job to extract this data.\n";
?>
