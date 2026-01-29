<?php

// Verify report generation
use Illuminate\Support\Facades\DB;

$report = DB::table('weekly_call_reports')->latest()->first();

if ($report) {
    echo "✅ Report found!\n";
    echo "   ID: {$report->id}\n";
    echo "   Total calls: {$report->total_calls}\n";
    echo "   Answered: {$report->answered_calls}\n";
    echo "   Week: {$report->week_start_date} to {$report->week_end_date}\n";
    
    $metrics = json_decode($report->metrics, true);
    if (isset($metrics['ai_summary'])) {
        echo "   ✅ AI summary: YES\n";
        $ai = $metrics['ai_summary'];
        echo "      - Executive summary: " . (strlen($ai['ai_summary']) > 0 ? 'Generated' : 'Empty') . "\n";
        echo "      - Recommendations: " . count($ai['recommendations'] ?? []) . "\n";
        echo "      - Risks: " . count($ai['risks'] ?? []) . "\n";
        echo "      - Opportunities: " . count($ai['automation_opportunities'] ?? []) . "\n";
    } else {
        echo "   ⚠️  AI summary: NOT FOUND\n";
    }
    
    // Check categories
    if (isset($metrics['category_counts'])) {
        echo "\n   Category breakdown:\n";
        foreach ($metrics['category_counts'] as $cat => $count) {
            echo "      - $cat: $count calls\n";
        }
    }
} else {
    echo "❌ No reports found!\n";
}
