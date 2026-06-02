<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Exclude Stripe webhook from CSRF verification — Stripe sends raw POST with its own signature
        $middleware->validateCsrfTokens(except: [
            'stripe/webhook',
        ]);
        // Trust all reverse proxies so X-Forwarded-Proto/Host/IP headers are
        // respected and $request->fullUrl() returns the correct HTTPS scheme.
        // Without this, session redirects generated server-side use http://
        // while the browser is on https://, causing mixed-content blocks.
        $middleware->trustProxies(
            at: '*',
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'pbx.apiKey' => \App\Http\Middleware\ApiKeyAuth::class,
            'pbx_auth' => \App\Http\Middleware\ApiKeyAuth::class,
            'admin' => \App\Http\Middleware\AdminOnly::class,
            'admin.guest' => \App\Http\Middleware\AdminGuestOnly::class,
            'user' => \App\Http\Middleware\UserOnly::class,
            'user.guest' => \App\Http\Middleware\UserGuestOnly::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
