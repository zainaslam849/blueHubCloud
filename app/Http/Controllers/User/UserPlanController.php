<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\CompanyMinuteBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class UserPlanController extends Controller
{
    public function show(): JsonResponse
    {
        $user = Auth::guard('web')->user();

        if (! $user->company_id) {
            return response()->json(['message' => 'No company assigned to your account yet.'], 404);
        }

        $balance = CompanyMinuteBalance::with('plan')
            ->where('company_id', $user->company_id)
            ->first();

        if (! $balance) {
            return response()->json(['message' => 'No plan assigned yet. Contact your administrator.'], 404);
        }

        return response()->json([
            'plan' => $balance->plan ? [
                'id' => $balance->plan->id,
                'name' => $balance->plan->name,
                'minute_limit' => $balance->plan->minute_limit,
                'price' => $balance->plan->price,
            ] : null,
            'purchased_minutes' => $balance->purchased_minutes,
            'used_minutes' => $balance->used_minutes,
            'available_minutes' => $balance->available_minutes,
        ]);
    }
}
