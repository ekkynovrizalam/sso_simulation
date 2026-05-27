<?php

declare(strict_types=1);

namespace Iae\Central\Services;

final class JwtCodec
{
    public function __construct(
        private readonly RsaKeyManager $keys,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function encode(array $payload): string
    {
        $header = $this->base64UrlEncode(json_encode([
            'typ' => 'JWT',
            'alg' => 'RS256',
            'kid' => $this->keys->keyId(),
        ], JSON_THROW_ON_ERROR));

        $body = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signingInput = $header . '.' . $body;

        $signature = '';
        $privateKey = openssl_pkey_get_private($this->keys->privateKeyPem());
        if ($privateKey === false) {
            return '';
        }

        $signed = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$signed) {
            return '';
        }

        return $signingInput . '.' . $this->base64UrlEncode($signature);
    }

    /** @return array<string, mixed>|null */
    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;
        $headerJson = $this->base64UrlDecode($headerB64);
        if ($headerJson === false) {
            return null;
        }

        try {
            $header = json_decode($headerJson, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (($header['alg'] ?? '') !== 'RS256') {
            return null;
        }

        if (isset($header['kid']) && $header['kid'] !== $this->keys->keyId()) {
            return null;
        }

        $signingInput = $headerB64 . '.' . $payloadB64;
        $signature = $this->base64UrlDecode($signatureB64);
        if ($signature === false) {
            return null;
        }

        $publicKey = openssl_pkey_get_public($this->keys->publicKeyPem());
        if ($publicKey === false) {
            return null;
        }

        $verified = openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            return null;
        }

        $payloadJson = $this->base64UrlDecode($payloadB64);
        if ($payloadJson === false) {
            return null;
        }

        try {
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        if (($payload['iss'] ?? '') !== 'iae-central-mock') {
            return null;
        }

        $exp = (int) ($payload['exp'] ?? 0);
        if ($exp > 0 && $exp < time()) {
            return null;
        }

        return $payload;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string|false
    {
        $padding = 4 - (strlen($data) % 4);
        if ($padding < 4) {
            $data .= str_repeat('=', $padding);
        }

        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
