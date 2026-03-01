<?php
/**
 * Reset all business data while keeping the database structure intact
 * Run: php reset_all_data.php
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "╔════════════════════════════════════════════════╗\n";
echo "║     DELETING ALL BUSINESS DATA                 ║\n";
echo "╚════════════════════════════════════════════════╝\n\n";

// Disable foreign keys
DB::statement('SET FOREIGN_KEY_CHECKS=0');

// Delete all data from tables (individual truncates, no transaction)
$tables = [
    'daily_ingest_metrics',
    'daily_category_ingest_metrics',
    'extension_performance_reports',
    'ring_group_performance_reports',
    'category_analytics_reports',
    'call_categories',
    'call_category_overrides',
    'sub_categories',
    'category_override_logs',
    'weekly_call_reports',
    'calls',
    'transcriptions',
];

$totalDeleted = 0;

foreach ($tables as $table) {
    // Check if table exists
    if (!DB::getSchemaBuilder()->hasTable($table)) {
        echo "⊘ Table '$table' does not exist\n";
        continue;
    }
    
    try {
        $count = DB::table($table)->count();
        if ($count > 0) {
            DB::table($table)->truncate();
            $totalDeleted += $count;
            echo "✓ Truncated '$table' ($count rows deleted)\n";
        } else {
            echo "⊘ Table '$table' already empty\n";
        }
    } catch (\Exception $e) {
        echo "⚠ Failed to truncate '$table': " . $e->getMessage() . "\n";
    }
}

// Re-enable foreign keys
DB::statement('SET FOREIGN_KEY_CHECKS=1');

echo "\n╔════════════════════════════════════════════════╗\n";
echo "║  ✓ RESET COMPLETE - $totalDeleted ROWS DELETED    ║\n";
echo "╚════════════════════════════════════════════════╝\n\n";

echo "Next steps:\n";
echo "1. Go to /admin/dashboard\n";
echo "2. Click 'Run Full AI Pipeline'\n";
echo "3. Select a company\n";
echo "4. Click 'Run'\n";
echo "5. Wait for pipeline to complete\n";
echo "6. Fresh data will start populating\n\n";

