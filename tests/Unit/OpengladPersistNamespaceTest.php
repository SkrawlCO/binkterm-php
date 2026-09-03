<?php

declare(strict_types=1);

use BinktermPHP\Crossroads\OpengladPersistNamespace;
use PHPUnit\Framework\TestCase;

/**
 * The OpenGlad WebDoor per-user browser-persistence partition token.
 * It is a partition identifier, never a credential — these tests pin the
 * properties that matter (shape, determinism, per-user distinctness, and
 * independence from APP_SECRET so a secret rotation cannot orphan Companies).
 */
final class OpengladPersistNamespaceTest extends TestCase
{
    public function testTokenShapeIs40LowercaseHex(): void
    {
        $token = OpengladPersistNamespace::forUser(19);

        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $token);
        self::assertSame(40, strlen($token));
        self::assertLessThanOrEqual(64, strlen($token));
    }

    public function testDeterministicForTheSameUser(): void
    {
        self::assertSame(
            OpengladPersistNamespace::forUser(19),
            OpengladPersistNamespace::forUser(19)
        );
    }

    public function testDistinctBetweenUsers(): void
    {
        $tokens = array_map(
            static fn(int $id): string => OpengladPersistNamespace::forUser($id),
            [1, 2, 3, 19, 20, 21, 100000]
        );

        self::assertSame($tokens, array_values(array_unique($tokens)));
    }

    public function testIndependentOfAppSecret(): void
    {
        $before = getenv('APP_SECRET');

        putenv('APP_SECRET=first-secret-value');
        $_ENV['APP_SECRET'] = 'first-secret-value';
        $a = OpengladPersistNamespace::forUser(19);

        putenv('APP_SECRET=a-completely-different-secret');
        $_ENV['APP_SECRET'] = 'a-completely-different-secret';
        $b = OpengladPersistNamespace::forUser(19);

        if ($before === false) {
            putenv('APP_SECRET');
            unset($_ENV['APP_SECRET']);
        } else {
            putenv('APP_SECRET=' . $before);
            $_ENV['APP_SECRET'] = $before;
        }

        self::assertSame($a, $b, 'the persistence token must survive APP_SECRET rotation');
    }

    public function testMatchesTheDocumentedDerivation(): void
    {
        // The exact contract index.php and the carried OpenGlad patch depend on.
        $userId = 19;
        $expected = substr(hash('sha256', 'openglad-persist-v1:' . $userId), 0, 40);

        self::assertSame($expected, OpengladPersistNamespace::forUser($userId));
    }

    public function testRejectsNonPositiveUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OpengladPersistNamespace::forUser(0);
    }
}
