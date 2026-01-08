<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminGuestOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('admin');

        if ($guard->check()) {
            $user = $guard->user();

            if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
                return redirect()->to('/admin/dashboard');
            }

            return redirect()->to('/');
        }

        return $next($request);
    }
}
