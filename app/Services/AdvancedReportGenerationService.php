<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Call;
use App\Models\CallCategory;
use App\Models\ExtensionPerformanceReport;
use App\Models\RingGroupPerformanceReport;
use App\Models\CategoryAnalyticsReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Advanced Report Generation Service
 * 
 * Generates comprehensive analytics for:
 * - Extension leaderboards and scorecards
 * - Ring group performance
 * - Category drill-down analytics
 * - Automation opportunity scoring
 */
class AdvancedReportGenerationService
{
    private const AUTOMATION_HIGH_VOLUME_THRESHOLD = 20; // calls per week
    private const AUTOMATION_HIGH_MINUTES_THRESHOLD = 60; // minutes per week
    private const REPETITIVE_THRESHOLD = 0.7; // 70% of calls in top 5 categories

    /**
     * Generate all advanced reports for a company and time period
     */
    public function generateComprehensiveReports(
        int $companyId,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        ?int $weeklyReportId = null
    ): array {
        Log::info("Generating comprehensive reports", [
            'company_id' => $companyId,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
        ]);

        $results = [
            'extension_reports' => 0,
            'ring_group_reports' => 0,
            'category_reports' => 0,
        ];

        try {
            // Generate extension performance reports (leaderboard + scorecards)
            $results['extension_reports'] = $this->generateExtensionReports(
                $companyId,
                $periodStart,
                $periodEnd,
                $weeklyReportId
            );

            // Generate ring group performance reports
            $results['ring_group_reports'] = $this->generateRingGroupReports(
                $companyId,
                $periodStart,
                $periodEnd,
                $weeklyReportId
            );

            // Generate category analytics reports (drill-down)
            $results['category_reports'] = $this->generateCategoryAnalytics(
                $companyId,
                $periodStart,
                $periodEnd,
                $weeklyReportId
            );

            Log::info("Comprehensive reports generated successfully", $results);
        } catch (\Exception $e) {
            Log::error("Failed to generate comprehensive reports", [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $results;
    }

    /**
     * Generate Extension Performance Reports (Leaderboard data)
     */
    private function generateExtensionReports(
        int $companyId,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        ?int $weeklyReportId
    ): int {
        $calls = Call::where('company_id', $companyId)
            ->whereBetween('started_at', [
                $periodStart->startOfDay(),
                $periodEnd->endOfDay()
            ])
            ->get();

        $extensionData = [];

        foreach ($calls as $call) {
            // Identify the extension that handled this call
            $extension = $call->answered_by_extension ?? $call->to ?? null;
            
            if (!$extension || !preg_match('/^\d{2,5}$/', $extension)) {
                continue; // Skip if not a valid extension number
            }

            if (!isset($extensionData[$extension])) {
                $extensionData[$extension] = [
                    'calls_answered' => 0,
                    'calls_made' => 0,
                    'total_seconds' => 0,
                    'categories' => [],
                    'ring_groups' => [],
                    'missed_calls' => 0,
                    'short_calls' => 0,
                    'department' => $call->department,
                ];
            }

            // Categorize call direction
            if ($call->direction === 'inbound' && $call->status === 'answered') {
                $extensionData[$extension]['calls_answered']++;
            } elseif ($call->direction === 'outbound') {
                $extensionData[$extension]['calls_made']++;
            }

            $extensionData[$extension]['total_seconds'] += $call->duration_seconds ?? 0;

            // Track missed calls
            if (in_array($call->status, ['missed', 'failed'])) {
                $extensionData[$extension]['missed_calls']++;
            }

            // Track short calls (< 15 seconds)
            if ($call->duration_seconds > 0 && $call->duration_seconds <= 15) {
                $extensionData[$extension]['short_calls']++;
            }

            // Track categories
            if ($call->category_id) {
                if (!isset($extensionData[$extension]['categories'][$call->category_id])) {
                    $extensionData[$extension]['categories'][$call->category_id] = [
                        'count' => 0,
                        'minutes' => 0,
                        'name' => $call->category->name ?? 'Unknown',
                    ];
                }
                $extensionData[$extension]['categories'][$call->category_id]['count']++;
                $extensionData[$extension]['categories'][$call->category_id]['minutes'] += intdiv($call->duration_seconds ?? 0, 60);
            }

            // Track ring groups
            if ($call->ring_group) {
                $extensionData[$extension]['ring_groups'][$call->ring_group] = 
                    ($extensionData[$extension]['ring_groups'][$call->ring_group] ?? 0) + 1;
            }
        }

        // Insert/update extension performance reports
        $reportsCreated = 0;
        foreach ($extensionData as $extension => $data) {
            $totalCalls = $data['calls_answered'] + $data['calls_made'];
            if ($totalCalls === 0) continue;

            // Calculate top 3 categories
            arsort($data['categories']);
            $topCategories = array_slice($data['categories'], 0, 3, true);
            $topCategoriesFormatted = [];
            foreach ($topCategories as $catId => $catData) {
                $topCategoriesFormatted[] = [
                    'category_id' => $catId,
                    'category_name' => $catData['name'],
                    'count' => $catData['count'],
                    'percentage' => round(($catData['count'] / $totalCalls) * 100, 1),
                ];
            }

            // Calculate repetitive percentage (top 5 categories)
            $top5Count = array_sum(array_slice(array_column($data['categories'], 'count'), 0, 5));
            $repetitivePercentage = $totalCalls > 0 ? ($top5Count / $totalCalls) * 100 : 0;

            // Calculate automation impact score
            $totalMinutes = intdiv($data['total_seconds'], 60);
            $automationImpactScore = (int) ($totalMinutes * ($repetitivePercentage / 100));

            ExtensionPerformanceReport::updateOrCreate(
                [
                    'company_id' => $companyId,
                    'extension' => $extension,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                ],
                [
                    'weekly_call_report_id' => $weeklyReportId,
                    'department' => $data['department'],
                    'total_calls_answered' => $data['calls_answered'],
                    'total_calls_made' => $data['calls_made'],
                    'total_minutes' => $totalMinutes,
                    'avg_call_duration_seconds' => $totalCalls > 0 ? intdiv($data['total_seconds'], $totalCalls) : 0,
                    'top_categories' => $topCategoriesFormatted,
                    'repetitive_category_percentage' => round($repetitivePercentage, 2),
                    'automation_impact_score' => $automationImpactScore,
                    'missed_calls_count' => $data['missed_calls'],
                    'short_calls_count' => $data['short_calls'],
                    'category_breakdown' => $data['categories'],
                    'ring_group_breakdown' => $data['ring_groups'],
                ]
            );

            $reportsCreated++;
        }

        return $reportsCreated;
    }

    /**
     * Generate Ring Group Performance Reports
     */
    private function generateRingGroupReports(
        int $companyId,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        ?int $weeklyReportId
    ): int {
        $calls = Call::where('company_id', $companyId)
            ->whereBetween('started_at', [
                $periodStart->startOfDay(),
                $periodEnd->endOfDay()
            ])
            ->whereNotNull('ring_group')
            ->get();

        $ringGroupData = [];

        foreach ($calls as $call) {
            $ringGroup = $call->ring_group;
            
            if (!isset($ringGroupData[$ringGroup])) {
                $ringGroupData[$ringGroup] = [
                    'total_calls' => 0,
                    'answered_calls' => 0,
                    'missed_calls' => 0,
                    'abandoned_calls' => 0,
                    'total_seconds' => 0,
                    'categories' => [],
                    'extensions' => [],
                    'hourly_distribution' => array_fill(0, 24, 0),
                    'missed_by_hour' => array_fill(0, 24, 0),
                    'department' => $call->department,
                ];
            }

            $ringGroupData[$ringGroup]['total_calls']++;

            if ($call->status === 'answered') {
                $ringGroupData[$ringGroup]['answered_calls']++;
            } elseif ($call->status === 'missed') {
                $ringGroupData[$ringGroup]['missed_calls']++;
                
                // Track when missed calls occur
                $hour = $call->started_at->hour;
                $ringGroupData[$ringGroup]['missed_by_hour'][$hour]++;
            } elseif ($call->status === 'abandoned') {
                $ringGroupData[$ringGroup]['abandoned_calls']++;
            }

            $ringGroupData[$ringGroup]['total_seconds'] += $call->duration_seconds ?? 0;

            // Track hourly distribution
            $hour = $call->started_at->hour;
            $ringGroupData[$ringGroup]['hourly_distribution'][$hour]++;

            // Track categories with minutes
            if ($call->category_id) {
                if (!isset($ringGroupData[$ringGroup]['categories'][$call->category_id])) {
                    $ringGroupData[$ringGroup]['categories'][$call->category_id] = [
                        'count' => 0,
                        'minutes' => 0,
                        'name' => $call->category->name ?? 'Unknown',
                    ];
                }
                $ringGroupData[$ringGroup]['categories'][$call->category_id]['count']++;
                $ringGroupData[$ringGroup]['categories'][$call->category_id]['minutes'] += intdiv($call->duration_seconds ?? 0, 60);
            }

            // Track extensions in this ring group
            if ($call->answered_by_extension) {
                $ringGroupData[$ringGroup]['extensions'][$call->answered_by_extension] = 
                    ($ringGroupData[$ringGroup]['extensions'][$call->answered_by_extension] ?? 0) + 1;
            }
        }

        // Insert/update ring group performance reports
        $reportsCreated = 0;
        foreach ($ringGroupData as $ringGroup => $data) {
            // Top categories by call count
            $topCategories = $this->sortAndFormatCategories($data['categories'], 'count');
            
            // Time sink categories (sorted by minutes)
            $timeSinkCategories = $this->sortAndFormatCategories($data['categories'], 'minutes');

            // Identify automation opportunities
            $automationOpportunities = $this->identifyAutomationOpportunities($data['categories']);
            
            // Calculate automation priority score
            $automationScore = $this->calculateAutomationPriorityScore($data['categories'], $data['total_calls']);

            // Peak missed times
            $peakMissedTimes = $this->identifyPeakMissedTimes($data['missed_by_hour']);

            RingGroupPerformanceReport::updateOrCreate(
                [
                    'company_id' => $companyId,
                    'ring_group' => $ringGroup,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                ],
                [
                    'weekly_call_report_id' => $weeklyReportId,
                    'department' => $data['department'],
                    'total_calls' => $data['total_calls'],
                    'answered_calls' => $data['answered_calls'],
                    'missed_calls' => $data['missed_calls'],
                    'abandoned_calls' => $data['abandoned_calls'],
                    'total_minutes' => intdiv($data['total_seconds'], 60),
                    'top_categories' => $topCategories,
                    'time_sink_categories' => $timeSinkCategories,
                    'automation_opportunities' => $automationOpportunities,
                    'automation_priority_score' => $automationScore,
                    'peak_missed_times' => $peakMissedTimes,
                    'hourly_distribution' => $data['hourly_distribution'],
                    'extension_stats' => $data['extensions'],
                ]
            );

            $reportsCreated++;
        }

        return $reportsCreated;
    }

    /**
     * Generate Category Analytics Reports (Drill-down)
     */
    private function generateCategoryAnalytics(
        int $companyId,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        ?int $weeklyReportId
    ): int {
        $categories = CallCategory::where('company_id', $companyId)
            ->where('is_enabled', true)
            ->get();

        $reportsCreated = 0;

        foreach ($categories as $category) {
            $calls = Call::where('company_id', $companyId)
                ->where('category_id', $category->id)
                ->whereBetween('started_at', [
                    $periodStart->startOfDay(),
                    $periodEnd->endOfDay()
                ])
                ->get();

            if ($calls->isEmpty()) continue;

            $totalCalls = $calls->count();
            $totalSeconds = $calls->sum('duration_seconds');
            $totalMinutes = intdiv($totalSeconds, 60);
            $avgDuration = $totalCalls > 0 ? $totalSeconds / $totalCalls : 0;

            // Extension breakdown
            $extensionBreakdown = [];
            $ringGroupBreakdown = [];
            $subCategoryBreakdown = [];
            $dailyTrend = array_fill(0, 7, 0); // Mon-Sun
            $hourlyTrend = array_fill(0, 24, 0);
            $sampleCallIds = [];
            $confidenceSum = 0;
            $lowConfidenceCount = 0;

            foreach ($calls as $call) {
                // Extensions
                $ext = $call->answered_by_extension ?? $call->to;
                if ($ext) {
                    $extensionBreakdown[$ext] = ($extensionBreakdown[$ext] ?? 0) + 1;
                }

                // Ring groups
                if ($call->ring_group) {
                    $ringGroupBreakdown[$call->ring_group] = ($ringGroupBreakdown[$call->ring_group] ?? 0) + 1;
                }

                // Sub-categories
                if ($call->sub_category_id) {
                    $subName = $call->subCategory->name ?? 'Unknown';
                    $subCategoryBreakdown[$subName] = ($subCategoryBreakdown[$subName] ?? 0) + 1;
                }

                // Trends
                $dayOfWeek = $call->started_at->dayOfWeek; // 0 = Sunday
                $dailyTrend[$dayOfWeek]++;
                $hourlyTrend[$call->started_at->hour]++;

                // Sample calls (top 5)
                if (count($sampleCallIds) < 5) {
                    $sampleCallIds[] = $call->id;
                }

                // Confidence tracking
                if ($call->category_confidence !== null) {
                    $confidenceSum += $call->category_confidence;
                    if ($call->category_confidence < 0.6) {
                        $lowConfidenceCount++;
                    }
                }
            }

            $avgConfidence = $totalCalls > 0 ? $confidenceSum / $totalCalls : null;

            // Determine if automation candidate
            $isAutomationCandidate = $this->isAutomationCandidate($totalCalls, $totalMinutes, $avgConfidence);
            $automationPriority = $this->determineAutomationPriority($totalCalls, $totalMinutes);
            $suggestedAutomations = $this->generateAutomationSuggestions($category->name, $totalCalls, $totalMinutes);

            // Trend analysis (compare to previous period if needed - simplified here)
            $trendDirection = 0; // 0 = stable (you can enhance this)
            $trendPercentage = 0;

            CategoryAnalyticsReport::updateOrCreate(
                [
                    'company_id' => $companyId,
                    'category_id' => $category->id,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                ],
                [
                    'weekly_call_report_id' => $weeklyReportId,
                    'total_calls' => $totalCalls,
                    'total_minutes' => $totalMinutes,
                    'average_call_duration_seconds' => $avgDuration,
                    'extension_breakdown' => $extensionBreakdown,
                    'ring_group_breakdown' => $ringGroupBreakdown,
                    'sub_category_breakdown' => $subCategoryBreakdown,
                    'daily_trend' => $dailyTrend,
                    'hourly_trend' => $hourlyTrend,
                    'trend_direction' => $trendDirection,
                    'trend_percentage_change' => $trendPercentage,
                    'is_automation_candidate' => $isAutomationCandidate,
                    'automation_priority' => $automationPriority,
                    'suggested_automations' => $suggestedAutomations,
                    'sample_call_ids' => $sampleCallIds,
                    'avg_confidence_score' => $avgConfidence,
                    'low_confidence_count' => $lowConfidenceCount,
                ]
            );

            $reportsCreated++;
        }

        return $reportsCreated;
    }

    // Helper methods

    private function sortAndFormatCategories(array $categories, string $sortBy): array
    {
        usort($categories, function ($a, $b) use ($sortBy) {
            return $b[$sortBy] <=> $a[$sortBy];
        });

        $formatted = [];
        foreach (array_slice($categories, 0, 10) as $catId => $data) {
            $formatted[] = array_merge($data, ['category_id' => $catId]);
        }

        return $formatted;
    }

    private function identifyAutomationOpportunities(array $categories): array
    {
        $opportunities = [];
        
        foreach ($categories as $catId => $data) {
            if ($data['count'] >= self::AUTOMATION_HIGH_VOLUME_THRESHOLD || 
                $data['minutes'] >= self::AUTOMATION_HIGH_MINUTES_THRESHOLD) {
                $opportunities[] = [
                    'category_id' => $catId,
                    'category_name' => $data['name'],
                    'call_count' => $data['count'],
                    'minutes' => $data['minutes'],
                    'priority' => $this->determineAutomationPriority($data['count'], $data['minutes']),
                ];
            }
        }

        // Sort by priority
        usort($opportunities, function ($a, $b) {
            $priorityOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
            return ($priorityOrder[$b['priority']] ?? 0) <=> ($priorityOrder[$a['priority']] ?? 0);
        });

        return $opportunities;
    }

    private function calculateAutomationPriorityScore(array $categories, int $totalCalls): int
    {
        $score = 0;
        
        foreach ($categories as $data) {
            if ($data['count'] >= self::AUTOMATION_HIGH_VOLUME_THRESHOLD) {
                $score += $data['minutes'] * 2; // Multiply by 2 for high-volume categories
            }
        }

        return $score;
    }

    private function identifyPeakMissedTimes(array $missedByHour): array
    {
        $peaks = [];
        arsort($missedByHour);
        
        foreach (array_slice($missedByHour, 0, 3, true) as $hour => $count) {
            if ($count > 0) {
                $peaks[] = [
                    'hour' => $hour,
                    'hour_label' => sprintf("%02d:00-%02d:00", $hour, $hour + 1),
                    'missed_count' => $count,
                ];
            }
        }

        return $peaks;
    }

    private function isAutomationCandidate(int $callCount, int $minutes, ?float $avgConfidence): bool
    {
        return $callCount >= self::AUTOMATION_HIGH_VOLUME_THRESHOLD &&
               $minutes >= self::AUTOMATION_HIGH_MINUTES_THRESHOLD &&
               ($avgConfidence === null || $avgConfidence >= 0.7);
    }

    private function determineAutomationPriority(int $callCount, int $minutes): string
    {
        if ($callCount >= 50 || $minutes >= 180) {
            return 'high';
        } elseif ($callCount >= 30 || $minutes >= 90) {
            return 'medium';
        }
        return 'low';
    }

    private function generateAutomationSuggestions(string $categoryName, int $callCount, int $minutes): array
    {
        $suggestions = [];

        // Pattern-based suggestions
        $patterns = [
            '/appointment|booking|schedule/i' => 'Implement online booking system or SMS scheduling link',
            '/billing|invoice|payment/i' => 'Create self-service billing portal or automated payment reminders',
            '/status|track|where.*is/i' => 'Implement real-time tracking system with SMS updates',
            '/hours|open|closed/i' => 'Add IVR option for business hours or update website',
            '/general.*inquiry|information/i' => 'Deploy AI chatbot or enhanced FAQ system',
            '/support|technical|help/i' => 'Implement tiered support system with AI-powered initial triage',
        ];

        foreach ($patterns as $pattern => $suggestion) {
            if (preg_match($pattern, $categoryName)) {
                $suggestions[] = [
                    'type' => 'pattern_match',
                    'suggestion' => $suggestion,
                    'impact' => "Could reduce ~{$callCount} calls/week (~{$minutes} minutes)",
                ];
            }
        }

        // Volume-based generic suggestion
        if (empty($suggestions)) {
            $suggestions[] = [
                'type' => 'high_volume',
                'suggestion' => "High volume category - consider IVR option or dedicated AI flow",
                'impact' => "Handling {$callCount} calls/week (~{$minutes} minutes)",
            ];
        }

        return $suggestions;
    }
}
