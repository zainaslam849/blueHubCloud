<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('admin');

        if ($guard->guest()) {
            if ($request->expectsJson() || $request->is('admin/api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $redirect = $request->fullUrl();
            return redirect()->to('/admin/login?redirect='.urlencode($redirect));
        }

        /** @var User $user */
        $user = $guard->user();

        if (! $user->isAdmin()) {
            if ($request->expectsJson() || $request->is('admin/api/*')) {
                return response()->json([
                    'message' => 'Forbidden.',
                ], 403);
            }

            // Non-admin users should not see the admin SPA.
            return redirect()->to('/');
        }

        return $next($request);
    }
}
