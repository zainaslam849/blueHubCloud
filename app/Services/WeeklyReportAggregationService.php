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
                'from_number',
                'to_number',
                'started_at',
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

                $extension = $this->extractExtension($call->from_number) ?? $this->extractExtension($call->to_number);
                if ($extension) {
                    $accumulators[$key]['top_extensions'][$extension] = ($accumulators[$key]['top_extensions'][$extension] ?? 0) + 1;
                }

                $accumulators[$key]['call_ids'][] = (int) $call->id;
            }
        });

        $rowsUpserted = 0;

        foreach ($accumulators as $weekly) {
            $topicCounts = $this->computeTopTopicsFromTranscripts($weekly['call_ids']);
            $topExtensions = $this->topN($weekly['top_extensions'], 10);

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

        DB::table('call_transcriptions')
            ->select(['transcript_text'])
            ->whereIn('call_id', $callIds)
            ->orderBy('call_id')
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
}
