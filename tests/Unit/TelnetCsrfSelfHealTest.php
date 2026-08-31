<?php

declare(strict_types=1);

use BinktermPHP\TelnetServer\TelnetUtils;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/ChatHandler.php';

/**
 * Regression: a long-lived terminal session caches its CSRF token from its own
 * login, but the token is stored per user and rotated on every login. A second
 * authentication of the same user (another terminal/SSH/web/QWK session)
 * silently invalidates the terminal session's cached copy, and the next
 * mutating API call — e.g. sending a message from a Crossroads contextual DM —
 * fails with errors.auth.invalid_csrf_token even though the session is valid.
 *
 * TelnetUtils::apiRequest() now transparently re-syncs the token for the SAME
 * authenticated session (GET /api/auth/csrf, read-only, secret-gated) and
 * retries the original request once. This never bypasses validation: the
 * server still validates every request against the live token.
 *
 * These tests pin the decision logic, the token contract, the read-only /
 * secret-gated refresh endpoint, and that contextual chat (room and DM) sends
 * through the very same TelnetUtils::apiRequest CSRF path as ordinary Local
 * Chat — no separate implementation.
 */
final class TelnetCsrfSelfHealTest extends TestCase
{
    protected function tearDown(): void
    {
        TelnetUtils::setCsrfToken(null);
    }

    // ---- session token contract ---------------------------------------

    public function testCsrfTokenIsSeededAndReadBack(): void
    {
        self::assertNull(TelnetUtils::getCsrfToken());

        TelnetUtils::setCsrfToken('abc123');
        self::assertSame('abc123', TelnetUtils::getCsrfToken());

        TelnetUtils::setCsrfToken('');
        self::assertNull(TelnetUtils::getCsrfToken(), 'empty is normalised to null');

        TelnetUtils::setCsrfToken(null);
        self::assertNull(TelnetUtils::getCsrfToken());
    }

    // ---- self-heal decision (pure) -----------------------------------

    public function testHealsOnlyOnAStaleCsrfRejectionOfAMutatingRequest(): void
    {
        $body = ['success' => false, 'error_code' => 'errors.auth.invalid_csrf_token'];

        self::assertTrue(TelnetUtils::shouldHealStaleCsrf(true, 403, $body, false, 'sess', 'tok'));
    }

    public function testDoesNotHealWhenConditionsAreNotAStaleCsrfRejection(): void
    {
        $stale = ['error_code' => 'errors.auth.invalid_csrf_token'];

        // not a mutating request
        self::assertFalse(TelnetUtils::shouldHealStaleCsrf(false, 403, $stale, false, 'sess', 'tok'));
        // wrong status
        self::assertFalse(TelnetUtils::shouldHealStaleCsrf(true, 401, $stale, false, 'sess', 'tok'));
        self::assertFalse(TelnetUtils::shouldHealStaleCsrf(true, 200, $stale, false, 'sess', 'tok'));
        // a different 403 (real authorization failure) must NOT trigger a token swap
        self::assertFalse(TelnetUtils::shouldHealStaleCsrf(true, 403, ['error_code' => 'errors.auth.forbidden'], false, 'sess', 'tok'));
        self::assertFalse(TelnetUtils::shouldHealStaleCsrf(true, 403, ['raw' => 'nope'], false, 'sess', 'tok'));
        // already healed once — do not loop
        self::assertFalse(TelnetUtils::shouldHealStaleCsrf(true, 403, $stale, true, 'sess', 'tok'));
        // no session / no token to re-sync
        self::assertFalse(TelnetUtils::shouldHealStaleCsrf(true, 403, $stale, false, null, 'tok'));
        self::assertFalse(TelnetUtils::shouldHealStaleCsrf(true, 403, $stale, false, '', 'tok'));
        self::assertFalse(TelnetUtils::shouldHealStaleCsrf(true, 403, $stale, false, 'sess', null));
    }

