<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RegenerateAiJob;
use App\Models\Call;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAiRegenerateController extends Controller
{
    public function pendingStats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'steps' => ['nullable', 'array'],
            'steps.*' => ['string', 'in:transcript,summary,categories'],
        ]);

        $steps = $validated['steps'] ?? ['summary', 'categories'];
        $from = isset($validated['from_date'])
            ? CarbonImmutable::parse($validated['from_date'], 'UTC')->startOfDay()
            : null;
        $to = isset($validated['to_date'])
            ? CarbonImmutable::parse($validated['to_date'], 'UTC')->endOfDay()
            : null;

        $baseQuery = Call::query();

        if (isset($validated['company_id'])) {
            $baseQuery->where('company_id', (int) $validated['company_id']);
        }

        if ($from && $to) {
            $baseQuery->whereBetween('started_at', [$from, $to]);
        }

        $summaryPending = in_array('summary', $steps, true)
            ? $this->buildSummaryPendingQuery(clone $baseQuery)->count()
            : 0;

        $categoryPending = in_array('categories', $steps, true)
            ? $this->buildCategoryPendingQuery(clone $baseQuery)->count()
            : 0;

        $transcriptPendingEstimate = in_array('transcript', $steps, true)
            ? (clone $baseQuery)
                ->where('status', 'answered')
                ->where('has_transcription', false)
                ->count()
            : 0;

        $reportPendingRows = (clone $baseQuery)
            ->selectRaw('weekly_call_report_id, COUNT(*) as pending_count')
            ->where(function ($q) use ($steps): void {
                if (in_array('summary', $steps, true)) {
                    $q->orWhere(function ($summary) {
                        $summary->whereNotNull('transcript_text')
                            ->where('transcript_text', '!=', '')
                            ->where(function ($inner) {
                                $inner->where('ai_summary_status', 'credit_exhausted')
                                    ->orWhere(function ($pending) {
                                        $pending->whereNull('ai_summary_status')
                                            ->where(function ($pendingSummary) {
                                                $pendingSummary->whereNull('ai_summary')
                                                    ->orWhere('ai_summary', '');
                                            });
                                    });
                            });
                    });
                }

                if (in_array('categories', $steps, true)) {
                    $q->orWhere(function ($category) {
                        $category->whereNotNull('transcript_text')
                            ->where('transcript_text', '!=', '')
                            ->whereNull('category_id')
                            ->where(function ($inner) {
                                $inner->where('ai_category_status', 'credit_exhausted')
                                    ->orWhere('ai_category_status', 'not_generated')
                                    ->orWhereNull('ai_category_status');
                            });
                    });
                }
            })
            ->whereNotNull('weekly_call_report_id')
            ->groupBy('weekly_call_report_id')
            ->orderByDesc('pending_count')
            ->limit(20)
            ->get();

        $reportIds = $reportPendingRows->pluck('weekly_call_report_id')->all();

        $reportMeta = empty($reportIds)
            ? collect()
            : \App\Models\WeeklyCallReport::query()
                ->with('company:id,name')
                ->whereIn('id', $reportIds)
                ->get()
                ->keyBy('id');

        $perReport = $reportPendingRows->map(function ($row) use ($reportMeta) {
            $report = $reportMeta->get((int) $row->weekly_call_report_id);

            return [
                'report_id' => (int) $row->weekly_call_report_id,
                'pending_count' => (int) $row->pending_count,
                'company_id' => (int) ($report?->company_id ?? 0),
                'company_name' => (string) ($report?->company?->name ?? ''),
                'week_start_date' => $report?->week_start_date?->toDateString(),
                'week_end_date' => $report?->week_end_date?->toDateString(),
            ];
        })->values();

        return response()->json([
            'data' => [
                'scope' => [
                    'company_id' => isset($validated['company_id']) ? (int) $validated['company_id'] : null,
                    'from_date' => $from?->toDateString(),
                    'to_date' => $to?->toDateString(),
                    'steps' => $steps,
                ],
                'summary_pending' => $summaryPending,
                'category_pending' => $categoryPending,
                'transcript_pending_estimate' => $transcriptPendingEstimate,
                'total_pending' => $summaryPending + $categoryPending,
                'affected_reports' => $perReport->count(),
                'per_report' => $perReport,
            ],
        ]);
    }

    public function regenerate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*' => ['string', 'in:transcript,summary,categories'],
        ]);

        $from = CarbonImmutable::parse($validated['from_date'], 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($validated['to_date'], 'UTC')->endOfDay();
        $steps = array_values(array_unique(array_map('strval', $validated['steps'])));

        $companyIds = [];

        if (isset($validated['company_id'])) {
            $companyIds = [(int) $validated['company_id']];
        } else {
            $companyIds = Call::query()
                ->whereBetween('started_at', [$from, $to])
                ->distinct()
                ->pluck('company_id')
                ->map(static fn ($id) => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->values()
                ->all();
        }

        foreach ($companyIds as $companyId) {
            RegenerateAiJob::dispatch(
                $companyId,
                $from->toDateString(),
                $to->toDateString(),
                $steps,
            )->onQueue('default');
        }

        return response()->json([
            'data' => [
                'queued_jobs' => count($companyIds),
                'company_ids' => $companyIds,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'steps' => $steps,
            ],
            'message' => count($companyIds) > 0
                ? 'AI regeneration jobs queued successfully.'
                : 'No companies found in the selected date range.',
        ]);
    }

    private function buildSummaryPendingQuery(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->whereNotNull('transcript_text')
            ->where('transcript_text', '!=', '')
            ->where(function ($q) {
                $q->where('ai_summary_status', 'credit_exhausted')
                    ->orWhere(function ($pending) {
                        $pending->whereNull('ai_summary_status')
                            ->where(function ($summary) {
                                $summary->whereNull('ai_summary')
                                    ->orWhere('ai_summary', '');
                            });
                    });
            });
    }

    private function buildCategoryPendingQuery(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->whereNotNull('transcript_text')
            ->where('transcript_text', '!=', '')
            ->whereNull('category_id')
            ->where(function ($q) {
                $q->where('ai_category_status', 'credit_exhausted')
                    ->orWhere('ai_category_status', 'not_generated')
                    ->orWhereNull('ai_category_status');
            });
    }
}
