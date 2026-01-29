<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$call = \App\Models\Call::find(2);

echo "Call 2:\n";
echo "  category_id: {$call->category_id}\n";

// Test 1: Load relationship after
$category = $call->category;
echo "  category via relationship: ";
var_dump($category);

// Test 2: Direct eager loading
$callWithCategory = \App\Models\Call::with('category')->find(2);
echo "\nCall 2 with eager loading:\n";
echo "  category_id: {$callWithCategory->category_id}\n";
echo "  category: ";
var_dump($callWithCategory->category);

// Test 3: Using getter
echo "\nUsing null coalesce:\n";
echo "  " . ($callWithCategory->category?->name ?? 'NULL') . "\n";
