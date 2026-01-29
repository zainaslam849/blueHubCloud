<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$cat = DB::select('SELECT * FROM call_categories WHERE id = 3')[0];
echo "Category 3 raw data:\n";
echo json_encode($cat, JSON_PRETTY_PRINT) . "\n";

echo "\nTesting the WHERE clause:\n";
$result = DB::select('SELECT * FROM call_categories WHERE id = 3 AND deleted_at IS NULL');
echo "Query result with 'deleted_at IS NULL': " . count($result) . " rows\n";

if (count($result) > 0) {
    echo "✓ Category found!\n";
} else {
    echo "✗ Category NOT found\n";
    echo "Checking deleted_at value:\n";
    $col = DB::select('SELECT deleted_at, HEX(deleted_at) as hex FROM call_categories WHERE id = 3')[0];
    var_dump($col);
}
