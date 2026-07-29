<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyCreditBalance;
use App\Models\CreditTransaction;
use App\Services\Billing\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin view of a company's credit balance/ledger plus manual adjustments
 * (grant or remove credits without a payment).
 */
class AdminCreditsController extends Controller
{
    public function show(int $companyId): JsonResponse
    {
        $company = Company::withTrashed()->findOrFail($companyId);

        $balanceRow = CompanyCreditBalance::firstOrCreate(
            ['company_id' => $company->id],
            ['balance' => 0]
        );

        $transactions = CreditTransaction::query()
            ->where('company_id', $company->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (CreditTransaction $transaction) => [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'credits' => $transaction->credits,
                'balance_after' => $transaction->balance_after,
                'meta' => $transaction->meta,
                'created_at' => $transaction->created_at?->toISOString(),
            ]);

        return response()->json([
            'data' => [
                'company_id' => $company->id,
                'company_name' => $company->name,
                'balance' => (float) $balanceRow->balance,
                'auto_topup_enabled' => (bool) $balanceRow->auto_topup_enabled,
                'auto_topup_paused_at' => $balanceRow->auto_topup_paused_at?->toISOString(),
                'transactions' => $transactions,
            ],
        ]);
    }

    public function adjust(Request $request, int $companyId, CreditService $creditService): JsonResponse
    {
        $company = Company::withTrashed()->findOrFail($companyId);

        $validated = $request->validate([
            'credits' => ['required', 'numeric', 'not_in:0', 'min:-100000', 'max:100000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $credits = (float) $validated['credits'];
        $meta = ['note' => $validated['note'] ?? null, 'source' => 'admin_adjustment'];
        $adminId = Auth::guard('admin')->id();

        if ($credits > 0) {
            $creditService->credit($company, $credits, CreditTransaction::TYPE_ADJUSTMENT, null, $meta, $adminId);
        } else {
            $creditService->deduct($company, abs($credits), null, $meta);
        }

        return response()->json([
            'message' => 'Credit adjustment applied.',
            'data' => ['balance' => $creditService->availableCredits($company)],
        ]);
    }
}
