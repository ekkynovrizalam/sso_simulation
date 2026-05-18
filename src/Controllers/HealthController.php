<?php

declare(strict_types=1);

namespace Iae\Central\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthController
{
    public function check(Request $request, Response $response): Response
    {
        $payload = [
            'status' => 'ok',
            'service' => 'iae-central-mock',
            'timestamp' => gmdate('c'),
        ];

        $response->getBody()->write((string) json_encode($payload, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
