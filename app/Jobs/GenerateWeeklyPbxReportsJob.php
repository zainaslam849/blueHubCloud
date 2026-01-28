<?php

namespace App\Jobs;

use App\Models\WeeklyCallReport;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class GenerateWeeklyPbxReportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Optional ISO date bounds (inclusive) applied to calls.started_at.
     *
     * Use this to limit aggregation work (e.g., just last week).
     */
    public function __construct(
        public ?string $fromDate = null,
        public ?string $toDate = null,
    ) {
    }

    public function handle(): void
    {
        $from = $this->fromDate ? CarbonImmutable::parse($this->fromDate, 'UTC')->startOfDay() : null;
        $to = $this->toDate ? CarbonImmutable::parse($this->toDate, 'UTC')->endOfDay() : null;

        $companyRows = DB::table('companies')
            ->select(['id', 'timezone'])
            ->orderBy('id')
            ->get();

        foreach ($companyRows as $company) {
            $companyId = (int) ($company->id ?? 0);
            if ($companyId <= 0) {
                continue;
            }

            $timezone = is_string($company->timezone) && $company->timezone !== '' ? $company->timezone : 'UTC';

            $query = $this->baseCallsQuery($companyId);

            if ($from) {
                $query->where('started_at', '>=', $from->toDateTimeString());
            }

            if ($to) {
                $query->where('started_at', '<=', $to->toDateTimeString());
            }

            $accumulators = [];

            // If date bounds were provided (regeneration), reset weekly markers
            if ($from || $to) {
                $reset = DB::table('calls')->where('company_id', $companyId);
                if ($from) {
                    $reset->where('started_at', '>=', $from->toDateTimeString());
                }
                if ($to) {
                    $reset->where('started_at', '<=', $to->toDateTimeString());
                }
                $reset->update(['weekly_call_report_id' => null]);
            }

            $query->orderBy('started_at')->chunk(2000, function ($calls) use (&$accumulators, $timezone) {
                foreach ($calls as $call) {
                    if (! $call->started_at) {
                        continue;
                    }

                    $companyPbxAccountId = (int) ($call->company_pbx_account_id ?? 0);
                    if ($companyPbxAccountId <= 0) {
                        continue;
                    }

                    $startedAtUtc = CarbonImmutable::parse($call->started_at, 'UTC');
                    $startedAtLocal = $startedAtUtc->setTimezone($timezone);

                    $weekStart = $startedAtLocal->startOfWeek(CarbonImmutable::MONDAY);
                    $weekStartDate = $weekStart->toDateString();

                    $serverId = is_string($call->server_id) && $call->server_id !== '' ? $call->server_id : null;

                    $key = $companyPbxAccountId.'|'.($serverId ?? '').'|'.$weekStartDate;

                    if (! isset($accumulators[$key])) {
                        $accumulators[$key] = [
                            'company_pbx_account_id' => $companyPbxAccountId,
                            'server_id' => $serverId,
                            'week_start_date' => $weekStartDate,
                            'total_calls' => 0,
                            'answered_calls' => 0,
                            'missed_calls' => 0,
                            'calls_with_transcription' => 0,
                            'total_call_duration_seconds' => 0,
                            'first_call_at' => null,
                            'last_call_at' => null,
                            // New metrics accumulators
                            'category_counts' => [],
                            'category_breakdowns' => [],
                            'did_counts' => [],
                            'hourly_distribution' => [],
                        ];
                    }

                    $accumulators[$key]['total_calls']++;

                    $duration = (int) ($call->duration_seconds ?? 0);
                    $accumulators[$key]['total_call_duration_seconds'] += $duration;

                    $status = (string) ($call->status ?? '');
                    if (strtolower($status) === 'answered') {
                        $accumulators[$key]['answered_calls']++;
                    } else {
                        $accumulators[$key]['missed_calls']++;
                    }

                    $transcript = is_string($call->transcript_text ?? null) ? trim($call->transcript_text) : '';
                    if ($transcript !== '') {
                        $accumulators[$key]['calls_with_transcription']++;
                    }

                    $first = $accumulators[$key]['first_call_at'];
                    if (! $first || $startedAtUtc->lt($first)) {
                        $accumulators[$key]['first_call_at'] = $startedAtUtc;
                    }

                    $last = $accumulators[$key]['last_call_at'];
                    if (! $last || $startedAtUtc->gt($last)) {
                        $accumulators[$key]['last_call_at'] = $startedAtUtc;
                    }

                    // Category counts
                    $category = is_string($call->category ?? null) ? trim($call->category) : '';
                    if ($category !== '') {
                        if (! isset($accumulators[$key]['category_counts'][$category])) {
                            $accumulators[$key]['category_counts'][$category] = 0;
                        }
                        $accumulators[$key]['category_counts'][$category]++;

                        // Sub-category counts per category
                        $subCategory = is_string($call->sub_category ?? null) ? trim($call->sub_category) : '';
                        if ($subCategory !== '') {
                            if (! isset($accumulators[$key]['category_breakdowns'][$category])) {
                                $accumulators[$key]['category_breakdowns'][$category] = [];
                            }
                            if (! isset($accumulators[$key]['category_breakdowns'][$category][$subCategory])) {
                                $accumulators[$key]['category_breakdowns'][$category][$subCategory] = 0;
                            }
                            $accumulators[$key]['category_breakdowns'][$category][$subCategory]++;
                        }
                    }

                    // DID counts (for top 10 DIDs by call volume)
                    $did = is_string($call->did ?? null) ? trim($call->did) : '';
                    if ($did !== '') {
                        if (! isset($accumulators[$key]['did_counts'][$did])) {
                            $accumulators[$key]['did_counts'][$did] = 0;
                        }
                        $accumulators[$key]['did_counts'][$did]++;
                    }

                    // Hourly distribution (0-23)
                    $hour = (int) $startedAtLocal->format('H');
                    if (! isset($accumulators[$key]['hourly_distribution'][$hour])) {
                        $accumulators[$key]['hourly_distribution'][$hour] = 0;
                    }
                    $accumulators[$key]['hourly_distribution'][$hour]++;
                }
            });

            foreach ($accumulators as $weekly) {
                $weekStart = CarbonImmutable::parse($weekly['week_start_date'], $timezone)
                    ->startOfWeek(CarbonImmutable::MONDAY);

                $weekEnd = $weekStart->addDays(6);

                $totalCalls = (int) $weekly['total_calls'];
                $totalDuration = (int) $weekly['total_call_duration_seconds'];

                $avgDuration = $totalCalls > 0 ? intdiv($totalDuration, $totalCalls) : 0;

                // Prepare top 10 DIDs by call volume
                $didCounts = $weekly['did_counts'];
                arsort($didCounts);
                $topDids = [];
                $count = 0;
                foreach ($didCounts as $did => $callCount) {
                    if ($count >= 10) {
                        break;
                    }
                    $topDids[] = [
                        'did' => $did,
                        'calls' => $callCount,
                    ];
                    $count++;
                }

                // Prepare hourly distribution (ensure all hours 0-23 are present)
                $hourlyDistribution = [];
                for ($h = 0; $h < 24; $h++) {
                    $hourlyDistribution[$h] = $weekly['hourly_distribution'][$h] ?? 0;
                }

                // Fetch sample calls for each category
                $categories = array_keys($weekly['category_counts']);
                $sampleCallsByCategory = $this->fetchSampleCallsByCategory(
                    $companyId,
                    (int) $weekly['company_pbx_account_id'],
                    $weekStart->toDateString(),
                    $weekEnd->toDateString(),
                    $categories
                );

                // Build category_breakdowns with counts, sub_categories, and sample_calls
                $categoryBreakdowns = [];
                foreach ($weekly['category_counts'] as $category => $count) {
                    $categoryBreakdowns[$category] = [
                        'count' => $count,
                        'sub_categories' => $weekly['category_breakdowns'][$category] ?? [],
                        'sample_calls' => $sampleCallsByCategory[$category] ?? [],
                    ];
                }

                // Generate rule-based insights
                $insights = $this->generateInsights(
                    $totalCalls,
                    (int) $weekly['answered_calls'],
                    (int) $weekly['missed_calls'],
                    $weekly['category_counts'],
                    $weekly['category_breakdowns'],
                    $hourlyDistribution
                );

                // Build metrics JSON structure
                $metrics = [
                    'category_counts' => $weekly['category_counts'],
                    'category_breakdowns' => $categoryBreakdowns,
                    'top_dids' => $topDids,
                    'hourly_distribution' => $hourlyDistribution,
                    'insights' => $insights,
                ];

                // Generate executive summary
                $executiveSummary = $this->generateExecutiveSummary(
                    $weekStart,
                    $weekEnd,
                    $totalCalls,
                    (int) $weekly['answered_calls'],
                    (int) $weekly['missed_calls'],
                    $avgDuration,
                    $weekly['category_counts']
                );

                $reportModel = WeeklyCallReport::query()->updateOrCreate(
                    [
                        'company_id' => $companyId,
                        'company_pbx_account_id' => (int) $weekly['company_pbx_account_id'],
                        'week_start_date' => $weekStart->toDateString(),
                    ],
                    [
                        // Persist server_id for visibility and accurate PBX mapping
                        'server_id' => $weekly['server_id'] ?? null,
                        // Legacy columns kept for compatibility with older schema consumers.
                        'week_end_date' => $weekEnd->toDateString(),
                        'reporting_period_start' => $weekStart->toDateString(),
                        'reporting_period_end' => $weekEnd->toDateString(),
                        'total_calls' => $totalCalls,
                        'answered_calls' => (int) $weekly['answered_calls'],
                        'missed_calls' => (int) $weekly['missed_calls'],
                        'calls_with_transcription' => (int) $weekly['calls_with_transcription'],
                        'total_call_duration_seconds' => $totalDuration,
                        'avg_call_duration_seconds' => $avgDuration,
                        'first_call_at' => ($weekly['first_call_at'] instanceof CarbonImmutable)
                            ? $weekly['first_call_at']->toDateTimeString()
                            : null,
                        'last_call_at' => ($weekly['last_call_at'] instanceof CarbonImmutable)
                            ? $weekly['last_call_at']->toDateTimeString()
                            : null,
                        'metrics' => $metrics,
                        'executive_summary' => $executiveSummary,
                    ],
                );

                // Mark included calls as belonging to this weekly report
                try {
                    $markQuery = DB::table('calls')
                        ->where('company_id', $companyId)
                        ->where('company_pbx_account_id', (int) $weekly['company_pbx_account_id'])
                        ->whereDate('started_at', '>=', $weekStart->toDateString())
                        ->whereDate('started_at', '<=', $weekEnd->toDateString())
                        ->whereNull('weekly_call_report_id');

                    if (! empty($weekly['server_id'])) {
                        $markQuery->where('server_id', $weekly['server_id']);
                    }

                    $markQuery->update(['weekly_call_report_id' => $reportModel->id]);
                } catch (\Throwable $e) {
                    // Non-fatal: indexing of calls to reports should not stop report generation
                }
            }
        }
    }

    /**
     * @return Builder
     */
    private function baseCallsQuery(int $companyId): Builder
    {
        return DB::table('calls')
            ->select([
                'server_id',
                'company_pbx_account_id',
                'status',
                'started_at',
                'duration_seconds',
                'transcript_text',
                'did',
                'category',
                'sub_category',
                'weekly_call_report_id',
            ])
            ->where('company_id', $companyId);
    }

    /**
     * Fetch 3-5 sample calls for each category.
     *
     * Selection criteria (priority order):
     * 1. Non-empty transcript_text (required)
     * 2. Valid DID (prefer non-null/non-empty)
     * 3. Longer transcript (order by length descending)
     *
     * @param  array<string>  $categories
     * @return array<string, array> category => sample_calls
     */
    private function fetchSampleCallsByCategory(
        int $companyId,
        int $companyPbxAccountId,
        string $weekStartDate,
        string $weekEndDate,
        array $categories
    ): array {
        $samplesByCategory = [];

        foreach ($categories as $category) {
            $samples = DB::table('calls')
                ->select([
                    'started_at',
                    'did',
                    'from as src',
                    'transcript_text',
                ])
                ->where('company_id', $companyId)
                ->where('company_pbx_account_id', $companyPbxAccountId)
                ->where('category', $category)
                ->whereDate('started_at', '>=', $weekStartDate)
                ->whereDate('started_at', '<=', $weekEndDate)
                ->whereNotNull('transcript_text')
                ->where('transcript_text', '!=', '')
                // Prioritize: valid DID first, then by transcript length
                ->orderByRaw("CASE WHEN did IS NOT NULL AND did != '' THEN 0 ELSE 1 END")
                ->orderByRaw('LENGTH(transcript_text) DESC')
                ->limit(5)
                ->get();

            $samplesByCategory[$category] = $samples->map(function ($call) {
                $transcript = is_string($call->transcript_text) ? $call->transcript_text : '';

                return [
                    'date' => $call->started_at,
                    'did' => $call->did ?? '',
                    'src' => $call->src ?? '',
                    'transcript' => mb_strlen($transcript) > 300
                        ? mb_substr($transcript, 0, 300).'...'
                        : $transcript,
                ];
            })->values()->toArray();
        }

        return $samplesByCategory;
    }

    /**
     * Generate a programmatic executive summary for the weekly report.
     *
     * Includes: week range, total call volume, answer rate, top category.
     * Professional, neutral tone. No AI generation.
     *
     * @param  array<string, int>  $categoryCounts
     */
    private function generateExecutiveSummary(
        CarbonImmutable $weekStart,
        CarbonImmutable $weekEnd,
        int $totalCalls,
        int $answeredCalls,
        int $missedCalls,
        int $avgDurationSeconds,
        array $categoryCounts
    ): string {
        // Format week range (e.g., "January 20–26, 2026" or "December 30, 2025 – January 5, 2026")
        $weekRange = $this->formatWeekRange($weekStart, $weekEnd);

        // Handle zero calls case
        if ($totalCalls === 0) {
            return "For the week of {$weekRange}, no calls were recorded.";
        }

        // Calculate answer rate
        $answerRate = round(($answeredCalls / $totalCalls) * 100, 1);

        // Format average duration
        $avgDurationFormatted = $this->formatDuration($avgDurationSeconds);

        // Determine top category
        $topCategory = null;
        $topCategoryCount = 0;
        $topCategoryPercent = 0;

        if (! empty($categoryCounts)) {
            arsort($categoryCounts);
            $topCategory = array_key_first($categoryCounts);
            $topCategoryCount = $categoryCounts[$topCategory];
            $topCategoryPercent = round(($topCategoryCount / $totalCalls) * 100, 1);
        }

        // Build summary sentences
        $sentences = [];

        // Sentence 1: Week range and total volume
        $callWord = $totalCalls === 1 ? 'call' : 'calls';
        $sentences[] = "For the week of {$weekRange}, a total of {$totalCalls} {$callWord} were recorded.";

        // Sentence 2: Answer rate and average duration
        $sentences[] = "The answer rate was {$answerRate}% with an average call duration of {$avgDurationFormatted}.";

        // Sentence 3: Top category (if available)
        if ($topCategory !== null) {
            $sentences[] = "The most common call category was \"{$topCategory}\" accounting for {$topCategoryCount} calls ({$topCategoryPercent}% of total volume).";
        }

        // Sentence 4: Missed calls note (if significant)
        if ($missedCalls > 0 && $answerRate < 90) {
            $sentences[] = "{$missedCalls} calls were missed or unanswered during this period.";
        }

        return implode(' ', $sentences);
    }

    /**
     * Format the week range for display.
     *
     * Examples:
     * - Same month: "January 20–26, 2026"
     * - Different months, same year: "January 27 – February 2, 2026"
     * - Different years: "December 30, 2025 – January 5, 2026"
     */
    private function formatWeekRange(CarbonImmutable $start, CarbonImmutable $end): string
    {
        if ($start->year !== $end->year) {
            // Different years: "December 30, 2025 – January 5, 2026"
            return $start->format('F j, Y').' – '.$end->format('F j, Y');
        }

        if ($start->month !== $end->month) {
            // Different months, same year: "January 27 – February 2, 2026"
            return $start->format('F j').' – '.$end->format('F j, Y');
        }

        // Same month: "January 20–26, 2026"
        return $start->format('F j').'–'.$end->format('j, Y');
    }

    /**
     * Format duration in seconds to human-readable format.
     *
     * Examples:
     * - 45 seconds: "45 seconds"
     * - 90 seconds: "1 minute 30 seconds"
     * - 3661 seconds: "1 hour 1 minute"
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds === 1 ? '1 second' : "{$seconds} seconds";
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        $parts = [];

        if ($hours > 0) {
            $parts[] = $hours === 1 ? '1 hour' : "{$hours} hours";
        }

        if ($minutes > 0) {
            $parts[] = $minutes === 1 ? '1 minute' : "{$minutes} minutes";
        }

        // Only include seconds if no hours and there are remaining seconds
        if ($hours === 0 && $remainingSeconds > 0) {
            $parts[] = $remainingSeconds === 1 ? '1 second' : "{$remainingSeconds} seconds";
        }

        return implode(' ', $parts);
    }

    /**
     * Generate rule-based insights from weekly metrics.
     *
     * Rules applied:
     * - Category > 30% of total → automation candidate
     * - Highlight top sub-category per category
     * - Low answer rate detection (< 80%)
     * - Peak hour identification
     * - High missed call volume detection
     *
     * @param  array<string, int>  $categoryCounts
     * @param  array<string, array<string, int>>  $categoryBreakdowns
     * @param  array<int, int>  $hourlyDistribution
     * @return array{ai_opportunities: array, recommendations: array}
     */
    private function generateInsights(
        int $totalCalls,
        int $answeredCalls,
        int $missedCalls,
        array $categoryCounts,
        array $categoryBreakdowns,
        array $hourlyDistribution
    ): array {
        $aiOpportunities = [];
        $recommendations = [];

        if ($totalCalls === 0) {
            return [
                'ai_opportunities' => [],
                'recommendations' => [],
            ];
        }

        $answerRate = ($answeredCalls / $totalCalls) * 100;

        // Rule 1: Categories > 30% are automation candidates
        foreach ($categoryCounts as $category => $count) {
            $percentage = ($count / $totalCalls) * 100;

            if ($percentage >= 30) {
                $topSubCategory = $this->getTopSubCategory($category, $categoryBreakdowns);

                $opportunity = [
                    'type' => 'automation_candidate',
                    'category' => $category,
                    'call_count' => $count,
                    'percentage' => round($percentage, 1),
                    'reason' => "High volume category representing {$this->formatPercentage($percentage)} of total calls.",
                ];

                if ($topSubCategory !== null) {
                    $opportunity['top_sub_category'] = $topSubCategory['name'];
                    $opportunity['top_sub_category_count'] = $topSubCategory['count'];
                    $opportunity['top_sub_category_percentage'] = $topSubCategory['percentage'];
                }

                $aiOpportunities[] = $opportunity;
            }
        }

        // Rule 2: Highlight top sub-category for each major category (top 3)
        arsort($categoryCounts);
        $topCategories = array_slice(array_keys($categoryCounts), 0, 3, true);

        foreach ($topCategories as $category) {
            $topSubCategory = $this->getTopSubCategory($category, $categoryBreakdowns);

            if ($topSubCategory !== null) {
                // Check if not already captured in automation candidates
                $alreadyCaptured = false;
                foreach ($aiOpportunities as $opp) {
                    if (($opp['category'] ?? '') === $category) {
                        $alreadyCaptured = true;
                        break;
                    }
                }

                if (! $alreadyCaptured) {
                    $aiOpportunities[] = [
                        'type' => 'sub_category_highlight',
                        'category' => $category,
                        'top_sub_category' => $topSubCategory['name'],
                        'top_sub_category_count' => $topSubCategory['count'],
                        'top_sub_category_percentage' => $topSubCategory['percentage'],
                        'reason' => "Top sub-category within \"{$category}\" calls.",
                    ];
                }
            }
        }

        // Rule 3: Low answer rate recommendation
        if ($answerRate < 80) {
            $recommendations[] = [
                'type' => 'low_answer_rate',
                'metric' => round($answerRate, 1),
                'threshold' => 80,
                'message' => "Answer rate of {$this->formatPercentage($answerRate)} is below the 80% threshold. Consider reviewing staffing levels or call routing.",
            ];
        }

        // Rule 4: High missed call volume
        $missedRate = ($missedCalls / $totalCalls) * 100;
        if ($missedCalls > 20 && $missedRate > 15) {
            $recommendations[] = [
                'type' => 'high_missed_calls',
                'missed_count' => $missedCalls,
                'missed_percentage' => round($missedRate, 1),
                'message' => "{$missedCalls} calls ({$this->formatPercentage($missedRate)}) were missed. Review peak hours for potential staffing adjustments.",
            ];
        }

        // Rule 5: Peak hour identification
        $peakHours = $this->identifyPeakHours($hourlyDistribution, $totalCalls);
        if (! empty($peakHours)) {
            $peakHoursList = implode(', ', array_map(fn ($h) => $this->formatHour($h), $peakHours));
            $recommendations[] = [
                'type' => 'peak_hours',
                'hours' => $peakHours,
                'message' => "Peak call hours identified: {$peakHoursList}. Ensure adequate staffing during these periods.",
            ];
        }

        // Rule 6: After-hours call detection
        $afterHoursCalls = $this->countAfterHoursCalls($hourlyDistribution);
        $afterHoursRate = ($afterHoursCalls / $totalCalls) * 100;
        if ($afterHoursRate > 10) {
            $recommendations[] = [
                'type' => 'after_hours_volume',
                'call_count' => $afterHoursCalls,
                'percentage' => round($afterHoursRate, 1),
                'message' => "{$afterHoursCalls} calls ({$this->formatPercentage($afterHoursRate)}) occurred outside business hours (before 8am or after 6pm). Consider after-hours automation or voicemail optimization.",
            ];
        }

        return [
            'ai_opportunities' => $aiOpportunities,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Get the top sub-category for a given category.
     *
     * @param  array<string, array<string, int>>  $categoryBreakdowns
     * @return array{name: string, count: int, percentage: float}|null
     */
    private function getTopSubCategory(string $category, array $categoryBreakdowns): ?array
    {
        if (! isset($categoryBreakdowns[$category]) || empty($categoryBreakdowns[$category])) {
            return null;
        }

        $subCategories = $categoryBreakdowns[$category];
        arsort($subCategories);

        $topName = array_key_first($subCategories);
        $topCount = $subCategories[$topName];
        $totalInCategory = array_sum($subCategories);

        return [
            'name' => $topName,
            'count' => $topCount,
            'percentage' => round(($topCount / $totalInCategory) * 100, 1),
        ];
    }

    /**
     * Identify peak hours (hours with > 10% of daily volume).
     *
     * @param  array<int, int>  $hourlyDistribution
     * @return array<int>
     */
    private function identifyPeakHours(array $hourlyDistribution, int $totalCalls): array
    {
        if ($totalCalls === 0) {
            return [];
        }

        $threshold = $totalCalls * 0.10; // 10% of total
        $peakHours = [];

        foreach ($hourlyDistribution as $hour => $count) {
            if ($count >= $threshold) {
                $peakHours[] = (int) $hour;
            }
        }

        sort($peakHours);

        return $peakHours;
    }

    /**
     * Count calls outside business hours (before 8am or after 6pm).
     *
     * @param  array<int, int>  $hourlyDistribution
     */
    private function countAfterHoursCalls(array $hourlyDistribution): int
    {
        $count = 0;

        foreach ($hourlyDistribution as $hour => $calls) {
            // Before 8am or after 6pm (18:00)
            if ($hour < 8 || $hour >= 18) {
                $count += $calls;
            }
        }

        return $count;
    }

    /**
     * Format hour for display (e.g., 9 → "9am", 14 → "2pm").
     */
    private function formatHour(int $hour): string
    {
        if ($hour === 0) {
            return '12am';
        }
        if ($hour === 12) {
            return '12pm';
        }
        if ($hour < 12) {
            return "{$hour}am";
        }

        return ($hour - 12).'pm';
    }

    /**
     * Format percentage for display.
     */
    private function formatPercentage(float $value): string
    {
        return round($value, 1).'%';
    }
}
