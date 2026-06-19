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
            $nim = trim((string) ($body['nim'] ?? ''));

            return $this->issueM2m($response, $apiKey, $nim);
        }

        if ($email !== '' && $password !== '') {
            return $this->issueUser($response, $email, $password);
        }

        return $this->json($response, [
            'status' => 'error',
            'message' => 'Provide api_key (M2M) or email+password (End-User SSO).',
        ], 400);
    }

    private function issueM2m(Response $response, string $apiKey, string $nim): Response
    {
        if (!$this->authService->isValidApiKey($apiKey)) {
            return $this->json($response, [
                'status' => 'error',
                'message' => 'Unauthorized',
            ], 401);
        }

        if ($this->authService->keyRequiresNim($apiKey) && $nim === '') {
            return $this->json($response, [
                'status' => 'error',
                'message' => 'nim is required for M2M authentication.',
            ], 400);
        }

        $resolved = $this->authService->resolveM2mMeta($apiKey, $nim);
        if ($resolved === null) {
            return $this->json($response, [
                'status' => 'error',
                'message' => 'Unauthorized: nim is not registered for this API key.',
            ], 401);
        }

        $token = $this->authService->issueM2mToken($apiKey, $nim);

        $logSubject = $apiKey . '#' . $resolved['nim'];

        $this->logger->log(
            $logSubject,
            'sso_m2m',
            $resolved['nim'],
            json_encode([
                'grant_type' => 'client_credentials',
                'team' => $resolved['team'],
                'nim' => $resolved['nim'],
            ], JSON_THROW_ON_ERROR)
        );

        $app = [
            'client_id' => $apiKey,
            'name' => $resolved['app_name'],
            'team' => $resolved['team'],
            'nim' => $resolved['nim'],
        ];

        return $this->json($response, [
            'status' => 'success',
            'token_type' => 'm2m',
            'grant_type' => 'client_credentials',
            'algorithm' => 'RS256',
            'jwks_uri' => '/api/v1/auth/jwks',
            'token' => $token,
            'expires_in' => $this->jwtTtl,
            'app' => $app,
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
            'algorithm' => 'RS256',
            'jwks_uri' => '/api/v1/auth/jwks',
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
