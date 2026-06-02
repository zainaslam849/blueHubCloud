<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUsersController extends Controller
{
    /**
     * Users with role='user' and no company assigned yet (for the assign-user dropdown).
     */
    public function unassigned(): JsonResponse
    {
        $users = User::where('role', User::ROLE_USER)
            ->whereNull('company_id')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'created_at']);

        return response()->json(['data' => $users]);
    }

    /**
     * All registered SaaS users with company and minute balance.
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::where('role', User::ROLE_USER)
            ->with([
                'company:id,name,status',
                'company.minuteBalance:company_id,plan_id,purchased_minutes,used_minutes',
                'company.minuteBalance.plan:id,name,minute_limit',
            ])
            ->orderBy('name')
            ->paginate((int) $request->query('per_page', 25));

        $items = collect($users->items())->map(fn (User $user) => $this->format($user));

        return response()->json([
            'data' => $items,
            'meta' => [
                'currentPage' => $users->currentPage(),
                'lastPage'    => $users->lastPage(),
                'total'       => $users->total(),
                'perPage'     => $users->perPage(),
            ],
        ]);
    }

    /**
     * Full user detail with billing history.
     * GET /admin/api/users/{id}
     */
    public function show(int $id): JsonResponse
    {
        $user = User::where('role', User::ROLE_USER)
            ->with([
                'company:id,name,status',
                'company.minuteBalance:company_id,plan_id,purchased_minutes,used_minutes',
                'company.minuteBalance.plan:id,name,minute_limit',
            ])
            ->findOrFail($id);

        $purchases = \App\Models\PlanPurchase::where('user_id', $id)
            ->with('plan:id,name,minute_limit')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($p) => [
                'id'                      => $p->id,
                'plan_name'               => $p->plan_name,
                'minutes_added'           => $p->minutes_added,
                'amount_paid'             => $p->amount_paid,
                'currency'                => strtoupper($p->currency ?? 'USD'),
                'status'                  => $p->status,
                'stripe_session_id'       => $p->stripe_session_id,
                'stripe_payment_intent_id'=> $p->stripe_payment_intent_id,
                'purchased_at'            => $p->purchased_at?->toIso8601String(),
                'created_at'              => $p->created_at?->toIso8601String(),
            ]);

        $balance = $user->company?->minuteBalance;

        return response()->json([
            'data' => [
                'user' => [
                    'id'             => $user->id,
                    'name'           => $user->name,
                    'email'          => $user->email,
                    'account_status' => $user->account_status ?? 'active',
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                    'created_at'     => $user->created_at?->toIso8601String(),
                ],
                'company' => $user->company ? [
                    'id'   => $user->company->id,
                    'name' => $user->company->name,
                ] : null,
                'minute_balance' => $balance ? [
                    'purchased_minutes' => $balance->purchased_minutes,
                    'used_minutes'      => $balance->used_minutes,
                    'available_minutes' => $balance->available_minutes,
                    'plan_name'         => $balance->plan?->name,
                ] : null,
                'purchases'      => $purchases,
                'total_spent'    => $purchases->where('status', 'completed')->sum('amount_paid'),
                'total_purchases'=> $purchases->count(),
            ],
        ]);
    }

    /**
     * Toggle a user's account_status between active and suspended.
     * PATCH /admin/api/users/{id}/toggle-status
     */
    public function toggleStatus(int $id): JsonResponse
    {
        $user = User::where('role', User::ROLE_USER)->findOrFail($id);

        $newStatus = $user->isSuspended()
            ? User::STATUS_ACTIVE
            : User::STATUS_SUSPENDED;

        $user->update(['account_status' => $newStatus]);

        return response()->json([
            'message'        => $newStatus === User::STATUS_SUSPENDED
                ? 'User suspended. They will not be able to log in.'
                : 'User reactivated. They can now log in.',
            'account_status' => $newStatus,
            'user'           => $this->format($user),
        ]);
    }

    /**
     * Delete a SaaS user account only — company, calls, and reports are untouched.
     * DELETE /admin/api/users/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $user = User::where('role', User::ROLE_USER)->findOrFail($id);

        // Only delete the user row — all company data stays intact
        $user->delete();

        return response()->json(['message' => "User \"{$user->name}\" has been deleted."]);
    }

    private function format(User $user): array
    {
        $balance = $user->company?->minuteBalance;

        return [
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'company_id'     => $user->company_id,
            'account_status' => $user->account_status ?? User::STATUS_ACTIVE,
            'company'        => $user->company
                ? ['id' => $user->company->id, 'name' => $user->company->name]
                : null,
            'minute_balance' => $balance ? [
                'purchased_minutes' => $balance->purchased_minutes,
                'used_minutes'      => $balance->used_minutes,
                'available_minutes' => $balance->available_minutes,
                'plan_name'         => $balance->plan?->name,
            ] : null,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
