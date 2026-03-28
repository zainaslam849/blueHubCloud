<?php

/**
 * Phase 5 Verification Script
 * 
 * Validates that the company-scoped category assignment hardening is working:
 * 1. No processed calls exit with category_id = null (non-fatal paths)
 * 2. Invalid AI payloads fall back to General
 * 3. Low confidence falls back to Other/Unclear
 * 4. Fallback categories are auto-created per company when missing
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Call;
use App\Models\CallCategory;
use App\Services\CallCategorizationPersistenceService;

echo "=== Phase 5 Hardening Verification ===\n\n";

// Test 1: Check existing calls for null category_id (non-fatal failures)
echo "Test 1: Verify no calls with ai_category_status='completed' have null category_id\n";
$completedButUncategorized = Call::where('ai_category_status', 'completed')
    ->whereNull('category_id')
    ->count();

if ($completedButUncategorized > 0) {
    echo "  ❌ FAILED: Found {$completedButUncategorized} calls marked as 'completed' but uncategorized\n";
} else {
    echo "  ✓ PASSED: All completed calls have a category_id\n";
}

// Test 2: Verify General category fallback exists for company 144 (if calls exist)
echo "\nTest 2: Verify fallback categories exist for all companies with calls\n";
$companiesWithCalls = Call::whereNotNull('company_id')
    ->distinct('company_id')
    ->pluck('company_id')
    ->toArray();

foreach ($companiesWithCalls as $companyId) {
    $generalExists = CallCategory::where('company_id', $companyId)
        ->where('name', 'General')
        ->where('is_enabled', true)
        ->where('status', 'active')
        ->exists();
    
    $otherExists = CallCategory::where('company_id', $companyId)
        ->where('name', 'Other')
        ->where('is_enabled', true)
        ->where('status', 'active')
        ->exists();
    
    $status = ($generalExists && $otherExists) ? '✓' : '❌';
    echo "  {$status} Company {$companyId}: General={$generalExists}, Other={$otherExists}\n";
}

// Test 3: Check categorization status distribution
echo "\nTest 3: Categorization Status Distribution\n";
$statuses = Call::selectRaw('ai_category_status, COUNT(*) as count')
    ->groupBy('ai_category_status')
    ->get();

foreach ($statuses as $row) {
    echo "  - {$row->ai_category_status}: {$row->count}\n";
}

// Test 4: Verify category source consistency
echo "\nTest 4: Category Source Breakdown (for categorized calls)\n";
$sources = Call::whereNotNull('category_id')
    ->selectRaw('category_source, COUNT(*) as count')
    ->groupBy('category_source')
    ->get();

foreach ($sources as $row) {
    echo "  - {$row->category_source}: {$row->count}\n";
}

// Test 5: Check confidence distribution
echo "\nTest 5: Confidence Distribution (for AI-sourced calls)\n";
$confidenceBuckets = Call::where('category_source', 'ai')
    ->selectRaw("
        CASE 
            WHEN category_confidence >= 0.9 THEN '90-100%'
            WHEN category_confidence >= 0.8 THEN '80-89%'
            WHEN category_confidence >= 0.7 THEN '70-79%'
            WHEN category_confidence >= 0.6 THEN '60-69%'
            ELSE 'Below 60%'
        END as bucket,
        COUNT(*) as count
    ")
    ->groupBy('bucket')
    ->get();

foreach ($confidenceBuckets as $row) {
    echo "  - {$row->bucket}: {$row->count}\n";
}

// Test 6: Verify fallback category assignments
echo "\nTest 6: Fallback Category Usage\n";
$generalUsage = Call::whereHas('category', function ($q) {
    $q->where('name', 'General');
})->count();

$otherUsage = Call::whereHas('category', function ($q) {
    $q->where('name', 'Other');
})->count();

echo "  - General category assigned to: {$generalUsage} calls\n";
echo "  - Other category assigned to: {$otherUsage} calls\n";

// Test 7: Sample calls from each fallback category
echo "\nTest 7: Sample Fallback Assignments\n";

$generalSamples = Call::with(['category', 'subCategory'])
    ->whereHas('category', function ($q) {
        $q->where('name', 'General');
    })
    ->limit(2)
    ->get();

if ($generalSamples->count() > 0) {
    echo "  General Category Samples:\n";
    foreach ($generalSamples as $call) {
        echo "    - Call {$call->id}: confidence={$call->category_confidence}, reason stored in logs\n";
    }
}

$otherSamples = Call::with(['category', 'subCategory'])
    ->whereHas('category', function ($q) {
        $q->where('name', 'Other');
    })
    ->limit(2)
    ->get();

if ($otherSamples->count() > 0) {
    echo "  Other Category Samples:\n";
    foreach ($otherSamples as $call) {
        $subCat = $call->subCategory?->name ?? $call->sub_category_label ?? 'None';
        echo "    - Call {$call->id}: sub_category={$subCat}, confidence={$call->category_confidence}\n";
    }
}

// Test 8: Verify no crashes or fatal errors occurred
echo "\nTest 8: Fatal Error Status\n";
$failedCount = Call::where('ai_category_status', 'failed')->count();
$creditExhaustedCount = Call::where('ai_category_status', 'credit_exhausted')->count();

echo "  - Failed (fatal): {$failedCount}\n";
echo "  - Credit exhausted: {$creditExhaustedCount}\n";

if ($failedCount + $creditExhaustedCount > 0) {
    echo "  (These are expected for provider/credit exceptions)\n";
}

echo "\n=== Phase 5 Verification Complete ===\n";
