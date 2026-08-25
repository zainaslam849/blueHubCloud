<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class UserOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');

        if ($guard->guest()) {
            if ($request->expectsJson() || $request->is('api/v1/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $redirect = $request->fullUrl();
            return redirect()->to('/login?redirect='.urlencode($redirect));
        }

        /** @var User $user */
        $user = $guard->user();

        if (! $user->isUser()) {
            if ($request->expectsJson() || $request->is('api/v1/*')) {
                return response()->json([
                    'message' => 'Forbidden.',
                ], 403);
            }

            return redirect()->to('/login');
        }

        // Re-checked on every request (not just at login) so a suspension
        // takes effect immediately against an already-open session instead
        // of waiting for the user to log out and back in.
        if ($user->isSuspended()) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() || $request->is('api/v1/*')) {
                return response()->json([
                    'message' => 'Your account has been suspended. Please contact support.',
                    'code' => 'account_suspended',
                ], 403);
            }

            return redirect()->to('/login');
        }

        Auth::shouldUse('web');

        return $next($request);
    }
}