    // ---- GET /api/auth/csrf : read-only + secret-gated ---------------

    public function testAuthCsrfEndpointIsSecretGatedReadOnlyAndReturnsTheToken(): void
    {
        $routes = file_get_contents(__DIR__ . '/../../routes/api-routes.php');
        self::assertIsString($routes);

        self::assertMatchesRegularExpression(
            "#SimpleRouter::get\\(\\s*'/auth/csrf'#",
            $routes,
            'the refresh endpoint is a GET'
        );

        // Locate the handler body and assert its properties in isolation.
        $start = strpos($routes, "SimpleRouter::get('/auth/csrf'");
        self::assertNotFalse($start);
        $body = substr($routes, $start, 1400);

        self::assertStringContainsString('RouteHelper::requireAuth()', $body, 'requires a valid session');
        self::assertStringContainsString('HTTP_X_BINKTERM_CLIENT_TOKEN', $body, 'gated on the terminal secret header');
        self::assertStringContainsString('hash_equals(', $body, 'constant-time secret comparison');
        self::assertStringContainsString("getValue(\$userId, 'csrf_token')", $body, 'returns the stored token');
        self::assertStringContainsString("'csrf_token' => \$token", $body);

        // Must NOT mint a new session / rotate the token.
        self::assertStringNotContainsString('createAuthenticatedSession', $body);
        self::assertStringNotContainsString('random_bytes', $body);
        self::assertStringNotContainsString("setValue(\$userId, 'csrf_token'", $body);
    }

    // ---- contextual chat uses the same CSRF path as ordinary chat ---

    public function testContextualChatSendsThroughTheSharedApiRequestCsrfPath(): void
    {
        $chat = file_get_contents(__DIR__ . '/../../telnet/src/ChatHandler.php');
        self::assertIsString($chat);

        // showRoom() / showDirectMessage() are thin wrappers over the same run()
        // used by ordinary Local Chat's show(); no bespoke send path.
        self::assertMatchesRegularExpression('#function showRoom\([^)]*\)\s*:\s*bool\s*\{\s*return \$this->run\(#s', $chat);
        self::assertMatchesRegularExpression('#function showDirectMessage\([^)]*\)\s*:\s*bool\s*\{\s*return \$this->run\(#s', $chat);
        self::assertMatchesRegularExpression('#function show\([^)]*\)\s*:\s*void\s*\{\s*\$this->run\(#s', $chat);

        // Every message send (room and DM) goes through ChatHandler::apiRequest,
        // which forwards $state['csrf_token'] to TelnetUtils::apiRequest.
        self::assertSame(
            1,
            substr_count($chat, "\$this->apiRequest('POST', '/api/chat/send'"),
            'exactly one send path for both room and DM'
        );
        self::assertMatchesRegularExpression(
            "#return TelnetUtils::apiRequest\\([^;]*\\\$state\\['csrf_token'\\] \\?\\? null#s",
            $chat,
            'ChatHandler::apiRequest forwards the session CSRF token to TelnetUtils'
        );

        // No second CSRF/token handling of its own.
        self::assertStringNotContainsString('X-CSRF-Token', $chat, 'ChatHandler never builds CSRF headers itself');
    }

    public function testBbsSessionSeedsTheApiLayerCsrfTokenAtLogin(): void
    {
        $bbs = file_get_contents(__DIR__ . '/../../telnet/src/BbsSession.php');
        self::assertIsString($bbs);

        $assign = strpos($bbs, "\$state['csrf_token'] = \$loginResult['csrf_token']");
        $seed   = strpos($bbs, "TelnetUtils::setCsrfToken(\$state['csrf_token'])");

        self::assertNotFalse($assign, 'session token still cached from the login result');
        self::assertNotFalse($seed, 'API-layer token is seeded from the same value');
        self::assertGreaterThan($assign, $seed, 'seeded right after the session token at login');
    }
}
