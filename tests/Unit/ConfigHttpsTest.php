<?php

declare(strict_types=1);

/**
 * Regression tests for Config::isHttps() — the HTTPS determination the
 * `binktermphp_session` cookie's Secure attribute relies on
 * (routes/api-routes.php).
 *
 * isHttps() deliberately reuses Config::getSiteUrl()'s existing precedence
 * (a configured SITE_URL wins, falling back to $_SERVER['HTTPS']) rather than
 * consulting X-Forwarded-Proto or any other client-influenceable header, so
 * these tests exercise exactly that precedence via the same $_ENV/$_SERVER
 * seams getSiteUrl() itself reads.
 *
 * setcookie() itself is not exercised here (PHP cannot send real headers in
 * the CLI SAPI test runner) — the source-guardrail test below instead proves
 * that both cookie-creation call sites actually pass isHttps() as the
 * `secure` option, so the decision function proven here is the same one
 * wired into the cookie.
 */

use BinktermPHP\Config;
use PHPUnit\Framework\TestCase;

final class ConfigHttpsTest extends TestCase
{
    /** @var string|null Prior $_ENV['SITE_URL'] value, to restore in tearDown. */
    private $priorSiteUrl;
    private bool $priorSiteUrlWasSet;

    /** @var string|null Prior $_SERVER['HTTPS'] value, to restore in tearDown. */
    private $priorHttps;
    private bool $priorHttpsWasSet;

    protected function setUp(): void
    {
        $this->priorSiteUrlWasSet = array_key_exists('SITE_URL', $_ENV);
        $this->priorSiteUrl = $_ENV['SITE_URL'] ?? null;

        $this->priorHttpsWasSet = array_key_exists('HTTPS', $_SERVER);
        $this->priorHttps = $_SERVER['HTTPS'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->priorSiteUrlWasSet) {
            $_ENV['SITE_URL'] = $this->priorSiteUrl;
        } else {
            unset($_ENV['SITE_URL']);
        }

        if ($this->priorHttpsWasSet) {
            $_SERVER['HTTPS'] = $this->priorHttps;
        } else {
            unset($_SERVER['HTTPS']);
        }
    }

    public function testHttpsSiteUrlIsSecure(): void
    {
        $_ENV['SITE_URL'] = 'https://example.test';
        unset($_SERVER['HTTPS']);

        self::assertTrue(Config::isHttps(), 'a configured https:// SITE_URL must be treated as secure');
    }

    public function testHttpSiteUrlIsNotSecureEvenIfServerHttpsIsOn(): void
    {
        // SITE_URL must win over $_SERVER['HTTPS'] per getSiteUrl()'s own
        // documented precedence — an operator's explicit configuration is
        // authoritative, not a possibly-stale/misconfigured server variable.
        $_ENV['SITE_URL'] = 'http://example.test';
        $_SERVER['HTTPS'] = 'on';

        self::assertFalse(Config::isHttps(), 'a configured http:// SITE_URL must take precedence over $_SERVER[HTTPS]');
    }

    public function testFallsBackToServerHttpsWhenSiteUrlUnset(): void
    {
        unset($_ENV['SITE_URL']);
        $_SERVER['HTTPS'] = 'on';

        self::assertTrue(Config::isHttps(), 'must fall back to $_SERVER[HTTPS] when SITE_URL is not configured');
    }

    public function testPlainHttpDevelopmentIsNotForcedSecure(): void
    {
        // Supported local-development context: no SITE_URL, no HTTPS server
        // variable. Must not be forced secure -- doing so would break plain
        // HTTP local development, which this change must not do.
        unset($_ENV['SITE_URL']);
        unset($_SERVER['HTTPS']);

        self::assertFalse(Config::isHttps(), 'plain HTTP development context must not be forced secure');
    }

    /**
     * Source-level guardrail: both binktermphp_session cookie-creation sites
     * in routes/api-routes.php must pass Config::isHttps() as their `secure`
     * option, so the precedence proven above is actually what the cookie
     * uses.
     */
    public function testSessionCookieCreationSitesUseConfigIsHttps(): void
    {
        $src = file_get_contents(__DIR__ . '/../../routes/api-routes.php');
        self::assertIsString($src);

        $occurrences = substr_count($src, "'secure'   => Config::isHttps(),");
        self::assertSame(
            2,
            $occurrences,
            'expected both binktermphp_session setcookie() call sites to set secure via Config::isHttps()'
        );
    }
}
