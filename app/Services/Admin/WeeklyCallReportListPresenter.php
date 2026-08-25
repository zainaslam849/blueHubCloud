<?php

namespace App\Services\Admin;

use App\Services\Billing\CreditService;

class WeeklyCallReportListPresenter
{
    public function __construct(private readonly CreditService $creditService)
    {
    }

    /**
     * Map a raw weekly report row to the existing index payload contract.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public function toIndexRow(array $r): array
    {
        $total = (int) ($r['total_calls'] ?? 0);
        $answered = (int) ($r['answered_calls'] ?? 0);
        $minutesConsumed = $r['minutes_consumed'] ?? null;
        $creditsUsed = $this->creditService->costForSeconds((int) ($r['total_call_duration_seconds'] ?? 0));

        $weekStart = $r['week_start_date'] ?? null;
        $weekEnd = $r['week_end_date'] ?? null;

        if (is_object($weekStart) && method_exists($weekStart, 'toDateString')) {
            $weekStart = $weekStart->toDateString();
        }

        if (is_object($weekEnd) && method_exists($weekEnd, 'toDateString')) {
            $weekEnd = $weekEnd->toDateString();
        }

        $companyName = $r['company']['name'] ?? $r['company_name'] ?? null;

        return [
            'id' => $r['id'] ?? null,

            // Company (both object and flat name for compatibility)
            'company' => ['name' => $companyName],
            'company_name' => $companyName,

            // Week range (both snake_case and camelCase)
            'week_start_date' => $weekStart,
            'week_end_date' => $weekEnd,
            'weekStartDate' => $weekStart,
            'weekEndDate' => $weekEnd,

            // Metrics (both snake_case and camelCase)
            'total_calls' => $total,
            'answered_calls' => $answered,
            'missed_calls' => (int) ($r['missed_calls'] ?? 0),
            'totalCalls' => $total,
            'answeredCalls' => $answered,
            'missedCalls' => (int) ($r['missed_calls'] ?? 0),
            'minutes_consumed' => $minutesConsumed,
            'credits_used' => $creditsUsed,

            // Derived metric
            'answer_rate' => $total > 0 ? round(($answered / $total) * 100) : 0,
            'answerRate' => $total > 0 ? (int) round(($answered / $total) * 100) : 0,
        ];
    }
}
