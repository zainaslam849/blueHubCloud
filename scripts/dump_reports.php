<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$rows = App\Models\WeeklyCallReport::with('company')->get();
$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id' => $r->id,
        'company_id' => $r->company_id,
        'company_name' => $r->company ? $r->company->name : null,
        'week_start_date' => $r->week_start_date ? $r->week_start_date->toDateString() : null,
        'week_end_date' => $r->week_end_date ? $r->week_end_date->toDateString() : null,
        'total_calls' => $r->total_calls,
        'answered_calls' => $r->answered_calls,
        'calls_with_transcription' => $r->calls_with_transcription,
        'metrics' => $r->metrics ?? null,
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT);
