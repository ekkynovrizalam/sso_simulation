<?php

declare(strict_types=1);

namespace Iae\Central\Controllers;

use Iae\Central\Services\RabbitMqService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthController
{
    public function __construct(
        private readonly RabbitMqService $rabbitMq,
    ) {
    }

    public function check(Request $request, Response $response): Response
    {
        $rabbit = $this->rabbitMq->ping();
        $board = $this->rabbitMq->peekBoard(1);

        $payload = [
            'status' => $rabbit['ok'] ? 'ok' : 'degraded',
            'service' => 'iae-central-mock',
            'timestamp' => gmdate('c'),
            'rabbitmq' => [
                'connected' => $rabbit['ok'],
                'board_queue' => $board['queue'],
                'message_count' => $board['message_count'],
            ],
        ];

        if (!$rabbit['ok']) {
            $payload['rabbitmq']['error'] = $rabbit['error'];
        }

        $response->getBody()->write((string) json_encode($payload, JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($rabbit['ok'] ? 200 : 503);
    }
}
