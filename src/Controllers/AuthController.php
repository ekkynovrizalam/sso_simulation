<?php

declare(strict_types=1);

namespace Iae\Central\Controllers;

use Iae\Central\Services\ActivityLogger;
use Iae\Central\Services\AuthService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly ActivityLogger $logger,
        private readonly int $jwtTtl,
    ) {
    }

    public function token(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $apiKey = trim((string) ($body['api_key'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($apiKey !== '') {
            return $this->issueM2m($response, $apiKey);
        }

        if ($email !== '' && $password !== '') {
            return $this->issueUser($response, $email, $password);
        }

        return $this->json($response, [
            'status' => 'error',
            'message' => 'Provide api_key (M2M) or email+password (End-User SSO).',
        ], 400);
    }

    private function issueM2m(Response $response, string $apiKey): Response
    {
        if (!$this->authService->isValidApiKey($apiKey)) {
            return $this->json($response, [
                'status' => 'error',
                'message' => 'Unauthorized',
            ], 401);
        }

        $token = $this->authService->issueM2mToken($apiKey);
        $meta = $this->authService->getApiKeyMeta($apiKey);

        $this->logger->log(
            $apiKey,
            'sso_m2m',
            $meta['student'] ?? null,
            json_encode(['grant_type' => 'client_credentials'], JSON_THROW_ON_ERROR)
        );

        return $this->json($response, [
            'status' => 'success',
            'token_type' => 'm2m',
            'grant_type' => 'client_credentials',
            'token' => $token,
            'expires_in' => $this->jwtTtl,
            'app' => [
                'client_id' => $apiKey,
                'name' => $meta['app_name'] ?? $meta['student'],
                'team' => $meta['team'],
            ],
        ]);
    }

    private function issueUser(Response $response, string $email, string $password): Response
    {
        $profile = $this->authService->authenticateCitizen($email, $password);

        if ($profile === null) {
            return $this->json($response, [
                'status' => 'error',
                'message' => 'Unauthorized',
            ], 401);
        }

        $token = $this->authService->issueUserToken($profile);

        $this->logger->log(
            $profile['email'],
            'sso_user',
            $profile['name'],
            json_encode([
                'grant_type' => 'password',
                'nim' => $profile['nim'],
            ], JSON_THROW_ON_ERROR)
        );

        return $this->json($response, [
            'status' => 'success',
            'token_type' => 'user',
            'grant_type' => 'password',
            'token' => $token,
            'expires_in' => $this->jwtTtl,
            'profile' => $profile,
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
