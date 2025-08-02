<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\CheckOnboarding;
use App\Services\ApiKeyService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Mockery;
use Tests\TestCase;

class CheckOnboardingTest extends TestCase
{
    private CheckOnboarding $middleware;

    private ApiKeyService $mockApiKeyService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockApiKeyService = Mockery::mock(ApiKeyService::class);
        $this->middleware = new CheckOnboarding($this->mockApiKeyService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_allows_onboarding_route_without_api_key(): void
    {
        $request = Request::create('/onboarding', 'GET');
        $route = new Route(['GET'], '/onboarding', []);
        $route->name('onboarding');
        $request->setRouteResolver(fn () => $route);

        $this->mockApiKeyService->shouldNotReceive('hasApiKey');

        $response = $this->middleware->handle($request, function ($req) {
            return new Response('OK');
        });

        $this->assertEquals('OK', $response->getContent());
    }

    public function test_allows_api_key_settings_routes_without_api_key(): void
    {
        $excludedRoutes = [
            'api-keys.edit',
            'api-keys.update',
            'api-keys.destroy',
            'api.openai.status',
            'api.openai.api-key.store',
            'appearance',
        ];

        foreach ($excludedRoutes as $routeName) {
            $request = Request::create('/test', 'GET');
            $route = new Route(['GET'], '/test', []);
            $route->name($routeName);
            $request->setRouteResolver(fn () => $route);

            $this->mockApiKeyService->shouldNotReceive('hasApiKey');

            $response = $this->middleware->handle($request, function ($req) {
                return new Response('OK');
            });

            $this->assertEquals('OK', $response->getContent(), "Route {$routeName} should be excluded");
        }
    }

    public function test_allows_api_routes_without_api_key(): void
    {
        $request = Request::create('/api/some-endpoint', 'POST');
        $route = new Route(['POST'], '/api/some-endpoint', []);
        $route->name('api.some-endpoint');
        $request->setRouteResolver(fn () => $route);

        $this->mockApiKeyService->shouldNotReceive('hasApiKey');

        $response = $this->middleware->handle($request, function ($req) {
            return new Response('OK');
        });

        $this->assertEquals('OK', $response->getContent());
    }

    public function test_redirects_to_onboarding_when_no_api_key(): void
    {
        $request = Request::create('/dashboard', 'GET');
        $route = new Route(['GET'], '/dashboard', []);
        $route->name('dashboard');
        $request->setRouteResolver(fn () => $route);

        $this->mockApiKeyService->shouldReceive('hasApiKey')
            ->once()
            ->andReturn(false);

        $response = $this->middleware->handle($request, function ($req) {
            return new Response('Should not reach here');
        });

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertTrue(str_contains($response->headers->get('Location'), '/onboarding'));
    }

    public function test_allows_access_when_api_key_exists(): void
    {
        $request = Request::create('/dashboard', 'GET');
        $route = new Route(['GET'], '/dashboard', []);
        $route->name('dashboard');
        $request->setRouteResolver(fn () => $route);

        $this->mockApiKeyService->shouldReceive('hasApiKey')
            ->once()
            ->andReturn(true);

        $response = $this->middleware->handle($request, function ($req) {
            return new Response('Dashboard content');
        });

        $this->assertEquals('Dashboard content', $response->getContent());
    }

    public function test_handles_request_without_route(): void
    {
        $request = Request::create('/some-path', 'GET');
        // No route set - route() returns null

        $this->mockApiKeyService->shouldReceive('hasApiKey')
            ->once()
            ->andReturn(false);

        $response = $this->middleware->handle($request, function ($req) {
            return new Response('OK');
        });

        // Without a route, middleware allows the request to continue
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getContent());
    }
}
