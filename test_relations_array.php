<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$call = \App\Models\Call::with('category')->find(2);

echo "Call relations array:\n";
var_dump($call->getRelations());

echo "\nCall relations keys:\n";
var_dump(array_keys($call->getRelations()));

echo "\nCheck if 'category' is in relations:\n";
var_dump(isset($call->getRelations()['category']));

if (isset($call->getRelations()['category'])) {
    echo "\nRelation value:\n";
    var_dump($call->getRelations()['category']);
}
