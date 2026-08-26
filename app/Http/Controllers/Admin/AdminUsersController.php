<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeUserMail;
use App\Models\AppSetting;
use App\Models\CompanyCreditBalance;
use App\Models\CompanyPbxAccount;
use App\Models\CreditTransaction;
use App\Models\PlanPurchase;
use App\Models\User;
use App\Models\WeeklyCallReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

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
     * All registered SaaS users with company info.
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::where('role', User::ROLE_USER)
            ->with(['company:id,name,status'])
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
     * Full user detail.
     * GET /admin/api/users/{id}
     */
    public function show(int $id): JsonResponse
    {
        $user = User::where('role', User::ROLE_USER)
            ->with(['company:id,name,slug,status,timezone,created_at'])
            ->findOrFail($id);

        $company = $user->company;

        $pbxAccounts = collect();
        $creditBalance = null;
        $reportsCount = 0;
        $recentReports = collect();
        $creditTransactions = collect();
        $purchases = collect();
        $currentPlanName = null;

        if ($company) {
            $pbxAccounts = CompanyPbxAccount::with('pbxProvider:id,name')
                ->where('company_id', $company->id)
                ->get()
                ->map(fn (CompanyPbxAccount $a) => [
                    'id' => $a->id,
                    'tenant_code' => $a->tenant_code,
                    'server_id' => $a->server_id,
                    'server_name' => $a->pbxProvider?->name,
                    'package_name' => $a->package_name,
                    'status' => $a->status,
                ])
                ->values();

            $balanceRow = CompanyCreditBalance::where('company_id', $company->id)->first();
            $creditBalance = [
                'balance' => (float) ($balanceRow?->balance ?? 0),
                'auto_topup_enabled' => (bool) ($balanceRow?->auto_topup_enabled ?? false),
                'auto_topup_threshold' => $balanceRow?->auto_topup_threshold !== null ? (float) $balanceRow->auto_topup_threshold : null,
                'auto_topup_credits' => $balanceRow?->auto_topup_credits !== null ? (float) $balanceRow->auto_topup_credits : null,
            ];

            $reportsCount = WeeklyCallReport::where('company_id', $company->id)->count();

            $recentReports = WeeklyCallReport::where('company_id', $company->id)
                ->orderByDesc('week_start_date')
                ->limit(10)
                ->get(['id', 'week_start_date', 'week_end_date', 'status', 'total_calls', 'minutes_consumed'])
                ->map(fn (WeeklyCallReport $r) => [
                    'id' => $r->id,
                    'week_start_date' => $r->week_start_date?->toDateString(),
                    'week_end_date' => $r->week_end_date?->toDateString(),
                    'status' => $r->status,
                    'total_calls' => $r->total_calls,
                    'minutes_consumed' => $r->minutes_consumed,
                ])
                ->values();

            $creditTransactions = CreditTransaction::where('company_id', $company->id)
                ->orderByDesc('id')
                ->limit(20)
                ->get(['id', 'type', 'credits', 'balance_after', 'meta', 'created_at'])
                ->map(fn (CreditTransaction $t) => [
                    'id' => $t->id,
                    'type' => $t->type,
                    'credits' => (float) $t->credits,
                    'balance_after' => (float) $t->balance_after,
                    'note' => $t->meta['note'] ?? null,
                    'created_at' => $t->created_at?->toIso8601String(),
                ])
                ->values();

            $purchases = PlanPurchase::with('plan:id,name')
                ->where('company_id', $company->id)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(['id', 'plan_id', 'plan_name', 'amount_paid', 'currency', 'credits_added', 'status', 'purchased_at', 'created_at'])
                ->map(fn (PlanPurchase $p) => [
                    'id' => $p->id,
                    'plan_name' => $p->plan_name ?? $p->plan?->name,
                    'amount_paid' => (float) $p->amount_paid,
                    'currency' => $p->currency,
                    'credits_added' => (float) $p->credits_added,
                    'status' => $p->status,
                    'purchased_at' => ($p->purchased_at ?? $p->created_at)?->toIso8601String(),
                ])
                ->values();

            $currentPlanName = $purchases->firstWhere('status', 'completed')['plan_name'] ?? null;
        }

        return response()->json([
            'data' => [
                'user' => [
                    'id'                  => $user->id,
                    'name'                => $user->name,
                    'email'               => $user->email,
                    'account_status'      => $user->account_status ?? 'active',
                    'email_verified_at'   => $user->email_verified_at?->toIso8601String(),
                    'created_at'          => $user->created_at?->toIso8601String(),
                ],
                'company' => $company ? [
                    'id'         => $company->id,
                    'name'       => $company->name,
                    'slug'       => $company->slug,
                    'status'     => $company->status,
                    'timezone'   => $company->timezone,
                    'created_at' => $company->created_at?->toIso8601String(),
                ] : null,
                'pbx_accounts'        => $pbxAccounts,
                'credit_balance'      => $creditBalance,
                'current_plan_name'   => $currentPlanName,
                'reports_count'       => $reportsCount,
                'recent_reports'      => $recentReports,
                'credit_transactions' => $creditTransactions,
                'purchases'           => $purchases,
            ],
        ]);
    }

    /**
     * Create a new SaaS user.
     * POST /admin/api/users
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                => ['required', 'string', 'max:255'],
            'email'               => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'            => ['required', 'string', 'min:8'],
            'company_id'          => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $user = User::create([
            'name'                => $validated['name'],
            'email'               => $validated['email'],
            'password'            => Hash::make($validated['password']),
            'role'                => User::ROLE_USER,
            'account_status'      => User::STATUS_ACTIVE,
            'company_id'          => $validated['company_id'] ?? null,
            'email_verified_at'   => now(), // admin-created users are pre-verified
        ]);

        $user->load('company:id,name,status');

        // Send welcome email with login credentials
        try {
            $settings = AppSetting::query()->first();
            $appName  = $settings?->site_name ?? config('app.name', 'BlueHubCloud');
            $loginUrl = rtrim(config('app.url'), '/') . '/login';

            Mail::to($user->email)->send(new WelcomeUserMail(
                userName:      $user->name,
                userEmail:     $user->email,
                plainPassword: $validated['password'],
                loginUrl:      $loginUrl,
                appName:       $appName,
            ));
        } catch (\Throwable) {
            // Email failure must not prevent user creation from succeeding
        }

        return response()->json([
            'message' => "User \"{$user->name}\" created successfully. A welcome email with login credentials has been sent.",
            'data'    => $this->format($user),
        ], 201);
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
        $user->delete();

        return response()->json(['message' => "User \"{$user->name}\" has been deleted."]);
    }

    private function format(User $user): array
    {
        return [
            'id'                 => $user->id,
            'name'               => $user->name,
            'email'              => $user->email,
            'company_id'         => $user->company_id,
            'account_status'     => $user->account_status ?? User::STATUS_ACTIVE,
            'company'            => $user->company
                ? ['id' => $user->company->id, 'name' => $user->company->name]
                : null,
            'created_at'         => $user->created_at?->toIso8601String(),
        ];
    }
}
