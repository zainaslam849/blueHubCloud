<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\AdminGuestOnly;
use App\Models\User;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class AdminGuestOnlyMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_authenticated_admin_is_redirected_to_dashboard(): void
    {
        $guard = Mockery::mock(Guard::class);
        $admin = new User(['role' => User::ROLE_ADMIN]);

        $guard->shouldReceive('check')->once()->andReturn(true);
        $guard->shouldReceive('user')->once()->andReturn($admin);

        Auth::shouldReceive('guard')->once()->with('admin')->andReturn($guard);

        $middleware = new AdminGuestOnly();
        $request = Request::create('/admin/login', 'GET');

        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertTrue($response->isRedirect());
        $this->assertStringEndsWith('/admin/dashboard', $response->getTargetUrl());
    }

    public function test_guest_user_can_access_login_page(): void
    {
        $guard = Mockery::mock(Guard::class);
        $guard->shouldReceive('check')->once()->andReturn(false);

        Auth::shouldReceive('guard')->once()->with('admin')->andReturn($guard);

        $middleware = new AdminGuestOnly();
        $request = Request::create('/admin/login', 'GET');

        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
    }
}
