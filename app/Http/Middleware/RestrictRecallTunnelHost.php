<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RestrictRecallTunnelHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $ingressOnly = $this->isIngressOnly();
        $tunnelHost = $this->normalizeHost(config('services.recall.tunnel_host'));
        $host = $this->normalizeHost($request->headers->get('Host'));

        if (! $ingressOnly && ($tunnelHost === '' || $this->isLocalHost($host) || $host !== $tunnelHost)) {
            return $next($request);
        }

        if ($request->isMethod('POST') && $request->getPathInfo() === '/api/recall/webhooks') {
            return $next($request);
        }

        return response('', 404);
    }

    private function isIngressOnly(): bool
    {
        $processMarker = getenv('RECALL_INGRESS_ONLY');

        return ($processMarker !== false && filter_var($processMarker, FILTER_VALIDATE_BOOLEAN))
            || (bool) config('services.recall.ingress_only', false);
    }

    private function isLocalHost(string $host): bool
    {
        $nativePhpHost = parse_url((string) config('nativephp-internal.api_url'), PHP_URL_HOST);

        return in_array($host, [
            '127.0.0.1',
            'localhost',
            $this->normalizeHost(is_string($nativePhpHost) ? $nativePhpHost : null),
        ], true);
    }

    private function normalizeHost(mixed $host): string
    {
        if (! is_string($host)) {
            return '';
        }

        return strtolower(preg_replace('/:\\d+$/', '', trim($host)) ?? '');
    }
}
