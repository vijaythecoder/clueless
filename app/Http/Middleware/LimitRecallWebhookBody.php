<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LimitRecallWebhookBody
{
    public function handle(Request $request, Closure $next): Response
    {
        $maximumBytes = (int) config('services.recall.webhook_max_bytes', 1048576);
        $contentLength = filter_var($request->headers->get('Content-Length'), FILTER_VALIDATE_INT);

        if ($contentLength !== false && $contentLength > $maximumBytes) {
            return response('', 413);
        }

        if (strlen($request->getContent()) > $maximumBytes) {
            return response('', 413);
        }

        return $next($request);
    }
}
