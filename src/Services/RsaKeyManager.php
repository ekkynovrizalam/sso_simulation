<?php

declare(strict_types=1);

namespace Iae\Central\Services;

use RuntimeException;

final class RsaKeyManager
{
    private const PRIVATE_FILE = 'private.pem';
    private const PUBLIC_FILE = 'public.pem';

    private string $privateKeyPem;
    private string $publicKeyPem;

    public function __construct(
        private readonly string $keysDirectory,
        private readonly string $keyId,
    ) {
        $this->ensureKeys();
        $private = file_get_contents($this->keysDirectory . '/' . self::PRIVATE_FILE);
        $public = file_get_contents($this->keysDirectory . '/' . self::PUBLIC_FILE);

        if ($private === false || $public === false) {
            throw new RuntimeException('Unable to load RSA key pair.');
        }

        $this->privateKeyPem = $private;
        $this->publicKeyPem = $public;
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    public function privateKeyPem(): string
    {
        return $this->privateKeyPem;
    }

    public function publicKeyPem(): string
    {
        return $this->publicKeyPem;
    }

    /** @return array{keys: list<array<string, string>>} */
    public function jwks(): array
    {
        $resource = openssl_pkey_get_public($this->publicKeyPem);
        if ($resource === false) {
            throw new RuntimeException('Invalid public key for JWKS export.');
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new RuntimeException('Unable to extract RSA parameters for JWKS.');
        }

        return [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'kid' => $this->keyId,
                    'n' => $this->base64UrlEncode($details['rsa']['n']),
                    'e' => $this->base64UrlEncode($details['rsa']['e']),
                ],
            ],
        ];
    }

    private function ensureKeys(): void
    {
        if (!is_dir($this->keysDirectory)) {
            mkdir($this->keysDirectory, 0750, true);
        }

        $privatePath = $this->keysDirectory . '/' . self::PRIVATE_FILE;
        $publicPath = $this->keysDirectory . '/' . self::PUBLIC_FILE;

        if (is_file($privatePath) && is_file($publicPath)) {
            return;
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            throw new RuntimeException('Failed to generate RSA key pair: ' . openssl_error_string());
        }

        $exported = openssl_pkey_export($resource, $privatePem);
        if (!$exported || $privatePem === null) {
            throw new RuntimeException('Failed to export private key.');
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false || !isset($details['key'])) {
            throw new RuntimeException('Failed to export public key.');
        }

        file_put_contents($privatePath, $privatePem);
        file_put_contents($publicPath, $details['key']);
        chmod($privatePath, 0600);
        chmod($publicPath, 0644);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
