<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression: the CSRF token is stored per user and rotated on every login
 * (Auth::createAuthenticatedSession). A long-lived web page caches the token
 * in <meta name="csrf-token"> at render time, so a second authentication of
 * the same user — another browser, or reconnecting a Telnet/SSH session while
 * an admin editor tab is open — silently invalidates that cached copy. The
 * next mutating fetch() then fails with 403 errors.auth.invalid_csrf_token
 * before the route body ever runs.
 *
 * Observed during F6 (Terminal Navigation editor) human acceptance: after a
 * valid Save, the tester reconnected SyncTerm, then an invalid Save came back
 * "Invalid CSRF token" instead of the expected NavigationValidator error. The
 * live config was never touched (the request never reached the daemon), but
 * the editor could not perform another POST without a full page reload.
 *
 * Fix, mirroring TelnetUtils::apiRequest()'s self-heal on the terminal side:
 * the global fetch() wrapper in public_html/js/app.js re-syncs the token from
 * GET /api/auth/web-csrf (read-only), updates the meta tag, and retries the
 * original request once. This never bypasses validation — the server still
 * checks every mutating request against the live token.
 *
 * JS behaviour is asserted by source inspection (the established pattern here);
 * the endpoint's properties are asserted against its isolated handler body.
 */
final class WebCsrfSelfHealTest extends TestCase
{
    private string $appJs;
    private string $apiRoutes;
    private string $navTemplate;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->appJs       = (string) file_get_contents($root . '/public_html/js/app.js');
        $this->apiRoutes   = (string) file_get_contents($root . '/routes/api-routes.php');
        $this->navTemplate = (string) file_get_contents($root . '/templates/admin/terminal_navigation.twig');
    }

    // ---- GET /api/auth/web-csrf : read-only, session-gated -----------------

    private function webCsrfHandlerBody(): string
    {
        self::assertMatchesRegularExpression(
            "#SimpleRouter::get\\(\\s*'/auth/web-csrf'#",
            $this->apiRoutes,
            'the web re-sync endpoint is a GET'
        );
        $start = strpos($this->apiRoutes, "SimpleRouter::get('/auth/web-csrf'");
        self::assertNotFalse($start);
        $end = strpos($this->apiRoutes, "\n    });", (int) $start);
        self::assertNotFalse($end);

        return substr($this->apiRoutes, (int) $start, (int) $end - (int) $start);
    }

    public function testWebCsrfEndpointRequiresASessionAndReturnsTheStoredToken(): void
    {
        $body = $this->webCsrfHandlerBody();

        self::assertStringContainsString('RouteHelper::requireAuth()', $body, 'requires a valid session');
        self::assertStringContainsString("getValue(\$userId, 'csrf_token')", $body, 'returns the stored token');
        self::assertStringContainsString("'csrf_token' => \$token", $body);
        self::assertStringContainsString("'success' => true", $body);
    }

    public function testWebCsrfEndpointIsReadOnly(): void
    {
        $body = $this->webCsrfHandlerBody();

        // Must NOT mint a session or rotate the token.
        self::assertStringNotContainsString('createAuthenticatedSession', $body);
        self::assertStringNotContainsString('random_bytes', $body);
        self::assertStringNotContainsString("setValue(\$userId, 'csrf_token'", $body);
    }

    public function testWebCsrfEndpointIsNotTerminalSecretGated(): void
    {
        // The browser is the legitimate caller here, unlike GET /api/auth/csrf.
        $body = $this->webCsrfHandlerBody();
        self::assertStringNotContainsString('HTTP_X_BINKTERM_CLIENT_TOKEN', $body);
        self::assertStringNotContainsString('terminalRegistrationSecret', $body);
    }

    public function testGetAuthCsrfIsUntouchedAndStillSecretGated(): void
    {
        // The pre-existing terminal endpoint keeps its stricter contract.
        $start = strpos($this->apiRoutes, "SimpleRouter::get('/auth/csrf'");
        self::assertNotFalse($start);
        $body = substr($this->apiRoutes, (int) $start, 1400);
        self::assertStringContainsString('HTTP_X_BINKTERM_CLIENT_TOKEN', $body);
        self::assertStringContainsString('hash_equals(', $body);
    }

    // ---- app.js global fetch() wrapper -----------------------------------

    private function fetchWrapper(): string
    {
        $start = strpos($this->appJs, 'Intercept native fetch() calls');
        self::assertNotFalse($start, 'the global fetch() wrapper is present');
        $end = strpos($this->appJs, '}());', (int) $start);
        self::assertNotFalse($end);

        return substr($this->appJs, (int) $start, (int) $end - (int) $start);
    }

    public function testWrapperStillInjectsTheTokenOnMutatingSameOriginRequests(): void
    {
        $w = $this->fetchWrapper();
        // Regression of the original behaviour.
        self::assertStringContainsString("['POST', 'PUT', 'PATCH', 'DELETE']", $w);
        self::assertStringContainsString("meta[name=\"csrf-token\"]", $w);
        self::assertStringContainsString("'X-CSRF-Token'", $w);
        self::assertMatchesRegularExpression('#startsWith\(\s*[\'"]/[\'"]\s*\)#', $w, 'same-origin gate retained');
    }

    public function testWrapperRetriesOnceOnAStaleCsrfRejection(): void
    {
        $w = $this->fetchWrapper();

        // Only a 403 whose body carries the stale-CSRF error_code triggers a retry.
        self::assertStringContainsString('resp.status !== 403', $w);
        self::assertStringContainsString("error_code === 'errors.auth.invalid_csrf_token'", $w);

        // Re-sync comes from the read-only endpoint, and updates the meta tag.
        self::assertStringContainsString("_fetch('/api/auth/web-csrf'", $w);
        self::assertMatchesRegularExpression('#meta\.content\s*=\s*fresh#', $w, 'meta tag is refreshed');

        // Exactly one retry — a loop guard flag on the retried request.
        self::assertStringContainsString('__csrfRetried', $w);
        self::assertStringContainsString('options.__csrfRetried', $w);
    }

    public function testWrapperUsesTheOriginalFetchForTheResyncCallToAvoidRecursion(): void
    {
        $w = $this->fetchWrapper();
        // The resync itself must not go back through window.fetch (would re-enter
        // the wrapper) — it uses the captured native _fetch.
        self::assertMatchesRegularExpression(
            "#_fetch\\('/api/auth/web-csrf'#",
            $w
        );
        self::assertStringNotContainsString("window.fetch('/api/auth/web-csrf'", $w);
    }

    public function testWrapperDoesNotRetryNonMutatingOrCrossOriginOrOtherErrors(): void
    {
        $w = $this->fetchWrapper();

        // A non-mutating / cross-origin request is never "guarded", so it is
        // returned unretried.
        self::assertMatchesRegularExpression('#if \(!guarded \|\| options\.__csrfRetried\) \{\s*return inFlight;#s', $w);

        // A non-403 response is returned as-is before any body inspection.
        self::assertMatchesRegularExpression('#if \(resp\.status !== 403\) return resp;#', $w);

        // A 403 that is NOT a stale-CSRF body is returned as-is — the resync and
        // retry live behind an isStaleCsrfBody() guard.
        self::assertMatchesRegularExpression('#if \(!isStaleCsrfBody\(data\)\) return resp;#', $w);
        $guardPos  = strpos($w, 'if (!isStaleCsrfBody(data)) return resp;');
        $resyncPos = strpos($w, 'resyncCsrfToken().then(');
        self::assertNotFalse($guardPos);
        self::assertNotFalse($resyncPos);
        self::assertGreaterThan($guardPos, $resyncPos, 'resync happens only after the stale-CSRF guard');

        // Non-JSON 403 bodies fall back to the original response (json() rejects).
        self::assertMatchesRegularExpression('#clone\(\)\.json\(\)\.then\(\s*data =>#s', $w);
        self::assertMatchesRegularExpression('#\}\,\s*\(\) => resp\s*\)#s', $w, 'rejection handler returns the original response');
    }

    // ---- the F6 editor inherits the fix (no bespoke CSRF handling) --------

    public function testTerminalNavigationEditorReliesOnTheGlobalWrapper(): void
    {
        // The editor POSTs with plain fetch() and carries no CSRF logic of its
        // own, so the self-heal applies to Validate / Preview / Save uniformly.
        self::assertStringNotContainsString('X-CSRF-Token', $this->navTemplate);
        self::assertStringNotContainsString('csrf-token', $this->navTemplate);
        self::assertStringNotContainsString('web-csrf', $this->navTemplate);
        self::assertStringContainsString("fetch('/admin/api/terminal-navigation/validate'", $this->navTemplate);
        self::assertStringContainsString("fetch('/admin/api/terminal-navigation/config'", $this->navTemplate);
        self::assertStringContainsString("fetch('/admin/api/terminal-navigation/preview'", $this->navTemplate);
    }

    public function testInvalidSaveIsRejectedByValidationNotCsrf(): void
    {
        // Once the token is valid, the Save route relays the daemon write
        // result, which is written:false + errors[] for an invalid candidate —
        // never an auth failure.
        $root = dirname(__DIR__, 2);
        $adminRoutes = (string) file_get_contents($root . '/routes/admin-routes.php');
        $start = strpos($adminRoutes, "SimpleRouter::post('/terminal-navigation/config'");
        self::assertNotFalse($start);
        $end = strpos($adminRoutes, "\n        });", (int) $start);
        $body = substr($adminRoutes, (int) $start, (int) $end - (int) $start);

        self::assertStringContainsString('saveTerminalNavigationConfig($json)', $body);
        self::assertStringContainsString("'written'", $body);
        self::assertStringContainsString("'errors'", $body);

        // The daemon-side write path validates the candidate through
        // NavigationDefinitionLoader and returns NavigationWriteResult::invalid
        // *before* touching the filesystem, so a bad Save leaves the live file
        // exactly as it was.
        $writer = (string) file_get_contents($root . '/src/Terminal/Navigation/NavigationConfigWriter.php');
        self::assertMatchesRegularExpression(
            '#NavigationDefinitionLoader\([^)]*\)\)->fromJson\([^;]*;\s*if \(!\$load->isOk\(\)\) \{\s*return NavigationWriteResult::invalid#s',
            $writer,
            'writer refuses an invalid candidate before writing'
        );
    }
}
