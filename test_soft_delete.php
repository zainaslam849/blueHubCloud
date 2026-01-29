<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$cat = DB::table('call_categories')->where('id', 3)->first();
echo "Raw DB query - ID 3:\n";
echo "  id: {$cat->id}\n";
echo "  name: {$cat->name}\n";
echo "  deleted_at: " . ($cat->deleted_at ?? 'NULL') . "\n\n";

// Now check with Eloquent
$catEloquent = \App\Models\CallCategory::find(3);
echo "Eloquent query - ID 3:\n";
var_dump($catEloquent);
