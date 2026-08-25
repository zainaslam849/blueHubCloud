<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\CompanyMinuteBalance;
use App\Models\Plan;
use App\Models\PlanPurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class UserAvailablePlansController extends Controller
{
    public function index(): JsonResponse
    {
        $user = Auth::guard('web')->user();

        // Determine the user's current active plan from purchase history
        $currentPlanId = null;
        if ($user->company_id) {
            $balance = CompanyMinuteBalance::where('company_id', $user->company_id)->first();
            $currentPlanId = $balance?->plan_id;
        }

        $plans = Plan::where('is_active', true)
            ->orderBy('price')
            ->get()
            ->map(fn (Plan $p) => [
                'id'               => $p->id,
                'name'             => $p->name,
                'credits'          => $p->credits,
                'minute_limit'     => $p->minute_limit,
                'price'            => $p->price,
                'sale_price'       => $p->sale_price,
                'description'      => $p->description,
                'has_sale'         => $p->has_sale,
                'discount_percent' => $p->discount_percent,
                'effective_price'  => $p->effective_price,
                'is_active'        => $p->is_active,
                'is_featured'      => $p->is_featured,
                'is_current'       => $p->id === $currentPlanId,
            ]);

        return response()->json(['data' => $plans]);
    }
}
