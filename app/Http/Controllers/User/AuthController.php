<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Mail\AdminNewUserMail;
use App\Mail\EmailVerificationMail;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function __construct(private readonly MailService $mailService) {}

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $code    = (string) random_int(100000, 999999);
        $expires = now()->addMinutes(15);

        $user = User::create([
            'name'                          => $validated['name'],
            'email'                         => $validated['email'],
            'password'                      => $validated['password'],
            'role'                          => User::ROLE_USER,
            'email_verification_code'       => $code,
            'email_verification_expires_at' => $expires,
        ]);

        $settings = AppSetting::query()->first();
        $appName  = $settings?->site_name ?? config('app.name', 'BlueHub');

        try {
            $this->mailService->send(
                $user->email,
                new EmailVerificationMail($user->name, $code, $appName)
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send verification email', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'status'  => 'pending_verification',
            'message' => 'A 6-digit verification code has been sent to your email address.',
            'email'   => $user->email,
        ], 201);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code'  => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            return response()->json(['message' => 'Invalid verification code.'], 422);
        }

        if ($user->email_verified_at) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            return response()->json(['user' => $this->userPayload($user), 'csrf_token' => csrf_token()]);
        }

        $storedCode    = trim((string) ($user->email_verification_code ?? ''));
        $submittedCode = trim((string) ($validated['code'] ?? ''));

        if (! $storedCode || $storedCode !== $submittedCode) {
            return response()->json(['message' => 'The verification code is incorrect. Please check and try again.'], 422);
        }

        if (! $user->email_verification_expires_at || now()->isAfter($user->email_verification_expires_at)) {
            return response()->json(['message' => 'This verification code has expired. Please request a new one.'], 422);
        }

        $user->update([
            'email_verified_at'             => now(),
            'email_verification_code'       => null,
            'email_verification_expires_at' => null,
        ]);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $this->notifyAdmin($user);

        return response()->json(['user' => $this->userPayload($user), 'csrf_token' => csrf_token()]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', $validated['email'])->whereNull('email_verified_at')->first();

        if (! $user) {
            return response()->json(['message' => 'If the email exists and is unverified, a new code has been sent.']);
        }

        $code = (string) random_int(100000, 999999);
        $user->update([
            'email_verification_code'       => $code,
            'email_verification_expires_at' => now()->addMinutes(15),
        ]);

        $settings = AppSetting::query()->first();
        $appName  = $settings?->site_name ?? config('app.name', 'BlueHub');

        try {
            $this->mailService->send($user->email, new EmailVerificationMail($user->name, $code, $appName));
        } catch (\Throwable $e) {
            Log::error('Failed to resend verification email', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        return response()->json(['message' => 'A new verification code has been sent.']);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        if (! Auth::guard('web')->attempt(['email' => $validated['email'], 'password' => $validated['password']], (bool) ($validated['remember'] ?? false))) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        $request->session()->regenerate();
        $user = Auth::guard('web')->user();

        if (! $user->isUser()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($user->isSuspended()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return response()->json(['message' => 'Your account has been suspended. Please contact support.', 'code' => 'account_suspended'], 403);
        }

        if (! $user->email_verified_at) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return response()->json(['message' => 'Please verify your email address before logging in.', 'code' => 'email_not_verified', 'email' => $user->email], 403);
        }

        return response()->json(['user' => $this->userPayload($user), 'csrf_token' => csrf_token()]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = Auth::guard('web')->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        return response()->json(['user' => $this->userPayload($user), 'csrf_token' => csrf_token()]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['success' => true]);
    }

    private function notifyAdmin(User $user): void
    {
        $settings   = AppSetting::query()->first();
        $adminEmail = $settings?->admin_notification_email;
        if (! $adminEmail) return;

        $appName  = $settings?->site_name ?? config('app.name', 'BlueHub');
        $adminUrl = config('app.url') . '/admin';

        try {
            $this->mailService->send($adminEmail, new AdminNewUserMail($user->name, $user->email, $appName, $adminUrl));
        } catch (\Throwable $e) {
            Log::error('Failed to send admin new-user notification', ['error' => $e->getMessage()]);
        }
    }

    private function userPayload(User $user): array
    {
        return [
            'id'           => $user->id,
            'name'         => $user->name,
            'email'        => $user->email,
            'role'         => $user->role,
            'company_id'   => $user->company_id,
            'company_name' => $user->company?->name,
            'created_at'   => $user->created_at?->toIso8601String(),
        ];
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = Auth::guard('web')->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password'     => ['required', 'string', Password::min(8), 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->password = Hash::make($validated['new_password']);
        $user->save();

        return response()->json(['message' => 'Password updated.']);
    }
}
