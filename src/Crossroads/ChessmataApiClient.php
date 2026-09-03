<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

use BinktermPHP\Config;

/**
 * ChessmataApiClient
 *
 * L33TEST/Crossroads-owned thin HTTP client for the handful of Chessmata
 * endpoints the identity broker uses:
 *
 *   POST /api/auth/register     -> create the per-BinkTerm-caller account
 *   POST /api/auth/login        -> re-authenticate from the stored password
 *   POST /api/auth/refresh      -> mint a fresh access token from the refresh token
 *   POST /api/auth/api-keys     -> mint the durable cmk_ credential (needs a JWT)
 *
 * Talks to the self-hosted service over the internal container network
 * (default http://chessmata:9029), NOT the public /chessmata/ path. BinkTerm
 * never touches Chessmata's database -- only these documented HTTP responses.
 *
 * Not a generic capability; deliberately Chessmata-shaped.
 */
final class ChessmataApiClient implements ChessmataApiInterface
{
    private const TIMEOUT = 20;

    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $url = $baseUrl ?? (string)Config::env('CHESSMATA_INTERNAL_URL', 'http://chessmata:9029');
        $this->baseUrl = rtrim($url, '/');
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @return array{status:int, data:array<string,mixed>}
     */
    public function register(string $email, string $password, string $displayName, ?string $forwardedFor = null): array
    {
        return $this->post('/api/auth/register', [
            'email'       => $email,
            'password'    => $password,
            'displayName' => $displayName,
        ], null, $forwardedFor);
    }

    /**
     * @return array{status:int, data:array<string,mixed>}
     */
    public function login(string $email, string $password, ?string $forwardedFor = null): array
    {
        return $this->post('/api/auth/login', [
            'email'    => $email,
            'password' => $password,
        ], null, $forwardedFor);
    }

    /**
     * @return array{status:int, data:array<string,mixed>}
     */
    public function refresh(string $refreshToken): array
    {
        return $this->post('/api/auth/refresh', ['refreshToken' => $refreshToken]);
    }

    /**
     * @return array{status:int, data:array<string,mixed>}
     */
    public function createApiKey(string $accessToken, string $name): array
    {
        return $this->post('/api/auth/api-keys', ['name' => $name], $accessToken);
    }

    /**
     * Probe used only by acceptance tooling: does this credential authenticate,
     * and as which Chessmata user id?
     *
     * @return array{status:int, data:array<string,mixed>}
     */
    public function me(string $bearer): array
    {
        return $this->request('GET', '/api/auth/me', null, $bearer, null);
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array{status:int, data:array<string,mixed>}
     */
    private function post(string $path, ?array $payload, ?string $bearer = null, ?string $forwardedFor = null): array
    {
        return $this->request('POST', $path, $payload, $bearer, $forwardedFor);
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array{status:int, data:array<string,mixed>}
     */
    private function request(string $method, string $path, ?array $payload, ?string $bearer, ?string $forwardedFor): array
    {
        $headers = ['Accept: application/json'];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($bearer !== null && $bearer !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        // Forward the genuine caller address so Chessmata's per-IP rate limits
        // (register = 5/hour/IP) apply per real BinkTerm caller instead of
        // lumping every provisioning request under the container's address.
        // Chessmata:9029 is not reachable by untrusted clients on this path.
        if ($forwardedFor !== null && filter_var($forwardedFor, FILTER_VALIDATE_IP) !== false) {
            $headers[] = 'X-Forwarded-For: ' . $forwardedFor;
        }

        $ch = curl_init($this->baseUrl . $path);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('Chessmata API network error: ' . $err);
        }

        $data = json_decode((string)$body, true);
        if (!\is_array($data)) {
            $data = [];
        }

        return ['status' => $status, 'data' => $data];
    }
}
