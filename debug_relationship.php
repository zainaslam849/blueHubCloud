<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "=== CALLS TABLE STRUCTURE ===\n";
$columns = Schema::getColumns('calls');
foreach ($columns as $col) {
    if (str_contains($col['name'], 'category')) {
        echo "{$col['name']}: {$col['type']} (nullable: " . ($col['nullable'] ? 'yes' : 'no') . ")\n";
    }
}

echo "\n=== CALL_CATEGORIES TABLE STRUCTURE ===\n";
$columns = Schema::getColumns('call_categories');
foreach ($columns as $col) {
    echo "{$col['name']}: {$col['type']}\n";
}

echo "\n=== RAW SQL JOIN TEST ===\n";
$result = DB::select(<<<SQL
    SELECT c.id, c.category_id, cc.id as category_table_id, cc.name
    FROM calls c
    LEFT JOIN call_categories cc ON c.category_id = cc.id
    WHERE c.id = 2
SQL
);

var_dump($result);

echo "\n=== TEST RELATIONSHIP QUERY ===\n";
$call = \App\Models\Call::find(2);
echo "Call model instance created for ID 2\n";
echo "category_id attribute: " . $call->getAttribute('category_id') . "\n";
echo "Calling $call->category (relationship)...\n";

// Enable query logging
DB::enableQueryLog();
$catResult = $call->category;
$queries = DB::getQueryLog();

echo "Queries executed:\n";
foreach ($queries as $query) {
    echo "  " . $query['query'] . "\n";
    if ($query['bindings']) {
        echo "    Bindings: " . json_encode($query['bindings']) . "\n";
    }
}

echo "Result: " . ($catResult ? "FOUND (ID: {$catResult->id})" : "NULL") . "\n";
