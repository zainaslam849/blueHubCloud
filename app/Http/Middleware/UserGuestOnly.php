<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class UserGuestOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');

        if ($guard->check()) {
            $user = $guard->user();

            if (method_exists($user, 'isUser') && $user->isUser()) {
                return redirect()->to('/dashboard');
            }
        }

        return $next($request);
    }
}
