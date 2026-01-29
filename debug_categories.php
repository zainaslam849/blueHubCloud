<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== CATEGORIZED CALLS (Raw Database) ===\n\n";

$calls = DB::table('calls')
    ->whereNotNull('category_id')
    ->orderBy('categorized_at', 'desc')
    ->limit(6)
    ->get(['id', 'category_id', 'sub_category_id', 'sub_category_label', 'category_source', 'category_confidence']);

foreach ($calls as $call) {
    echo "Call ID: {$call->id}\n";
    echo "  category_id: {$call->category_id}\n";
    echo "  sub_category_id: " . ($call->sub_category_id ?? 'NULL') . "\n";
    echo "  sub_category_label: " . ($call->sub_category_label ?? 'NULL') . "\n";
    echo "  source: {$call->category_source}\n";
    echo "  confidence: " . round($call->category_confidence * 100) . "%\n";
    
    // Check if category exists
    $category = DB::table('call_categories')->find($call->category_id);
    if ($category) {
        echo "  ✓ Category found: {$category->name} (enabled: " . ($category->is_enabled ? 'yes' : 'no') . ")\n";
    } else {
        echo "  ✗ Category ID {$call->category_id} not found in database!\n";
    }
    
    // Check if sub_category exists
    if ($call->sub_category_id) {
        $subCat = DB::table('sub_categories')->find($call->sub_category_id);
        if ($subCat) {
            echo "  ✓ Sub-category found: {$subCat->name}\n";
        } else {
            echo "  ✗ Sub-category ID {$call->sub_category_id} not found!\n";
        }
    }
    
    echo "\n";
}

echo "\n=== AVAILABLE CATEGORIES ===\n\n";

$categories = DB::table('call_categories')->get(['id', 'name', 'is_enabled']);
foreach ($categories as $cat) {
    $enabled = $cat->is_enabled ? '✓' : '✗';
    echo "{$enabled} ID {$cat->id}: {$cat->name}\n";
}
