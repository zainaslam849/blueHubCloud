<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlanPurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPurchasesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PlanPurchase::with(['user:id,name,email', 'company:id,name', 'plan:id,name'])
            ->orderByDesc('created_at');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $purchases = $query->paginate((int) $request->query('per_page', 25));

        $items = collect($purchases->items())->map(fn ($p) => [
            'id'             => $p->id,
            'user'           => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name, 'email' => $p->user->email] : null,
            'company'        => $p->company ? ['id' => $p->company->id, 'name' => $p->company->name] : null,
            'plan_name'      => $p->plan_name,
            'minutes_added'  => $p->minutes_added,
            'amount_paid'    => $p->amount_paid,
            'currency'       => strtoupper($p->currency),
            'status'         => $p->status,
            'stripe_session_id'       => $p->stripe_session_id,
            'stripe_payment_intent_id'=> $p->stripe_payment_intent_id,
            'purchased_at'   => $p->purchased_at?->toIso8601String(),
            'created_at'     => $p->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'data' => $items,
            'meta' => [
                'currentPage' => $purchases->currentPage(),
                'lastPage'    => $purchases->lastPage(),
                'total'       => $purchases->total(),
                'perPage'     => $purchases->perPage(),
            ],
            'totals' => [
                'total_revenue' => PlanPurchase::where('status', 'completed')->sum('amount_paid'),
                'total_purchases' => PlanPurchase::where('status', 'completed')->count(),
            ],
        ]);
    }
}
