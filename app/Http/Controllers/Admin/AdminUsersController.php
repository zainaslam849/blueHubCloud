<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeUserMail;
use App\Models\AppSetting;
use App\Models\User;
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
            ->with(['company:id,name,status,monthly_call_limit,call_limit_used,call_limit_expires_at,timezone'])
            ->findOrFail($id);

        $company = $user->company;

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
                    'id'   => $company->id,
                    'name' => $company->name,
                ] : null,
                // Call limit is company-level; surfaced here read-only for context.
                'call_limit' => $company ? [
                    'monthly_call_limit'   => $company->monthly_call_limit,
                    'call_limit_used'      => (int) $company->call_limit_used,
                    'call_limit_remaining' => $company->monthly_call_limit === null ? null : $company->call_limit_remaining,
                    'call_limit_expires_at'=> $company->call_limit_expires_at?->toDateString(),
                    'period_completed'     => $company->isCallLimitPeriodCompleted(),
                ] : null,
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
            'monthly_call_limit' => $user->monthly_call_limit,
            'company'            => $user->company
                ? ['id' => $user->company->id, 'name' => $user->company->name]
                : null,
            'created_at'         => $user->created_at?->toIso8601String(),
        ];
    }
}
