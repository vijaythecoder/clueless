<?php

namespace App\Http\Middleware;

use App\Services\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckOnboarding
{
    protected ApiKeyService $apiKeyService;

    public function __construct(ApiKeyService $apiKeyService)
    {
        $this->apiKeyService = $apiKeyService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Routes that should be accessible without API key
        $excludedRoutes = [
            'onboarding',
            'api-keys.edit',
            'api-keys.update',
            'api-keys.destroy',
            'api.openai.status',
            'api.openai.api-key.store',
            'appearance',
        ];

        // Skip check for excluded routes
        if ($request->route() && in_array($request->route()->getName(), $excludedRoutes)) {
            return $next($request);
        }

        // Skip if API request (they handle their own errors)
        if ($request->is('api/*')) {
            return $next($request);
        }

        // Check if API key exists
        if (! $this->apiKeyService->hasApiKey()) {
            // If not on onboarding page and no API key, redirect to onboarding
            if ($request->route() && $request->route()->getName() !== 'onboarding') {
                return redirect()->route('onboarding');
            }
        }

        return $next($request);
    }
}
