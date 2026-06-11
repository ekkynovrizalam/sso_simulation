<?php

declare(strict_types=1);

namespace Iae\Central\Controllers;

use Iae\Central\Services\ActivityLogger;
use Iae\Central\Services\AuthService;
use Iae\Central\Services\RabbitMqService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MessageController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly RabbitMqService $rabbitMq,
        private readonly ActivityLogger $logger,
    ) {
    }

    public function publish(Request $request, Response $response): Response
    {
        $token = AuthService::extractBearerToken($request->getHeaderLine('Authorization'));
        if ($token === null) {
            return $this->json($response, ['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $identity = $this->authService->validateToken($token);
        if ($identity === null) {
            return $this->json($response, ['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        if ($identity['token_type'] !== 'm2m') {
            return $this->json($response, [
                'status' => 'error',
                'message' => 'Forbidden: M2M Bearer token required.',
            ], 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $routingKey = trim((string) ($body['routing_key'] ?? ''));
        $message = $body['message'] ?? $body['payload'] ?? null;

        if (!is_array($message) && !is_string($message)) {
            return $this->json($response, [
                'status' => 'error',
                'message' => 'message (object or string) is required.',
            ], 400);
        }

        $payload = [
            'source' => 'central-mock',
            'token_type' => $identity['token_type'],
            'subject' => $identity['subject'],
            'api_key' => $identity['api_key'],
            'team' => $identity['team'],
            'profile' => $identity['profile'],
            'routing_key' => $routingKey,
            'message' => is_string($message) ? ['body' => $message] : $message,
            'published_at' => gmdate('c'),
        ];

        try {
            $this->rabbitMq->publish($routingKey, $payload);
        } catch (\Throwable $e) {
            return $this->json($response, [
                'status' => 'error',
                'message' => 'Failed to publish to RabbitMQ: ' . $e->getMessage(),
            ], 503);
        }

        $this->logger->log(
            $this->authService->logSubject($identity),
            'rabbitmq',
            $this->authService->logDisplayName($identity),
            json_encode(['routing_key' => $routingKey], JSON_THROW_ON_ERROR)
        );

        return $this->json($response, [
            'status' => 'success',
            'exchange' => 'iae.central.exchange',
            'routing_key' => $routingKey,
        ]);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
