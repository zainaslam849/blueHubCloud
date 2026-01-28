<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WeeklyReportAggregationService
{
    private const SHORT_CALL_THRESHOLD_SECONDS = 15;

    /**
     * Aggregate and upsert weekly call report snapshots for a company.
     *
     * Idempotent: re-running will recompute and overwrite the same weekly rows.
     *
     * @return int Number of weekly report rows upserted.
     */
    public function aggregateCompany(
        int $companyId,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): int {
        $company = DB::table('companies')
            ->select(['id', 'timezone'])
            ->where('id', $companyId)
            ->first();

        if (! $company) {
            return 0;
        }

        $timezone = is_string($company->timezone) && $company->timezone !== '' ? $company->timezone : 'UTC';

        $query = DB::table('calls')
            ->select([
                'id',
                'company_id',
                'duration_seconds',
                'status',
                'from',
                'to',
                'started_at',
                'category_id',
                'sub_category_id',
                'sub_category_label',
                'category_source',
                'category_confidence',
            ])
            ->where('company_id', $companyId);

        if ($from) {
            $query->where('started_at', '>=', $from->utc()->toDateTimeString());
        }

        if ($to) {
            $query->where('started_at', '<=', $to->utc()->toDateTimeString());
        }

        $accumulators = [];

        $query->orderBy('started_at')->chunk(2000, function ($calls) use (&$accumulators, $timezone) {
            foreach ($calls as $call) {
                if (! $call->started_at) {
                    continue;
                }

                $startedAt = CarbonImmutable::parse($call->started_at, 'UTC')->setTimezone($timezone);
                $weekStart = $startedAt->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
                $weekEnd = $startedAt->startOfWeek(CarbonImmutable::MONDAY)->addDays(6)->toDateString();

                $key = $weekStart.'|'.$weekEnd;

                if (! isset($accumulators[$key])) {
                    $accumulators[$key] = [
                        'reporting_period_start' => $weekStart,
                        'reporting_period_end' => $weekEnd,
                        'total_calls' => 0,
                        'total_duration_seconds' => 0,
                        'unresolved_calls_count' => 0,
                        'short_calls_count' => 0,
                        'top_extensions' => [],
                        'call_ids' => [],
                        'category_counts' => [],
                        'category_details' => [],
                    ];
                }

                $accumulators[$key]['total_calls']++;
                $accumulators[$key]['total_duration_seconds'] += (int) ($call->duration_seconds ?? 0);

                $status = (string) ($call->status ?? '');
                if (in_array($status, ['missed', 'failed'], true)) {
                    $accumulators[$key]['unresolved_calls_count']++;
                }

                if ((int) ($call->duration_seconds ?? 0) > 0 && (int) ($call->duration_seconds ?? 0) <= self::SHORT_CALL_THRESHOLD_SECONDS) {
                    $accumulators[$key]['short_calls_count']++;
                }

                $extension = $this->extractExtension($call->from) ?? $this->extractExtension($call->to);
                if ($extension) {
                    $accumulators[$key]['top_extensions'][$extension] = ($accumulators[$key]['top_extensions'][$extension] ?? 0) + 1;
                }

                // Track category data (use stored values only, never re-run AI)
                $categoryId = $call->category_id ? (int) $call->category_id : null;
                if ($categoryId) {
                    if (!isset($accumulators[$key]['category_counts'][$categoryId])) {
                        $accumulators[$key]['category_counts'][$categoryId] = 0;
                    }
                    $accumulators[$key]['category_counts'][$categoryId]++;

                    if (!isset($accumulators[$key]['category_details'][$categoryId])) {
                        $accumulators[$key]['category_details'][$categoryId] = [
                            'total_calls' => 0,
                            'total_duration' => 0,
                            'sub_categories' => [],
                            'sources' => [],
                            'avg_confidence' => 0,
                            'confidence_sum' => 0,
                        ];
                    }

                    $accumulators[$key]['category_details'][$categoryId]['total_calls']++;
                    $accumulators[$key]['category_details'][$categoryId]['total_duration'] += (int) ($call->duration_seconds ?? 0);

                    // Track sub-category
                    $subCategoryId = $call->sub_category_id ? (int) $call->sub_category_id : null;
                    $subCategoryLabel = $call->sub_category_label ? (string) $call->sub_category_label : null;
                    if ($subCategoryId || $subCategoryLabel) {
                        $subKey = $subCategoryId ? "id:{$subCategoryId}" : "label:{$subCategoryLabel}";
                        if (!isset($accumulators[$key]['category_details'][$categoryId]['sub_categories'][$subKey])) {
                            $accumulators[$key]['category_details'][$categoryId]['sub_categories'][$subKey] = 0;
                        }
                        $accumulators[$key]['category_details'][$categoryId]['sub_categories'][$subKey]++;
                    }

                    // Track source
                    $source = $call->category_source ? (string) $call->category_source : 'unknown';
                    if (!isset($accumulators[$key]['category_details'][$categoryId]['sources'][$source])) {
                        $accumulators[$key]['category_details'][$categoryId]['sources'][$source] = 0;
                    }
                    $accumulators[$key]['category_details'][$categoryId]['sources'][$source]++;

                    // Track confidence for averaging
                    if ($call->category_confidence !== null) {
                        $accumulators[$key]['category_details'][$categoryId]['confidence_sum'] += (float) $call->category_confidence;
                    }
                }

                $accumulators[$key]['call_ids'][] = (int) $call->id;
            }
        });

        $rowsUpserted = 0;

        foreach ($accumulators as $weekly) {
            $topicCounts = $this->computeTopTopicsFromTranscripts($weekly['call_ids']);
            $topExtensions = $this->topN($weekly['top_extensions'], 10);
            $categoryMetrics = $this->buildCategoryMetrics($weekly['category_counts'], $weekly['category_details']);

            DB::table('weekly_call_reports')->upsert(
                [[
                    'company_id' => $companyId,
                    'reporting_period_start' => $weekly['reporting_period_start'],
                    'reporting_period_end' => $weekly['reporting_period_end'],
                    'total_calls' => $weekly['total_calls'],
                    'total_duration_seconds' => $weekly['total_duration_seconds'],
                    'unresolved_calls_count' => $weekly['unresolved_calls_count'],
                    'top_extensions' => $topExtensions ? json_encode($topExtensions) : null,
                    'top_call_topics' => $topicCounts ? json_encode($topicCounts) : null,
                    'metadata' => json_encode([
                        'short_calls_count' => $weekly['short_calls_count'],
                        'short_call_threshold_seconds' => self::SHORT_CALL_THRESHOLD_SECONDS,
                        'source' => 'WeeklyReportAggregationService',
                        'category_counts' => $categoryMetrics['category_counts'],
                        'category_breakdowns' => $categoryMetrics['category_breakdowns'],
                    ]),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]],
                ['company_id', 'reporting_period_start', 'reporting_period_end'],
                [
                    'total_calls',
                    'total_duration_seconds',
                    'unresolved_calls_count',
                    'top_extensions',
                    'top_call_topics',
                    'metadata',
                    'updated_at',
                ],
            );

            $rowsUpserted++;
        }

        return $rowsUpserted;
    }

    private function extractExtension(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (! ctype_digit($trimmed)) {
            return null;
        }

        $len = strlen($trimmed);
        if ($len < 2 || $len > 6) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Compute basic topic counts from transcripts (simple keyword frequency).
     *
     * This is intentionally lightweight and deterministic; replace with NLP later.
     *
     * @param  int[]  $callIds
     * @return array<int, array{topic:string,mentions:int}>
     */
    private function computeTopTopicsFromTranscripts(array $callIds): array
    {
        if ($callIds === []) {
            return [];
        }

        $stopwords = [
            'the', 'a', 'an', 'and', 'or', 'to', 'of', 'in', 'on', 'for', 'with', 'is', 'are', 'was', 'were',
            'it', 'this', 'that', 'you', 'we', 'i', 'they', 'he', 'she', 'them', 'us', 'my', 'your', 'our',
            'as', 'at', 'by', 'be', 'from', 'not', 'but', 'so', 'if', 'then', 'there', 'here', 'can', 'could',
        ];
        $stop = array_fill_keys($stopwords, true);

        $counts = [];

        DB::table('calls')
            ->select(['transcript_text'])
            ->whereIn('id', $callIds)
            ->where('has_transcription', true)
            ->orderBy('id')
            ->chunk(200, function ($rows) use (&$counts, $stop) {
                foreach ($rows as $row) {
                    $text = is_string($row->transcript_text ?? null) ? $row->transcript_text : '';
                    if ($text === '') {
                        continue;
                    }

                    $normalized = Str::lower($text);
                    $normalized = preg_replace('/[^a-z0-9\s]+/u', ' ', $normalized) ?? $normalized;
                    $parts = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

                    foreach ($parts as $token) {
                        if (isset($stop[$token])) {
                            continue;
                        }

                        if (strlen($token) < 4) {
                            continue;
                        }

                        $counts[$token] = ($counts[$token] ?? 0) + 1;
                    }
                }
            });

        arsort($counts);

        $top = array_slice($counts, 0, 10, true);
        $result = [];
        foreach ($top as $topic => $mentions) {
            $result[] = [
                'topic' => (string) $topic,
                'mentions' => (int) $mentions,
            ];
        }

        return $result;
    }

    /**
     * @param  array<string,int>  $counts
     * @return array<int, array{key:string,count:int}>
     */
    private function topN(array $counts, int $n): array
    {
        if ($counts === []) {
            return [];
        }

        arsort($counts);
        $top = array_slice($counts, 0, $n, true);

        $result = [];
        foreach ($top as $key => $count) {
            $result[] = [
                'key' => (string) $key,
                'count' => (int) $count,
            ];
        }

        return $result;
    }

    /**
     * Build category metrics from accumulated data.
     * Uses ONLY stored category data from calls table - never runs AI.
     *
     * @param  array<int,int>  $categoryCounts
     * @param  array<int,array>  $categoryDetails
     * @return array{category_counts:array,category_breakdowns:array}
     */
    private function buildCategoryMetrics(array $categoryCounts, array $categoryDetails): array
    {
        if (empty($categoryCounts)) {
            return [
                'category_counts' => [],
                'category_breakdowns' => [],
            ];
        }

        // Load category names from database
        $categoryIds = array_keys($categoryCounts);
        $categories = DB::table('call_categories')
            ->select(['id', 'name'])
            ->whereIn('id', $categoryIds)
            ->get()
            ->keyBy('id');

        // Load sub-category names
        $subCategoryIds = [];
        foreach ($categoryDetails as $details) {
            foreach (array_keys($details['sub_categories']) as $subKey) {
                if (str_starts_with($subKey, 'id:')) {
                    $subCategoryIds[] = (int) substr($subKey, 3);
                }
            }
        }

        $subCategories = [];
        if (!empty($subCategoryIds)) {
            $subCategories = DB::table('sub_categories')
                ->select(['id', 'name'])
                ->whereIn('id', $subCategoryIds)
                ->get()
                ->keyBy('id');
        }

        // Build category_counts array
        $counts = [];
        foreach ($categoryCounts as $categoryId => $count) {
            $categoryName = $categories[$categoryId]->name ?? "Unknown (ID: {$categoryId})";
            $counts[] = [
                'category_id' => $categoryId,
                'category_name' => $categoryName,
                'call_count' => $count,
            ];
        }

        // Sort by call count descending
        usort($counts, fn($a, $b) => $b['call_count'] <=> $a['call_count']);

        // Build category_breakdowns array with detailed metrics
        $breakdowns = [];
        foreach ($categoryDetails as $categoryId => $details) {
            $categoryName = $categories[$categoryId]->name ?? "Unknown (ID: {$categoryId})";

            // Calculate average confidence
            $avgConfidence = null;
            if ($details['total_calls'] > 0 && $details['confidence_sum'] > 0) {
                $avgConfidence = round($details['confidence_sum'] / $details['total_calls'], 2);
            }

            // Format sub-categories
            $subCategoriesFormatted = [];
            foreach ($details['sub_categories'] as $subKey => $subCount) {
                if (str_starts_with($subKey, 'id:')) {
                    $subId = (int) substr($subKey, 3);
                    $subName = $subCategories[$subId]->name ?? "Unknown (ID: {$subId})";
                    $subCategoriesFormatted[] = [
                        'id' => $subId,
                        'name' => $subName,
                        'count' => $subCount,
                    ];
                } else if (str_starts_with($subKey, 'label:')) {
                    $label = substr($subKey, 6);
                    $subCategoriesFormatted[] = [
                        'id' => null,
                        'name' => $label,
                        'count' => $subCount,
                    ];
                }
            }

            // Sort sub-categories by count
            usort($subCategoriesFormatted, fn($a, $b) => $b['count'] <=> $a['count']);

            $breakdowns[] = [
                'category_id' => $categoryId,
                'category_name' => $categoryName,
                'total_calls' => $details['total_calls'],
                'total_duration_seconds' => $details['total_duration'],
                'avg_confidence' => $avgConfidence,
                'sources' => $details['sources'],
                'sub_categories' => $subCategoriesFormatted,
            ];
        }

        // Sort breakdowns by total calls descending
        usort($breakdowns, fn($a, $b) => $b['total_calls'] <=> $a['total_calls']);

        return [
            'category_counts' => $counts,
            'category_breakdowns' => $breakdowns,
        ];
    }
}
