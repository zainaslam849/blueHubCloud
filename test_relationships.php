<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$call = \App\Models\Call::with(['category', 'subCategory'])->find(2);

echo "Call ID: {$call->id}\n";
echo "category_id column: {$call->category_id}\n";
echo "category relationship: ";
var_dump($call->category);
echo "\nsub_category_id column: {$call->sub_category_id}\n";
echo "subCategory relationship: ";
var_dump($call->subCategory);

echo "\n\nDirect query:\n";
$category = \App\Models\CallCategory::find($call->category_id);
echo "Category: {$category->name}\n";

$subCat = \App\Models\SubCategory::find($call->sub_category_id);
echo "SubCategory: {$subCat->name}\n";
