<?php

declare(strict_types=1);

namespace Iae\Central\Services;

final class AuthService
{
    private JwtCodec $jwt;

    /**
     * @param array<string, array{student: string, team: string, app_name?: string}> $apiKeys
     * @param array<string, array{password: string, name: string, nim: string, email: string}> $citizens
     */
    public function __construct(
        private readonly array $apiKeys,
        private readonly array $citizens,
        RsaKeyManager $keys,
        private readonly int $jwtTtl,
    ) {
        $this->jwt = new JwtCodec($keys);
    }

    public function isValidApiKey(string $apiKey): bool
    {
        return isset($this->apiKeys[$apiKey]);
    }

    /** @return array{student: string, team: string, app_name?: string}|null */
    public function getApiKeyMeta(string $apiKey): ?array
    {
        return $this->apiKeys[$apiKey] ?? null;
    }

    /**
     * @return array{name: string, nim: string, email: string}|null
     */
    public function authenticateCitizen(string $email, string $password): ?array
    {
        $email = strtolower(trim($email));
        $citizen = $this->citizens[$email] ?? null;

        if ($citizen === null) {
            return null;
        }

        if (!hash_equals($citizen['password'], $password)) {
            return null;
        }

        return [
            'name' => $citizen['name'],
            'nim' => $citizen['nim'],
            'email' => $citizen['email'],
        ];
    }

    public function issueM2mToken(string $apiKey): string
    {
        $meta = $this->apiKeys[$apiKey];
        $now = time();

        $payload = [
            'iss' => 'iae-central-mock',
            'sub' => $apiKey,
            'iat' => $now,
            'exp' => $now + $this->jwtTtl,
            'grant_type' => 'client_credentials',
            'token_type' => 'm2m',
            'app' => [
                'client_id' => $apiKey,
                'name' => $meta['app_name'] ?? $meta['student'],
                'team' => $meta['team'],
            ],
        ];

        return $this->jwt->encode($payload);
    }

    /**
     * @param array{name: string, nim: string, email: string} $profile
     */
    public function issueUserToken(array $profile): string
    {
        $now = time();

        $payload = [
            'iss' => 'iae-central-mock',
            'sub' => $profile['email'],
            'iat' => $now,
            'exp' => $now + $this->jwtTtl,
            'grant_type' => 'password',
            'token_type' => 'user',
            'profile' => [
                'name' => $profile['name'],
                'nim' => $profile['nim'],
                'email' => $profile['email'],
            ],
        ];

        return $this->jwt->encode($payload);
    }

    /**
     * @return array{
     *   token_type: string,
     *   subject: string,
     *   api_key: ?string,
     *   team: ?string,
     *   profile: ?array{name: string, nim: string, email: string},
     *   exp: int
     * }|null
     */
    public function validateToken(string $token): ?array
    {
        $decoded = $this->jwt->decode($token);
        if ($decoded === null) {
            return null;
        }

        $tokenType = (string) ($decoded['token_type'] ?? 'm2m');
        $exp = (int) ($decoded['exp'] ?? 0);

        if ($tokenType === 'user') {
            $email = strtolower((string) ($decoded['sub'] ?? ''));
            $profile = $decoded['profile'] ?? null;

            if ($email === '' || !is_array($profile) || !isset($this->citizens[$email])) {
                return null;
            }

            return [
                'token_type' => 'user',
                'subject' => $email,
                'api_key' => null,
                'team' => null,
                'profile' => [
                    'name' => (string) ($profile['name'] ?? $this->citizens[$email]['name']),
                    'nim' => (string) ($profile['nim'] ?? $this->citizens[$email]['nim']),
                    'email' => $email,
                ],
                'exp' => $exp,
            ];
        }

        $apiKey = (string) ($decoded['sub'] ?? '');

        if ($apiKey === '' || !$this->isValidApiKey($apiKey)) {
            return null;
        }

        $app = $decoded['app'] ?? [];

        return [
            'token_type' => 'm2m',
            'subject' => $apiKey,
            'api_key' => $apiKey,
            'team' => (string) (is_array($app) ? ($app['team'] ?? '') : ($decoded['team'] ?? '')),
            'profile' => null,
            'exp' => $exp,
        ];
    }

    /** Display label for activity logs. */
    public function logSubject(array $identity): string
    {
        return $identity['api_key'] ?? $identity['subject'];
    }

    public function logDisplayName(array $identity): ?string
    {
        if ($identity['profile'] !== null) {
            return $identity['profile']['name'];
        }

        $meta = $this->getApiKeyMeta($identity['api_key'] ?? '');

        return $meta['student'] ?? null;
    }

    public static function extractBearerToken(?string $authorization): ?string
    {
        if ($authorization === null || $authorization === '') {
            return null;
        }

        if (preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }
}
