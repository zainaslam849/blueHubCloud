<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\CompanyCreditBalance;
use App\Services\Billing\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Credit balance summary for the logged-in user's company.
 * (Endpoint kept at /api/v1/plan for the existing usage screen.)
 */
class UserPlanController extends Controller
{
    public function show(CreditService $creditService): JsonResponse
    {
        $user = Auth::guard('web')->user();

        if (! $user->company_id) {
            return response()->json(['message' => 'No company assigned to your account yet.'], 404);
        }

        $balanceRow = CompanyCreditBalance::firstOrCreate(
            ['company_id' => $user->company_id],
            ['balance' => 0]
        );

        $creditsPerMinute = $creditService->creditsPerMinute();

        return response()->json([
            'credits' => (float) $balanceRow->balance,
            'credit_price_usd' => $creditService->creditPriceUsd(),
            'credits_per_minute' => $creditsPerMinute,
            'minutes_available' => $creditsPerMinute > 0
                ? round((float) $balanceRow->balance / $creditsPerMinute, 1)
                : null,
            'auto_topup' => [
                'enabled' => (bool) $balanceRow->auto_topup_enabled,
                'threshold' => $balanceRow->auto_topup_threshold,
                'credits' => $balanceRow->auto_topup_credits,
                'has_payment_method' => $balanceRow->stripe_payment_method_id !== null,
                'paused_at' => $balanceRow->auto_topup_paused_at?->toISOString(),
                'failure_count' => (int) $balanceRow->auto_topup_failure_count,
            ],
        ]);
    }
}
