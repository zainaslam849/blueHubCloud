<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$call = \App\Models\Call::find(2);

echo "Test 1: Using relationship() method then get():\n";
$cat1 = $call->category()->first();
echo "Result: " . ($cat1 ? $cat1->name : "NULL") . "\n";

echo "\nTest 2: Using property accessor (lazy load):\n";
$cat2 = $call->category;
echo "Result: " . ($cat2 ? $cat2->name : "NULL") . "\n";

echo "\nTest 3: Using eager loading in query:\n";
$callEager = \App\Models\Call::with('category')->find(2);
$cat3 = $callEager->category;
echo "Result: " . ($cat3 ? $cat3->name : "NULL") . "\n";

echo "\nTest 4: Check if relationship is loaded:\n";
$call2 = \App\Models\Call::find(2);
echo "Is 'category' in relations: " . ($call2->relationLoaded('category') ? 'YES' : 'NO') . "\n";

echo "\nTest 5: Force load relationship:\n";
$call2->load('category');
echo "Is 'category' in relations now: " . ($call2->relationLoaded('category') ? 'YES' : 'NO') . "\n";
echo "Value: " . ($call2->category ? $call2->category->name : "NULL") . "\n";
