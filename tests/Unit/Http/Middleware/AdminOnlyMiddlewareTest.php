<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\AdminOnly;
use App\Models\User;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class AdminOnlyMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_guest_on_admin_api_receives_unauthenticated_json(): void
    {
        $guard = Mockery::mock(Guard::class);
        $guard->shouldReceive('guest')->once()->andReturn(true);

        Auth::shouldReceive('guard')->once()->with('admin')->andReturn($guard);

        $middleware = new AdminOnly();
        $request = Request::create('/admin/api/calls', 'GET');

        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('{"message":"Unauthenticated."}', $response->getContent());
    }

    public function test_guest_on_admin_web_is_redirected_to_login_with_redirect_query(): void
    {
        $guard = Mockery::mock(Guard::class);
        $guard->shouldReceive('guest')->once()->andReturn(true);

        Auth::shouldReceive('guard')->once()->with('admin')->andReturn($guard);

        $middleware = new AdminOnly();
        $request = Request::create('https://bluehub.zotecsoft.work/admin/calls', 'GET');

        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertTrue($response->isRedirect());
        $this->assertStringContainsString('/admin/login?redirect=', $response->getTargetUrl());
        $this->assertStringContainsString(urlencode('https://bluehub.zotecsoft.work/admin/calls'), $response->getTargetUrl());
    }

    public function test_non_admin_user_on_admin_api_receives_forbidden_json(): void
    {
        $guard = Mockery::mock(Guard::class);
        $user = new User(['role' => User::ROLE_USER]);

        $guard->shouldReceive('guest')->once()->andReturn(false);
        $guard->shouldReceive('user')->once()->andReturn($user);

        Auth::shouldReceive('guard')->once()->with('admin')->andReturn($guard);

        $middleware = new AdminOnly();
        $request = Request::create('/admin/api/calls', 'GET');

        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('{"message":"Forbidden."}', $response->getContent());
    }
}
