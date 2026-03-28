<?php
// Check if deleted_at column exists in calls table
$db = new PDO('sqlite:database.sqlite');
$columns = $db->query('PRAGMA table_info(calls)')->fetchAll(PDO::FETCH_ASSOC);

echo "Checking calls table schema...\n";
$hasDeletedAt = false;
foreach($columns as $col) {
    if($col['name'] === 'deleted_at') {
        echo "✓ Found deleted_at column at position {$col['cid']}\n";
        echo "  Type: {$col['type']}\n";
        echo "  Nullable: " . ($col['notnull'] ? 'NO' : 'YES') . "\n";
        $hasDeletedAt = true;
    }
}

if (!$hasDeletedAt) {
    echo "✗ deleted_at column NOT found in calls table\n";
    echo "\nColumns in calls table:\n";
    foreach($columns as $col) {
        echo "  - {$col['name']} ({$col['type']})\n";
    }
}

echo "\nDone.\n";
