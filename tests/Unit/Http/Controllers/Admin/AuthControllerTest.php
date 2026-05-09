<?php

namespace Tests\Unit\Http\Controllers\Admin;

use App\Http\Controllers\Admin\AuthController;
use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'array');
        config()->set('cache.default', 'array');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeRequest(string $method, string $uri, array $payload = []): Request
    {
        $request = Request::create($uri, $method, $payload);
        $session = app('session')->driver('array');
        $session->start();
        $session->regenerateToken();
        $request->setLaravelSession($session);

        return $request;
    }

    public function test_me_returns_401_when_unauthenticated(): void
    {
        $guard = Mockery::mock(StatefulGuard::class);
        $guard->shouldReceive('user')->once()->andReturn(null);

        Auth::shouldReceive('guard')->once()->with('admin')->andReturn($guard);

        $controller = new AuthController();
        $request = $this->makeRequest('GET', '/admin/api/me');

        $response = $controller->me($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('{"message":"Unauthenticated."}', $response->getContent());
    }

    public function test_me_returns_user_and_csrf_token_when_authenticated(): void
    {
        $guard = Mockery::mock(StatefulGuard::class);
        $user = new User([
            'id' => 10,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => User::ROLE_ADMIN,
        ]);

        $guard->shouldReceive('user')->once()->andReturn($user);
        Auth::shouldReceive('guard')->once()->with('admin')->andReturn($guard);

        $controller = new AuthController();
        $request = $this->makeRequest('GET', '/admin/api/me');

        $response = $controller->me($request);
        $payload = json_decode((string) $response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('admin@example.com', $payload['user']['email']);
        $this->assertIsString($payload['csrf_token'] ?? null);
        $this->assertNotSame('', $payload['csrf_token'] ?? '');
    }

    public function test_login_returns_422_for_invalid_credentials(): void
    {
        $guard = Mockery::mock(StatefulGuard::class);
        $guard->shouldReceive('attempt')->once()->andReturn(false);

        Auth::shouldReceive('guard')->once()->with('admin')->andReturn($guard);

        $controller = new AuthController();
        $request = $this->makeRequest('POST', '/admin/api/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
            'remember' => true,
        ]);

        $response = $controller->login($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('{"message":"Invalid credentials."}', $response->getContent());
    }

    public function test_login_returns_user_and_csrf_token_for_admin(): void
    {
        $guard = Mockery::mock(StatefulGuard::class);
        $user = new User([
            'id' => 11,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => User::ROLE_ADMIN,
        ]);

        $guard->shouldReceive('attempt')
            ->once()
            ->with(['email' => 'admin@example.com', 'password' => 'secret'], true)
            ->andReturn(true);
        $guard->shouldReceive('user')->once()->andReturn($user);

        Auth::shouldReceive('guard')->twice()->with('admin')->andReturn($guard);

        $controller = new AuthController();
        $request = $this->makeRequest('POST', '/admin/api/login', [
            'email' => 'admin@example.com',
            'password' => 'secret',
            'remember' => true,
        ]);

        $response = $controller->login($request);
        $payload = json_decode((string) $response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('admin@example.com', $payload['user']['email']);
        $this->assertIsString($payload['csrf_token'] ?? null);
        $this->assertNotSame('', $payload['csrf_token'] ?? '');
    }

    public function test_login_for_non_admin_logs_out_and_returns_403(): void
    {
        $guard = Mockery::mock(StatefulGuard::class);
        $user = new User([
            'id' => 12,
            'name' => 'Regular User',
            'email' => 'user@example.com',
            'role' => User::ROLE_USER,
        ]);

        $guard->shouldReceive('attempt')->once()->andReturn(true);
        $guard->shouldReceive('user')->once()->andReturn($user);
        $guard->shouldReceive('logout')->once();

        Auth::shouldReceive('guard')->times(3)->with('admin')->andReturn($guard);

        $controller = new AuthController();
        $request = $this->makeRequest('POST', '/admin/api/login', [
            'email' => 'user@example.com',
            'password' => 'secret',
        ]);

        $response = $controller->login($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('{"message":"Forbidden."}', $response->getContent());
    }

    public function test_logout_returns_success_payload(): void
    {
        $guard = Mockery::mock(StatefulGuard::class);
        $guard->shouldReceive('logout')->once();

        Auth::shouldReceive('guard')->once()->with('admin')->andReturn($guard);

        $controller = new AuthController();
        $request = $this->makeRequest('POST', '/admin/api/logout');

        $response = $controller->logout($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"success":true}', $response->getContent());
    }
}
