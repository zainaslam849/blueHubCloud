<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

DB::enableQueryLog();

$call = \App\Models\Call::with('category')->find(2);
echo "Query log:\n";
foreach (DB::getQueryLog() as $query) {
    echo $query['query'] . "\n";
    if ($query['bindings']) echo "  Bindings: " . json_encode($query['bindings']) . "\n";
}

echo "\nCall category_id: {$call->category_id}\n";
echo "Call category: ";
var_dump($call->category);

// Try raw query
echo "\n\nRaw SQL:\n";
$raw = DB::table('calls')
    ->join('call_categories', 'calls.category_id', '=', 'call_categories.id')
    ->where('calls.id', 2)
    ->select('calls.id', 'calls.category_id', 'call_categories.id as cat_id', 'call_categories.name')
    ->first();
var_dump($raw);
