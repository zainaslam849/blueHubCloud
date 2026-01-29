<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$call = \App\Models\Call::find(2);

// Access the relationship definition
$relation = $call->category();

echo "Relationship type: " . class_basename($relation) . "\n";
echo "Foreign key: " . $relation->getForeignKeyName() . "\n";
echo "Other key: " . $relation->getOwnerKeyName() . "\n";
echo "Qualified foreign key: " . $relation->getQualifiedForeignKeyName() . "\n";
echo "Qualified owner key: " . $relation->getQualifiedOwnerKeyName() . "\n";

echo "\nCall model key name: " . $call->getKeyName() . "\n";
echo "Call model table: " . $call->getTable() . "\n";

echo "\nCallCategory model key name: " . \App\Models\CallCategory::make()->getKeyName() . "\n";
echo "CallCategory model table: " . \App\Models\CallCategory::make()->getTable() . "\n";

// Test the raw relationship query
echo "\n\nRelationship query:\n";
\Illuminate\Support\Facades\DB::enableQueryLog();
$result = $call->category()->get();
foreach (\Illuminate\Support\Facades\DB::getQueryLog() as $q) {
    echo $q['query'] . "\n";
}

echo "Result count: " . count($result) . "\n";
var_dump($result->toArray());
