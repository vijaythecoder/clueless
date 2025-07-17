<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\CheckOnboarding;
use App\Services\ApiKeyService;
use App\Services\AudioCapturePermissionService;
use App\Services\OnboardingStateService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Mockery;
use Tests\TestCase;

class CheckOnboardingTest extends TestCase
{
    private CheckOnboarding $middleware;
    private ApiKeyService $mockApiKeyService;
    private AudioCapturePermissionService $mockPermissionService;
    private OnboardingStateService $mockOnboardingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockApiKeyService = Mockery::mock(ApiKeyService::class);
        $this->mockPermissionService = Mockery::mock(AudioCapturePermissionService::class);
        $this->mockOnboardingService = Mockery::mock(OnboardingStateService::class);
        
        $this->middleware = new CheckOnboarding(
            $this->mockApiKeyService,
            $this->mockOnboardingService,
            $this->mockPermissionService
        );
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
        $request->setRouteResolver(fn() => $route);

        // Excluded routes should not trigger any service calls
        $this->mockApiKeyService->shouldNotReceive('hasApiKey');
        $this->mockPermissionService->shouldNotReceive('checkScreenRecordingPermission');
        $this->mockOnboardingService->shouldNotReceive('isOnboardingComplete');

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
            'api.onboarding.status',
            'api.onboarding.step',
            'api.onboarding.complete',
            'api.permissions.screen-recording.status',
            'api.permissions.screen-recording.request',
            'api.permissions.screen-recording.check',
            'api.open-external',
            'appearance'
        ];

        foreach ($excludedRoutes as $routeName) {
            $request = Request::create('/test', 'GET');
            $route = new Route(['GET'], '/test', []);
            $route->name($routeName);
            $request->setRouteResolver(fn() => $route);

            // Excluded routes should not trigger any service calls
            $this->mockApiKeyService->shouldNotReceive('hasApiKey');
            $this->mockPermissionService->shouldNotReceive('checkScreenRecordingPermission');
            $this->mockOnboardingService->shouldNotReceive('isOnboardingComplete');

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
        $request->setRouteResolver(fn() => $route);

        // API routes should not trigger any service calls
        $this->mockApiKeyService->shouldNotReceive('hasApiKey');
        $this->mockPermissionService->shouldNotReceive('checkScreenRecordingPermission');
        $this->mockOnboardingService->shouldNotReceive('isOnboardingComplete');

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
        $request->setRouteResolver(fn() => $route);

        // Mock missing API key scenario
        $this->mockApiKeyService->shouldReceive('hasApiKey')
            ->once()
            ->andReturn(false);

        // The middleware checks all services even if API key is missing
        $this->mockPermissionService->shouldReceive('checkScreenRecordingPermission')
            ->once()
            ->andReturn(['granted' => false]);

        $this->mockOnboardingService->shouldReceive('isOnboardingComplete')
            ->once()
            ->andReturn(false);

        // When API key is missing, the onboarding service should handle API key removal
        $this->mockOnboardingService->shouldReceive('handleApiKeyRemoval')
            ->once();

        $response = $this->middleware->handle($request, function ($req) {
            return new Response('Should not reach here');
        });

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertTrue(str_contains($response->headers->get('Location'), '/onboarding'));
    }

    public function test_allows_access_when_all_onboarding_complete(): void
    {
        $request = Request::create('/dashboard', 'GET');
        $route = new Route(['GET'], '/dashboard', []);
        $route->name('dashboard');
        $request->setRouteResolver(fn() => $route);

        // Mock complete onboarding scenario - all steps completed
        $this->mockApiKeyService->shouldReceive('hasApiKey')
            ->once()
            ->andReturn(true);

        $this->mockPermissionService->shouldReceive('checkScreenRecordingPermission')
            ->once()
            ->andReturn(['granted' => true]);

        $this->mockOnboardingService->shouldReceive('isOnboardingComplete')
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

        // The middleware checks all services even if API key is missing
        $this->mockPermissionService->shouldReceive('checkScreenRecordingPermission')
            ->once()
            ->andReturn(['granted' => false]);

        $this->mockOnboardingService->shouldReceive('isOnboardingComplete')
            ->once()
            ->andReturn(false);

        // When there's no route, the middleware does NOT call any state handlers
        // because the redirect condition requires a route to exist
        // The middleware just continues normally

        $response = $this->middleware->handle($request, function ($req) {
            return new Response('OK');
        });

        // Without a route, middleware allows the request to continue even with incomplete onboarding
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getContent());
    }

    public function test_redirects_when_permissions_missing(): void
    {
        $request = Request::create('/dashboard', 'GET');
        $route = new Route(['GET'], '/dashboard', []);
        $route->name('dashboard');
        $request->setRouteResolver(fn() => $route);

        // API key exists but permissions are missing
        $this->mockApiKeyService->shouldReceive('hasApiKey')
            ->once()
            ->andReturn(true);

        $this->mockPermissionService->shouldReceive('checkScreenRecordingPermission')
            ->once()
            ->andReturn(['granted' => false]);

        $this->mockOnboardingService->shouldReceive('isOnboardingComplete')
            ->once()
            ->andReturn(true);

        $this->mockOnboardingService->shouldReceive('handlePermissionRevocation')
            ->once();

        $response = $this->middleware->handle($request, function ($req) {
            return new Response('Should not reach here');
        });

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertTrue(str_contains($response->headers->get('Location'), '/onboarding'));
    }

    public function test_redirects_when_github_step_incomplete(): void
    {
        $request = Request::create('/dashboard', 'GET');
        $route = new Route(['GET'], '/dashboard', []);
        $route->name('dashboard');
        $request->setRouteResolver(fn() => $route);

        // API key and permissions exist but onboarding not complete
        $this->mockApiKeyService->shouldReceive('hasApiKey')
            ->once()
            ->andReturn(true);

        $this->mockPermissionService->shouldReceive('checkScreenRecordingPermission')
            ->once()
            ->andReturn(['granted' => true]);

        $this->mockOnboardingService->shouldReceive('isOnboardingComplete')
            ->once()
            ->andReturn(false);

        $this->mockOnboardingService->shouldReceive('handleIncompleteOnboarding')
            ->once();

        $response = $this->middleware->handle($request, function ($req) {
            return new Response('Should not reach here');
        });

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertTrue(str_contains($response->headers->get('Location'), '/onboarding'));
    }
}