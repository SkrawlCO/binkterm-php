<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RememberMeContractTest extends TestCase
{
    private string $loginTemplate;
    private string $apiRoutes;
    private string $authSource;
    private string $configSource;
    private string $telnetSession;
    private string $sshSession;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->loginTemplate = (string)file_get_contents($root . '/templates/login.twig');
        $this->apiRoutes = (string)file_get_contents($root . '/routes/api-routes.php');
        $this->authSource = (string)file_get_contents($root . '/src/Auth.php');
        $this->configSource = (string)file_get_contents($root . '/src/Config.php');
        $this->telnetSession = (string)file_get_contents($root . '/telnet/src/BbsSession.php');
        $this->sshSession = (string)file_get_contents($root . '/ssh/src/SshSession.php');
    }

    public function testLoginSubmissionSendsCheckboxStateAsBoolean(): void
    {
        self::assertStringContainsString(
            "remember: $('#remember').is(':checked')",
            $this->loginTemplate
        );
    }

    public function testExplicitRememberValuesAndLegacyDefaultAreStrict(): void
    {
        self::assertStringContainsString(
            "is_array(\$input) && array_key_exists('remember', \$input)",
            $this->apiRoutes
        );
        self::assertStringContainsString(
            "? \$input['remember'] === true",
            $this->apiRoutes
        );
        self::assertStringContainsString(
            ': true;',
            $this->loginRoute()
        );
    }

    public function testOnlyRememberedLoginGetsPersistentCookieExpiry(): void
    {
        $route = $this->loginRoute();

        self::assertStringContainsString('if ($remember) {', $route);
        self::assertStringContainsString(
            "\$cookieOptions['expires'] = time() + 86400 * 30;",
            $route
        );
        self::assertStringContainsString(
            "setcookie('binktermphp_session', \$sessionId, \$cookieOptions);",
            $route
        );
        self::assertSame(1, substr_count($route, "\$cookieOptions['expires']"));
    }

    public function testCookiePropertiesOtherThanPersistenceRemainUnchanged(): void
    {
        $route = $this->loginRoute();

        self::assertStringContainsString("'path'     => '/',", $route);
        self::assertStringContainsString("'httponly' => true,", $route);
        self::assertStringContainsString("'samesite' => 'Lax',", $route);
    }

    public function testServerSessionLifetimeRemainsFixedAtThirtyDays(): void
    {
        self::assertStringContainsString(
            "NOW() + INTERVAL \\'' . Config::SESSION_LIFETIME . ' seconds\\'",
            $this->authSource
        );
        self::assertStringContainsString(
            'const SESSION_LIFETIME = 86400 * 30;',
            $this->configSource
        );
        self::assertStringNotContainsString('remember', $this->authSource);
    }

    public function testTerminalAndSshLoginPayloadsStillOmitRemember(): void
    {
        $telnetLogin = $this->between(
            $this->telnetSession,
            "\$result = \$this->apiRequest('POST', '/api/auth/login'",
            'if ($result['
        );
        $sshLogin = $this->between(
            $this->sshSession,
            "\$url  = \$this->apiBase . '/api/auth/login';",
            '$response  = curl_exec($ch);'
        );

        self::assertStringContainsString("'service'  => \$transport", $telnetLogin);
        self::assertStringNotContainsString('remember', $telnetLogin);
        self::assertStringContainsString("'service' => 'ssh'", $sshLogin);
        self::assertStringNotContainsString('remember', $sshLogin);
    }

    public function testLogoutStillRevokesSessionAndExpiresCookie(): void
    {
        $logout = $this->between(
            $this->apiRoutes,
            "SimpleRouter::post('/auth/logout'",
            '// Gateway token verification endpoint'
        );

        self::assertStringContainsString('$auth->logout($sessionId);', $logout);
        self::assertStringContainsString(
            "setcookie('binktermphp_session', '', time() - 3600, '/');",
            $logout
        );
    }

    private function loginRoute(): string
    {
        return $this->between(
            $this->apiRoutes,
            "SimpleRouter::post('/auth/login'",
            "SimpleRouter::post('/auth/logout'"
        );
    }

    private function between(string $source, string $startNeedle, string $endNeedle): string
    {
        $start = strpos($source, $startNeedle);
        self::assertNotFalse($start, "Missing start marker: {$startNeedle}");
        $end = strpos($source, $endNeedle, $start);
        self::assertNotFalse($end, "Missing end marker: {$endNeedle}");

        return substr($source, $start, $end - $start);
    }
}
