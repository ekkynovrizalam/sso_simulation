<?php

declare(strict_types=1);

namespace Iae\Central\Controllers;

use Iae\Central\Services\RsaKeyManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class JwksController
{
    public function __construct(
        private readonly RsaKeyManager $keys,
    ) {
    }

    public function jwks(Request $request, Response $response): Response
    {
        $body = json_encode($this->keys->jwks(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($body);

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
