<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\CreditTransaction;
use App\Models\WeeklyCallReport;
use App\Services\Billing\CreditService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Full credit ledger for the company: purchases, auto top-ups, manual
 * adjustments and refunds as individual entries, plus per-call deductions
 * rolled up by weekly report (a deduction-per-call ledger isn't meaningful
 * to a customer one row at a time, but "X credits spent on the Jul 27 – Aug 2
 * report" is).
 */
class CreditHistoryController extends Controller
{
    public function index(CreditService $creditService): JsonResponse
    {
        $user = Auth::guard('web')->user();

        if (! $user->company_id) {
            return response()->json(['data' => [], 'balance' => 0, 'message' => 'No company assigned to your account yet.']);
        }

        $companyId = $user->company_id;

        // Purchases, auto top-ups, manual adjustments, refunds — one row each.
        // A manual admin deduction (negative adjustment) is also stored as
        // type=deduction but with no Call reference, so it belongs here too —
        // only per-call deductions get rolled up into the usage groups below.
        $entries = CreditTransaction::where('company_id', $companyId)
            ->where(function ($q) {
                $q->where('type', '!=', CreditTransaction::TYPE_DEDUCTION)
                    ->orWhere('reference_type', '!=', Call::class)
                    ->orWhereNull('reference_type');
            })
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(function (CreditTransaction $t) {
                return [
                    'key' => 'txn-' . $t->id,
                    'type' => $t->type,
                    'label' => $this->labelFor($t),
                    'credits' => (float) $t->credits,
                    'balance_after' => (float) $t->balance_after,
                    'date' => $t->created_at?->toIso8601String(),
                    'report_week' => null,
                ];
            });

        // Per-call deductions, rolled up by the report they ended up on.
        // Calls not yet attached to a report (weekly_call_report_id null —
        // ingested but the weekly report hasn't generated yet) land in one
        // combined "not yet reported" bucket rather than one row per call.
        $deductionGroups = DB::table('credit_transactions')
            ->join('calls', 'calls.id', '=', 'credit_transactions.reference_id')
            ->where('credit_transactions.company_id', $companyId)
            ->where('credit_transactions.reference_type', Call::class)
            ->where('credit_transactions.type', CreditTransaction::TYPE_DEDUCTION)
            ->groupBy('calls.weekly_call_report_id')
            ->select([
                'calls.weekly_call_report_id',
                DB::raw('SUM(credit_transactions.credits) as credits'),
                DB::raw('MAX(credit_transactions.created_at) as last_at'),
                DB::raw('MAX(credit_transactions.id) as last_txn_id'),
                DB::raw('COUNT(*) as call_count'),
            ])
            ->get();

        $reportIds = $deductionGroups->pluck('weekly_call_report_id')->filter()->values();
        $reports = $reportIds->isNotEmpty()
            ? WeeklyCallReport::whereIn('id', $reportIds)->get(['id', 'week_start_date', 'week_end_date'])->keyBy('id')
            : collect();

        $txnIds = $deductionGroups->pluck('last_txn_id')->filter()->values();
        $balancesByTxnId = $txnIds->isNotEmpty()
            ? CreditTransaction::whereIn('id', $txnIds)->pluck('balance_after', 'id')
            : collect();

        $usageEntries = $deductionGroups->map(function ($g) use ($reports, $balancesByTxnId) {
            $report = $g->weekly_call_report_id ? $reports->get($g->weekly_call_report_id) : null;

            $label = $report
                ? 'Weekly report: ' . $report->week_start_date?->format('M j') . ' – ' . $report->week_end_date?->format('M j, Y')
                : 'Calls not yet on a report (' . $g->call_count . ' calls)';

            return [
                'key' => 'usage-' . ($g->weekly_call_report_id ?? 'unreported'),
                'type' => 'usage',
                'label' => $label,
                'credits' => (float) $g->credits,
                'balance_after' => isset($balancesByTxnId[$g->last_txn_id]) ? (float) $balancesByTxnId[$g->last_txn_id] : null,
                'date' => $g->last_at ? Carbon::parse($g->last_at)->toIso8601String() : null,
                'report_week' => $report?->week_start_date?->toDateString(),
            ];
        });

        $combined = $entries->concat($usageEntries)
            ->sortByDesc(fn ($e) => $e['date'] ?? '')
            ->values();

        return response()->json([
            'data' => $combined,
            'balance' => $creditService->availableCredits($user->company),
        ]);
    }

    private function labelFor(CreditTransaction $t): string
    {
        $meta = $t->meta ?? [];
        $note = $meta['note'] ?? null;

        // Manual admin adjustments (AdminCreditsController::adjust) are the
        // only source of type=adjustment, and of type=deduction with no Call
        // reference — say so explicitly rather than the generic "Adjustment",
        // so the customer can tell a manual change from a purchase or usage.
        $isAdminAdjustment = ($meta['source'] ?? null) === 'admin_adjustment';

        return match ($t->type) {
            CreditTransaction::TYPE_PURCHASE => ($meta['plan_name'] ?? null) ? $meta['plan_name'] . ' pack' : 'Credit purchase',
            CreditTransaction::TYPE_AUTO_TOPUP => 'Auto top-up',
            CreditTransaction::TYPE_ADJUSTMENT, CreditTransaction::TYPE_DEDUCTION => $isAdminAdjustment
                ? (($t->credits >= 0 ? 'Added by admin' : 'Removed by admin') . ($note ? ' — ' . $note : ''))
                : (($t->credits >= 0 ? 'Credit added' : 'Credit removed') . ($note ? ' — ' . $note : '')),
            CreditTransaction::TYPE_REFUND => 'Refund',
            default => ucfirst($t->type),
        };
    }
}
