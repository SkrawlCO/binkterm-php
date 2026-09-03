<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

/**
 * The subset of the Chessmata HTTP API the identity broker depends on. An
 * interface so acceptance/unit tests can supply a double without touching the
 * network. Each method returns array{status:int, data:array<string,mixed>}.
 */
interface ChessmataApiInterface
{
    public function baseUrl(): string;

    /** @return array{status:int, data:array<string,mixed>} */
    public function register(string $email, string $password, string $displayName, ?string $forwardedFor = null): array;

    /** @return array{status:int, data:array<string,mixed>} */
    public function login(string $email, string $password, ?string $forwardedFor = null): array;

    /** @return array{status:int, data:array<string,mixed>} */
    public function refresh(string $refreshToken): array;

    /** @return array{status:int, data:array<string,mixed>} */
    public function createApiKey(string $accessToken, string $name): array;

    /** @return array{status:int, data:array<string,mixed>} */
    public function me(string $bearer): array;
}
