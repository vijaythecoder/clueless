<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidRecallWebhook;
use App\Services\Recall\RecallEventNormalizer;
use App\Services\Recall\RecallWebhookService;
use App\Services\Recall\RecallWebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class RecallWebhookController
{
    public function __invoke(
        Request $request,
        RecallWebhookVerifier $verifier,
        RecallEventNormalizer $normalizer,
        RecallWebhookService $webhooks,
    ): Response {
        $rawBody = $request->getContent();
        $webhookId = $this->header($request, 'webhook-id', 'svix-id');

        try {
            $verifier->verify(
                $rawBody,
                $webhookId,
                $this->header($request, 'webhook-timestamp', 'svix-timestamp'),
                $this->header($request, 'webhook-signature', 'svix-signature'),
            );
        } catch (InvalidRecallWebhook) {
            return response('', Response::HTTP_UNAUTHORIZED);
        } catch (Throwable $exception) {
            return $this->unexpectedFailure($exception, $webhookId);
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response('', Response::HTTP_BAD_REQUEST);
        }

        if (! is_array($payload)) {
            return response('', Response::HTTP_BAD_REQUEST);
        }

        try {
            $normalized = $normalizer->normalize($payload);

            if ($normalized === null) {
                return response('', Response::HTTP_NO_CONTENT);
            }

            $webhooks->persist($normalized, $webhookId);
        } catch (NotFoundHttpException) {
            return response('', Response::HTTP_NOT_FOUND);
        } catch (Throwable $exception) {
            return $this->unexpectedFailure($exception, $webhookId);
        }

        return response('', Response::HTTP_NO_CONTENT);
    }

    private function header(Request $request, string $primary, string $legacy): string
    {
        $value = $request->header($primary, $request->header($legacy, ''));

        return is_string($value) ? $value : '';
    }

    private function unexpectedFailure(Throwable $exception, string $webhookId): Response
    {
        Log::error('Unexpected Recall webhook failure', [
            'exception' => $exception::class,
            'webhook_id' => $webhookId,
        ]);

        return response('', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
