<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\PlanPurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class PurchaseHistoryController extends Controller
{
    public function index(): JsonResponse
    {
        $user = Auth::guard('web')->user();

        // Company members see all of their company's purchases, including
        // system-initiated auto top-ups (which have no user_id).
        $purchases = PlanPurchase::query()
            ->when(
                $user->company_id,
                fn ($q) => $q->where('company_id', $user->company_id),
                fn ($q) => $q->where('user_id', $user->id)
            )
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($p) => $this->format($p));

        return response()->json(['data' => $purchases]);
    }

    private function format(PlanPurchase $p): array
    {
        return [
            'id'             => $p->id,
            'plan_name'      => $p->plan_name,
            'credits_added'  => $p->credits_added,
            'minutes_added'  => $p->minutes_added,
            'amount_paid'    => $p->amount_paid,
            'currency'       => strtoupper($p->currency),
            'status'         => $p->status,
            'purchased_at'   => $p->purchased_at?->toIso8601String(),
            'created_at'     => $p->created_at?->toIso8601String(),
        ];
    }
}
