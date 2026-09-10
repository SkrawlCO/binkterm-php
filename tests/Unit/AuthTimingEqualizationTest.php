<?php

declare(strict_types=1);

use BinktermPHP\Auth;
use BinktermPHP\Database;
use PHPUnit\Framework\TestCase;

/**
 * Login-timing user-enumeration mitigation in Auth::authenticateCredentials().
 *
 * Before the fix, a login for a non-existent username short-circuited the
 * `$user && password_verify(...)` check and returned in microseconds, while a
 * wrong password for a real account paid a full bcrypt verification — a clean
 * timing oracle for "does this username exist?".
 *
 * These pin the mitigation: the not-found branch now runs one bcrypt verify
 * against a fixed dummy hash, so both failure paths cost roughly the same, and
 * no observable behaviour (return value / response) changed.
 */
final class AuthTimingEqualizationTest extends TestCase
{
    private \PDO $pdo;
    private Auth $auth;
    private string $username;
    private string $password = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::getInstance()->getPdo();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('database not available: ' . $e->getMessage());
        }

        $this->pdo->beginTransaction();
        $this->username = 'authtiming_' . bin2hex(random_bytes(6));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, real_name, is_active)
             VALUES (?, ?, ?, TRUE)'
        );
        $stmt->execute([
            $this->username,
            password_hash($this->password, PASSWORD_DEFAULT),
            'Auth Timing ' . $this->username,
        ]);

        // Auth grabs the same singleton PDO, so it sees the in-transaction row.
        $this->auth = new Auth();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testCorrectCredentialsStillAuthenticate(): void
    {
        $user = $this->auth->authenticateCredentials($this->username, $this->password);
        self::assertIsArray($user);
        self::assertSame($this->username, $user['username']);
    }

    public function testWrongPasswordForRealUserIsRejected(): void
    {
        self::assertFalse($this->auth->authenticateCredentials($this->username, 'not-the-password'));
    }

    public function testUnknownUsernameIsRejectedIdenticallyToWrongPassword(): void
    {
        // Same return type/value for both failure shapes — no enumeration signal.
        $unknown = $this->auth->authenticateCredentials(
            'authtiming_nonexistent_' . bin2hex(random_bytes(6)),
            'whatever'
        );
        self::assertFalse($unknown);
    }

    public function testNotFoundBranchPerformsADummyBcryptVerification(): void
    {
        $rm = new \ReflectionMethod(Auth::class, 'authenticateCredentials');
        $src = implode('', array_slice(
            file($rm->getFileName()),
            $rm->getStartLine() - 1,
            $rm->getEndLine() - $rm->getStartLine() + 1
        ));

        self::assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$user\s*\)\s*\{\s*password_verify\(\s*\$password\s*,\s*self::DUMMY_PASSWORD_HASH\s*\)\s*;/',
            $src,
            'the unknown-username branch must run one password_verify() against the fixed dummy hash'
        );

        // The dummy hash is a syntactically valid bcrypt hash and matches
        // nothing an account could legitimately hold.
        $rc = new \ReflectionClass(Auth::class);
        $dummy = $rc->getConstant('DUMMY_PASSWORD_HASH');
        self::assertIsString($dummy);
        $info = password_get_info($dummy);
        self::assertSame('bcrypt', $info['algoName']);
        self::assertFalse(password_verify('', $dummy));
    }

    public function testUnknownUsernameTimingIsComparableToWrongPassword(): void
    {
        $median = static function (array $xs): float {
            sort($xs);
            $n = count($xs);
            return $n % 2 ? $xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2;
        };

        $time = function (string $user, string $pass): float {
            $t = hrtime(true);
            $this->auth->authenticateCredentials($user, $pass);
            return (hrtime(true) - $t) / 1e6; // ms
        };

        $wrongPw = [];
        $unknown = [];
        for ($i = 0; $i < 5; $i++) {
            $wrongPw[] = $time($this->username, 'nope-' . $i);
            $unknown[] = $time('authtiming_ghost_' . bin2hex(random_bytes(5)), 'nope-' . $i);
        }

        $mWrong = $median($wrongPw);
        $mUnknown = $median($unknown);

        // Generous margin: with the mitigation the ratio sits near 1.0; without
        // it the unknown path is ~1000x faster. Anything above 25% proves the
        // bcrypt work is actually happening on the not-found path.
        self::assertGreaterThan(
            $mWrong * 0.25,
            $mUnknown,
            sprintf('unknown-user median %.1fms vs wrong-password median %.1fms', $mUnknown, $mWrong)
        );
    }
}
