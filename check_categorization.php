<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$totalCalls = \App\Models\Call::count();
echo "Total calls: {$totalCalls}\n\n";

$categorized = \App\Models\Call::whereNotNull('category_id')->count();
echo "Categorized calls: {$categorized}\n";
echo "Uncategorized calls: " . ($totalCalls - $categorized) . "\n\n";

if ($categorized > 0) {
    $sources = \App\Models\Call::whereNotNull('category_source')
        ->selectRaw('category_source, COUNT(*) as count')
        ->groupBy('category_source')
        ->get();
    echo "Breakdown by source:\n";
    foreach ($sources as $source) {
        echo "  - {$source->category_source}: {$source->count}\n";
    }
    
    echo "\n--- Sample Categorized Calls ---\n";
    $samples = \App\Models\Call::with(['category', 'subCategory'])
        ->whereNotNull('category_id')
        ->limit(3)
        ->get();
    
    foreach ($samples as $call) {
        echo "\nCall ID: {$call->id}\n";
        echo "  Category: " . ($call->category?->name ?? 'N/A') . "\n";
        echo "  Sub-Category: " . ($call->subCategory?->name ?? $call->sub_category_label ?? 'N/A') . "\n";
        echo "  Source: " . ($call->category_source ?? 'N/A') . "\n";
        echo "  Confidence: " . ($call->category_confidence ? round($call->category_confidence * 100) . '%' : 'N/A') . "\n";
    }
    
    echo "\n--- Category Distribution ---\n";
    $distribution = \App\Models\Call::with('category')
        ->whereNotNull('category_id')
        ->get()
        ->groupBy('category_id')
        ->map(function($calls, $categoryId) {
            return [
                'category' => $calls->first()->category?->name ?? "ID: {$categoryId}",
                'count' => $calls->count()
            ];
        })
        ->sortByDesc('count');

    foreach ($distribution as $data) {
        echo "  {$data['category']}: {$data['count']} calls\n";
    }
} else {
    echo "❌ No calls have been categorized yet.\n\n";
    echo "To categorize calls, you need to:\n";
    echo "1. Set up OpenAI/Claude API integration\n";
    echo "2. Call the categorization endpoints:\n";
    echo "   POST /admin/api/categorization/build-prompt\n";
    echo "   POST /admin/api/categorization/persist\n";
    echo "3. Or manually categorize via the admin UI\n";
}
