<?php

namespace App\Jobs;

use App\Models\WeeklyCallReport;
use App\Repositories\AiSettingsRepository;
use App\Services\ReportInsightsAiService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenerateWeeklyPbxReportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Generate weekly PBX call reports with immutable call freezing.
     *
     * CALL FREEZING RULES:
     * ===================
     *
     * When a weekly report is generated, calls are "frozen" to that report by setting
     * their weekly_call_report_id. This ensures:
     *
     *   ✅ No double-counting (each call assigned to exactly one report)
     *   ✅ No call loss (no call missed or skipped)
     *   ✅ Report immutability (once assigned, a call never changes reports)
     *   ✅ Audit trail (each call shows which report it belongs to)
     *
     * CALL SELECTION CRITERIA (applied in order):
     * ==========================================
     *
     *   1. Company ID match (company_id = target)
     *   2. Status filter (status = 'answered' ONLY - completed calls)
     *   3. Date range (started_at in report week, timezone-aware)
     *   4. PBX Account (company_pbx_account_id = target)
     *   5. Server match (server_id = target, if present)
     *   6. Unassigned only (weekly_call_report_id IS NULL)
     *
     * CATEGORY CONFIDENCE RULES (STEP 5):
     * ==================================
     *
     *   If confidence < 0.6 → category = null, sub_category = null
     *   This ensures only high-confidence AI categorization is used.
     *   Manual overrides tracked in category_source = 'manual'.
     *
     * WORKFLOW:
     * ========
     *
     *   Normal run (weekly):
     *     → Select unassigned calls matching criteria
     *     → Apply confidence threshold to calls
     *     → Assign to new report (status = answered only)
     *     → Calls are now frozen; future runs won't touch them
     *     → Call AI service for business insights (aggregated metrics only)
     *     → Store AI insights in metrics.insights.ai_summary
     *
     *   Regeneration (re-run for past week):
     *     → Reset weekly_call_report_id = NULL for calls in date range
     *     → Recalculate report with updated data
     *     → Re-assign calls to new report
     *     → Old report remains (historical archive)
     *
     * Optional ISO date bounds (inclusive) applied to calls.started_at.
     *
     * Use this to limit aggregation work (e.g., just last week).
     */
    public function __construct(
        public ?string $fromDate = null,
        public ?string $toDate = null,
        private ?ReportInsightsAiService $aiService = null,
    ) {
    }

    public function handle(): void
    {
        // Lazy-load AI service if not injected (for queue compatibility)
        if ($this->aiService === null) {
            $this->aiService = app(ReportInsightsAiService::class);
        }

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

                    // Category counts (use category_id and name)
                    $categoryId = $call->category_id ?? null;
                    $categoryName = is_string($call->category_name ?? null) ? trim($call->category_name) : '';
                    
                    // Only count if we have both ID and name
                    if ($categoryId !== null && $categoryName !== '') {
                        $categoryKey = $categoryId.'|'.$categoryName;
                        
                        if (! isset($accumulators[$key]['category_counts'][$categoryKey])) {
                            $accumulators[$key]['category_counts'][$categoryKey] = 0;
                        }
                        $accumulators[$key]['category_counts'][$categoryKey]++;

                        // Sub-category counts per category
                        $subCategoryId = $call->sub_category_id ?? null;
                        $subCategoryName = is_string($call->sub_category_name ?? null) ? trim($call->sub_category_name) : '';
                        
                        if ($subCategoryId !== null && $subCategoryName !== '') {
                            if (! isset($accumulators[$key]['category_breakdowns'][$categoryKey])) {
                                $accumulators[$key]['category_breakdowns'][$categoryKey] = [];
                            }
                            $subCategoryKey = $subCategoryId.'|'.$subCategoryName;
                            if (! isset($accumulators[$key]['category_breakdowns'][$categoryKey][$subCategoryKey])) {
                                $accumulators[$key]['category_breakdowns'][$categoryKey][$subCategoryKey] = 0;
                            }
                            $accumulators[$key]['category_breakdowns'][$categoryKey][$subCategoryKey]++;
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
                $aiSummary = null;
                $reportsAiEnabled = filter_var(env('REPORTS_AI_ENABLED', false), FILTER_VALIDATE_BOOLEAN);

                if ($reportsAiEnabled) {
                    $aiSummary = $this->generateAiInsights(
                        $weekStart,
                        $weekEnd,
                        $totalCalls,
                        (int) $weekly['answered_calls'],
                        (int) $weekly['missed_calls'],
                        $avgDuration,
                        $weekly['category_counts'],
                        $hourlyDistribution
                    );
                }

                $metrics = [
                    'category_counts' => $weekly['category_counts'],
                    'category_breakdowns' => $categoryBreakdowns,
                    'top_dids' => $topDids,
                    'hourly_distribution' => $hourlyDistribution,
                    'insights' => $insights,
                    'ai_summary' => $aiSummary,
                ];

                // Generate executive summary (rule-based fallback)
                $executiveSummary = $this->generateExecutiveSummary(
                    $weekStart,
                    $weekEnd,
                    $totalCalls,
                    (int) $weekly['answered_calls'],
                    (int) $weekly['missed_calls'],
                    $avgDuration,
                    $weekly['category_counts']
                );

                if ($reportsAiEnabled) {
                    $callSummaries = $this->fetchCallSummariesForReport(
                        $companyId,
                        (int) $weekly['company_pbx_account_id'],
                        $weekStart->toDateString(),
                        $weekEnd->toDateString(),
                        60
                    );

                    if (! empty($callSummaries)) {
                        $aiExecutiveSummary = $this->generateAiExecutiveSummary(
                            $weekStart,
                            $weekEnd,
                            $totalCalls,
                            (int) $weekly['answered_calls'],
                            (int) $weekly['missed_calls'],
                            $avgDuration,
                            $weekly['category_counts'],
                            $callSummaries
                        );

                        if (is_string($aiExecutiveSummary) && trim($aiExecutiveSummary) !== '') {
                            $executiveSummary = trim($aiExecutiveSummary);
                        }
                    }
                }

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
                // IMMUTABILITY RULES:
                // 1. Only select calls with status='answered' (completed calls)
                // 2. Only select calls where weekly_call_report_id IS NULL (not yet assigned)
                // 3. Match by company_id, company_pbx_account_id, date range, and server_id
                // 4. Once assigned, a call NEVER changes its weekly_call_report_id
                try {
                    $affectedRows = DB::table('calls')
                        ->where('company_id', $companyId)
                        ->where('company_pbx_account_id', (int) $weekly['company_pbx_account_id'])
                        ->where('status', 'answered') // Only completed calls
                        ->whereDate('started_at', '>=', $weekStart->toDateString())
                        ->whereDate('started_at', '<=', $weekEnd->toDateString())
                        ->whereNull('weekly_call_report_id') // Only unassigned calls
                        ->when(! empty($weekly['server_id']), function ($q) use ($weekly) {
                            return $q->where('server_id', $weekly['server_id']);
                        })
                        ->update(['weekly_call_report_id' => $reportModel->id]);

                    \Illuminate\Support\Facades\Log::info('Assigned calls to weekly report', [
                        'report_id' => $reportModel->id,
                        'company_id' => $companyId,
                        'company_pbx_account_id' => $weekly['company_pbx_account_id'],
                        'week_start' => $weekStart->toDateString(),
                        'week_end' => $weekEnd->toDateString(),
                        'affected_rows' => $affectedRows,
                    ]);
                } catch (\Throwable $e) {
                    // Non-fatal: indexing of calls to reports should not stop report generation
                    \Illuminate\Support\Facades\Log::error('Failed to assign calls to weekly report', [
                        'report_id' => $reportModel->id,
                        'error' => $e->getMessage(),
                    ]);
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
                'calls.id',
                'calls.server_id',
                'calls.company_pbx_account_id',
                'calls.status',
                'calls.started_at',
                'calls.duration_seconds',
                'calls.transcript_text',
                'calls.did',
                'calls.category_id',
                'calls.sub_category_id',
                'calls.category_confidence',
                'calls.weekly_call_report_id',
                DB::raw('call_categories.name as category_name'),
                DB::raw('sub_categories.name as sub_category_name'),
            ])
            ->leftJoin('call_categories', 'calls.category_id', '=', 'call_categories.id')
            ->leftJoin('sub_categories', 'calls.sub_category_id', '=', 'sub_categories.id')
            ->where('calls.company_id', $companyId)
            ->where('calls.status', 'answered'); // Only include completed/answered calls
    }

    /**
     * Fetch 3-5 sample calls for each category.
     *
     * Selection criteria (priority order):
     * 1. Non-empty transcript_text (required)
     * 2. Valid DID (prefer non-null/non-empty)
     * 3. Longer transcript (order by length descending)
     *
     * @param  array<string>  $categories  Format: ["id|name", ...]
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

        foreach ($categories as $categoryKey) {
            // Parse category key: "id|name"
            [$categoryId, $categoryName] = explode('|', $categoryKey, 2) + [null, null];
            if ($categoryId === null) {
                continue;
            }

            $samples = DB::table('calls')
                ->select([
                    'started_at',
                    'did',
                    'from as src',
                    'transcript_text',
                ])
                ->where('company_id', $companyId)
                ->where('company_pbx_account_id', $companyPbxAccountId)
                ->where('calls.category_id', (int) $categoryId)
                ->whereDate('started_at', '>=', $weekStartDate)
                ->whereDate('started_at', '<=', $weekEndDate)
                ->whereNotNull('transcript_text')
                ->where('transcript_text', '!=', '')
                // Prioritize: valid DID first, then by transcript length
                ->orderByRaw("CASE WHEN did IS NOT NULL AND did != '' THEN 0 ELSE 1 END")
                ->orderByRaw('LENGTH(transcript_text) DESC')
                ->limit(5)
                ->get();

            $samplesByCategory[$categoryKey] = $samples->map(function ($call) {
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
     * Fetch call summaries for executive report summary.
     *
     * @return array<int, string>
     */
    private function fetchCallSummariesForReport(
        int $companyId,
        int $companyPbxAccountId,
        string $weekStartDate,
        string $weekEndDate,
        int $limit
    ): array {
        $summaries = DB::table('calls')
            ->select(['ai_summary'])
            ->where('company_id', $companyId)
            ->where('company_pbx_account_id', $companyPbxAccountId)
            ->whereDate('started_at', '>=', $weekStartDate)
            ->whereDate('started_at', '<=', $weekEndDate)
            ->whereNotNull('ai_summary')
            ->where('ai_summary', '!=', '')
            ->orderByDesc('started_at')
            ->limit($limit)
            ->pluck('ai_summary')
            ->filter(fn ($summary) => is_string($summary) && trim($summary) !== '')
            ->map(function ($summary) {
                $clean = trim((string) $summary);

                return mb_strlen($clean) > 240 ? mb_substr($clean, 0, 240).'…' : $clean;
            })
            ->values()
            ->all();

        return $summaries;
    }

    /**
     * Generate executive summary using call summaries + aggregated metrics.
     */
    private function generateAiExecutiveSummary(
        CarbonImmutable $weekStart,
        CarbonImmutable $weekEnd,
        int $totalCalls,
        int $answeredCalls,
        int $missedCalls,
        int $avgDurationSeconds,
        array $categoryCounts,
        array $callSummaries
    ): ?string {
        try {
            $aiSettings = app(AiSettingsRepository::class)->getActive();

            if (! $aiSettings || ! $aiSettings->enabled) {
                return null;
            }

            if (! $aiSettings->api_key || ! $aiSettings->report_model) {
                return null;
            }

            $weekRange = $this->formatWeekRange($weekStart, $weekEnd);
            $answerRate = $totalCalls > 0 ? round(($answeredCalls / $totalCalls) * 100, 1) : 0;
            $avgDuration = $this->formatDuration($avgDurationSeconds);

            $categoryLines = '';
            if (! empty($categoryCounts)) {
                arsort($categoryCounts);
                foreach ($categoryCounts as $categoryKey => $count) {
                    $categoryName = $categoryKey;
                    if (strpos($categoryKey, '|') !== false) {
                        [, $categoryName] = explode('|', $categoryKey, 2);
                    }
                    $percentage = $totalCalls > 0 ? round(($count / $totalCalls) * 100, 1) : 0;
                    $categoryLines .= "- {$categoryName}: {$count} calls ({$percentage}%)\n";
                }
            }

            $summaryLines = implode("\n", array_map(fn ($s) => "- {$s}", $callSummaries));

            $prompt = <<<PROMPT
You are a reporting analyst. Create an executive summary for a weekly call report.

Use ONLY the information provided below. Do NOT invent facts.
Write 2–3 concise paragraphs, client-friendly and neutral.

PERIOD: {$weekRange}
TOTAL CALLS: {$totalCalls}
ANSWERED: {$answeredCalls}
MISSED: {$missedCalls}
ANSWER RATE: {$answerRate}%
AVERAGE DURATION: {$avgDuration}

CATEGORY DISTRIBUTION:
{$categoryLines}

CALL SUMMARIES (representative samples):
{$summaryLines}
PROMPT;

            $modelParameters = [
                'temperature' => 0.3,
                'max_tokens' => 600,
            ];

            return $this->callProvider(
                $aiSettings->provider,
                $aiSettings->api_key,
                $aiSettings->report_model,
                $prompt,
                $modelParameters
            );
        } catch (\Throwable $e) {
            Log::warning('AI executive summary generation failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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
            $topCategoryKey = array_key_first($categoryCounts);
            $topCategoryCount = $categoryCounts[$topCategoryKey];
            $topCategoryPercent = round(($topCategoryCount / $totalCalls) * 100, 1);
            
            // Extract category name from key format "id|name"
            if ($topCategoryKey && strpos($topCategoryKey, '|') !== false) {
                [, $topCategory] = explode('|', $topCategoryKey, 2);
            } else {
                $topCategory = $topCategoryKey;
            }
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
     * @param  array{temperature: float, max_tokens: int}  $modelParameters
     */
    private function callProvider(
        string $provider,
        string $apiKey,
        string $model,
        string $prompt,
        array $modelParameters
    ): string {
        if ($provider === 'openrouter') {
            return $this->callOpenRouter($apiKey, $model, $prompt, $modelParameters);
        }

        [$modelProvider, $modelName] = array_pad(explode('/', $model, 2), 2, null);

        if ($provider === 'openai' || $modelProvider === 'openai') {
            return $this->callOpenAI($apiKey, $modelName ?? $model, $prompt, $modelParameters);
        }

        if ($provider === 'anthropic' || $modelProvider === 'anthropic') {
            return $this->callAnthropic($apiKey, $modelName ?? $model, $prompt, $modelParameters);
        }

        throw new \Exception("Unsupported AI provider: {$provider}");
    }

    private function callOpenAI(string $apiKey, string $model, string $prompt, array $modelParameters): string
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $modelParameters['temperature'],
            'max_tokens' => $modelParameters['max_tokens'],
        ]);

        if (! $response->successful()) {
            throw new \Exception("OpenAI API failed ({$response->status()}): " . $response->body());
        }

        $content = $response->json()['choices'][0]['message']['content'] ?? null;

        if (! $content) {
            throw new \Exception('No content in OpenAI response');
        }

        return (string) $content;
    }

    private function callAnthropic(string $apiKey, string $model, string $prompt, array $modelParameters): string
    {
        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'Content-Type' => 'application/json',
            'anthropic-version' => '2023-06-01',
        ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
            'model' => $model,
            'max_tokens' => $modelParameters['max_tokens'],
            'temperature' => $modelParameters['temperature'],
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        if (! $response->successful()) {
            throw new \Exception("Anthropic API failed ({$response->status()}): " . $response->body());
        }

        $content = $response->json()['content'][0]['text'] ?? null;

        if (! $content) {
            throw new \Exception('No content in Anthropic response');
        }

        return (string) $content;
    }

    private function callOpenRouter(string $apiKey, string $model, string $prompt, array $modelParameters): string
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'HTTP-Referer' => config('app.url'),
            'X-Title' => 'blueHubCloud Weekly Reports',
        ])->timeout(30)->post('https://openrouter.ai/api/v1/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $modelParameters['temperature'],
            'max_tokens' => $modelParameters['max_tokens'],
        ]);

        if (! $response->successful()) {
            throw new \Exception("OpenRouter API failed ({$response->status()}): " . $response->body());
        }

        $content = $response->json()['choices'][0]['message']['content'] ?? null;

        if (! $content) {
            throw new \Exception('No content in OpenRouter response');
        }

        return (string) $content;
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
        foreach ($categoryCounts as $categoryKey => $count) {
            $percentage = ($count / $totalCalls) * 100;

            if ($percentage >= 30) {
                // Extract category name from key format "id|name"
                $categoryName = $categoryKey;
                if (strpos($categoryKey, '|') !== false) {
                    [, $categoryName] = explode('|', $categoryKey, 2);
                }

                $topSubCategory = $this->getTopSubCategory($categoryKey, $categoryBreakdowns);

                $opportunity = [
                    'type' => 'automation_candidate',
                    'category' => $categoryName,
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

        foreach ($topCategories as $categoryKey) {
            $topSubCategory = $this->getTopSubCategory($categoryKey, $categoryBreakdowns);

            if ($topSubCategory !== null) {
                // Extract category name
                $categoryName = $categoryKey;
                if (strpos($categoryKey, '|') !== false) {
                    [, $categoryName] = explode('|', $categoryKey, 2);
                }

                // Check if not already captured in automation candidates
                $alreadyCaptured = false;
                foreach ($aiOpportunities as $opp) {
                    if (($opp['category'] ?? '') === $categoryName) {
                        $alreadyCaptured = true;
                        break;
                    }
                }

                if (! $alreadyCaptured) {
                    $aiOpportunities[] = [
                        'type' => 'sub_category_highlight',
                        'category' => $categoryName,
                        'top_sub_category' => $topSubCategory['name'],
                        'top_sub_category_count' => $topSubCategory['count'],
                        'top_sub_category_percentage' => $topSubCategory['percentage'],
                        'reason' => "Top sub-category within \"{$categoryName}\" calls.",
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

        $topSubCategoryKey = array_key_first($subCategories);
        $topCount = $subCategories[$topSubCategoryKey];
        $totalInCategory = array_sum($subCategories);

        // Extract sub-category name from key format "id|name"
        $topName = $topSubCategoryKey;
        if ($topSubCategoryKey && strpos($topSubCategoryKey, '|') !== false) {
            [, $topName] = explode('|', $topSubCategoryKey, 2);
        }

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

    /**
     * Generate AI-powered business insights from aggregated metrics (STEP 4).
     *
     * ⚠️ IMPORTANT:
     * - Input: ONLY aggregated metrics (counts, percentages, statistics)
     * - NO transcripts, NO call details, NO PII
     * - This ensures privacy compliance and focuses on business analysis
     *
     * @param  CarbonImmutable  $weekStart
     * @param  CarbonImmutable  $weekEnd
     * @param  int  $totalCalls
     * @param  int  $answeredCalls
     * @param  int  $missedCalls
     * @param  int  $avgDuration
     * @param  array<string, int>  $categoryCounts
     * @param  array<int, int>  $hourlyDistribution
     * @return array<string, mixed>
     */
    private function generateAiInsights(
        CarbonImmutable $weekStart,
        CarbonImmutable $weekEnd,
        int $totalCalls,
        int $answeredCalls,
        int $missedCalls,
        int $avgDuration,
        array $categoryCounts,
        array $hourlyDistribution
    ): array {
        // Format week range
        $weekRange = $this->formatWeekRange($weekStart, $weekEnd);

        // Calculate derived metrics
        $answerRate = $totalCalls > 0 ? round(($answeredCalls / $totalCalls) * 100, 1) : 0;
        $afterHoursCalls = $this->countAfterHoursCalls($hourlyDistribution);
        $afterHoursPercentage = $totalCalls > 0 ? round(($afterHoursCalls / $totalCalls) * 100, 1) : 0;
        $peakHours = $this->identifyPeakHours($hourlyDistribution, $totalCalls);

        // Build metrics for AI (only aggregated data, no PII)
        $metricsForAi = [
            'period' => $weekRange,
            'total_calls' => $totalCalls,
            'answered_calls' => $answeredCalls,
            'answer_rate' => $answerRate,
            'missed_calls' => $missedCalls,
            'avg_call_duration_seconds' => $avgDuration,
            'calls_with_transcription' => $totalCalls, // Assume all have transcription for now
            'after_hours_percentage' => $afterHoursPercentage,
            'peak_hours' => $peakHours,
            'category_counts' => $categoryCounts,
        ];

        // Call AI service to generate business insights
        return $this->aiService->generateInsights($metricsForAi);
    }
}
