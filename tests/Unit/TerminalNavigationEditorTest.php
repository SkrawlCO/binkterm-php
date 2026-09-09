<?php

declare(strict_types=1);

use BinktermPHP\Terminal\Navigation\NavigationDefinitionLoader;
use BinktermPHP\Terminal\Navigation\TerminalActionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Contract cover for the F6 M1 Terminal Navigation admin editor — a thin
 * frontend over the existing NavigationConfigWriter / admin-daemon write
 * boundary. The routes are asserted by source inspection (the established
 * pattern for route contracts here); the validate endpoint's behaviour is
 * exercised directly through {@see NavigationDefinitionLoader}, the same class
 * the route runs.
 */
final class TerminalNavigationEditorTest extends TestCase
{
    private string $adminRoutes;
    private string $template;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->adminRoutes = (string) file_get_contents($root . '/routes/admin-routes.php');
        $this->template    = (string) file_get_contents($root . '/templates/admin/terminal_navigation.twig');
    }

    // ---- routes ---------------------------------------------------------

    public function testAllRoutesExistAndRequireAdmin(): void
    {
        foreach ([
            "SimpleRouter::get('/terminal-navigation', function()",
            "SimpleRouter::get('/terminal-navigation/config', function()",
            "SimpleRouter::post('/terminal-navigation/validate', function()",
            "SimpleRouter::post('/terminal-navigation/config', function()",
            "SimpleRouter::post('/terminal-navigation/preview', function()",
        ] as $sig) {
            self::assertStringContainsString($sig, $this->adminRoutes, $sig);
        }

        // Every terminal-navigation route closure calls requireAdmin().
        preg_match_all(
            "#SimpleRouter::(?:get|post)\('/terminal-navigation[^']*', function\(\) \{(.+?)\n        \}\);#s",
            $this->adminRoutes,
            $m
        );
        self::assertGreaterThanOrEqual(5, count($m[1]), 'matched all route bodies');
        foreach ($m[1] as $body) {
            self::assertStringContainsString('RouteHelper::requireAdmin()', $body);
        }
    }

    public function testPreviewRouteRendersOffSessionAndNeverMutates(): void
    {
        $body = $this->routeBody("SimpleRouter::post('/terminal-navigation/preview'");
        self::assertStringContainsString('NavigationPreviewService', $body);
        self::assertStringContainsString('->render(', $body);
        self::assertStringContainsString('NavigationPreviewProfile::geometry(', $body);
        // Invalid JSON returns errors[] instead of a render; never writes.
        self::assertStringContainsString('$load->isOk()', $body);
        self::assertStringNotContainsString('NavigationConfigWriter', $body);
        self::assertStringNotContainsString('saveTerminalNavigationConfig', $body);
        self::assertStringNotContainsString('file_put_contents', $body);
        // Only the three supported geometries are honoured.
        self::assertStringContainsString('NavigationPreviewProfile::GEOMETRIES', $body);
    }

    public function testPreviewGeometriesAreThe80And132Set(): void
    {
        self::assertSame(
            ['80x24', '132x36', '132x51'],
            array_keys(\BinktermPHP\Terminal\Navigation\NavigationPreviewProfile::GEOMETRIES)
        );
    }

    public function testPreviewServiceRendersACandidateDefinitionToAnsi(): void
    {
        $json = json_encode([
            'schema' => 1, 'id' => 't', 'root' => 'main',
            'nodes' => [[
                'id' => 'main', 'label_fallback' => 'Main Menu',
                'items' => [
                    ['id' => 'n', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail'],
                    ['id' => 'q', 'label_fallback' => 'Log Off', 'hotkey' => 'q', 'action' => 'quit'],
                ],
            ]],
        ]);

        $registry = TerminalActionCatalog::defaultRegistry();
        $def = $this->loader()->fromJson($json, 'f');
        self::assertTrue($def->isOk());

        $profile = \BinktermPHP\Terminal\Navigation\NavigationPreviewProfile::geometry('132x36')
            ->withAllFeaturesEnabled($registry);
        $bytes = (new \BinktermPHP\Terminal\Navigation\NavigationPreviewService($registry))
            ->render($def->definition(), $profile);

        self::assertIsString($bytes);
        self::assertStringContainsString('Main Menu', $bytes);
        self::assertStringContainsString('Netmail', $bytes);
        self::assertStringContainsString("\033[", $bytes, 'carries ANSI');
    }

    public function testLoadRouteGoesThroughTheAdminDaemonAndExposesTheActionCatalog(): void
    {
        $body = $this->routeBody("SimpleRouter::get('/terminal-navigation/config'");
        self::assertStringContainsString('AdminDaemonClient())->getTerminalNavigationConfig()', $body);
        self::assertStringContainsString('TerminalActionCatalog::descriptors()', $body);
        self::assertStringContainsString('NavigationConfig::isRuntimeEnabled()', $body);
        // Never leaks a writable filesystem path to the client.
        self::assertStringNotContainsString("'path'", $body);
    }

    public function testValidateRouteRunsTheLoaderAndNeverWrites(): void
    {
        $body = $this->routeBody("SimpleRouter::post('/terminal-navigation/validate'");
        self::assertStringContainsString('NavigationDefinitionLoader', $body);
        self::assertStringContainsString('->fromJson(', $body);
        self::assertStringNotContainsString('NavigationConfigWriter', $body);
        self::assertStringNotContainsString('saveTerminalNavigationConfig', $body);
        self::assertStringNotContainsString('file_put_contents', $body);
    }

    public function testSaveRouteOnlyWritesThroughThePrivilegedDaemonBoundary(): void
    {
        $body = $this->routeBody("SimpleRouter::post('/terminal-navigation/config'");
        self::assertStringContainsString('AdminDaemonClient())->saveTerminalNavigationConfig($json)', $body);
        self::assertStringContainsString("'written'", $body);
        self::assertStringContainsString("'errors'", $body);
        // No direct filesystem write, no arbitrary path, no daemon path forwarding.
        self::assertStringNotContainsString('file_put_contents', $body);
        self::assertStringNotContainsString('fopen', $body);
        self::assertStringNotContainsString('TERMINAL_NAV_CONFIG', $body);
    }

    public function testEditorNeverTouchesEnvOrTheRuntimeFlag(): void
    {
        $block = $this->allTerminalNavRoutes();
        foreach (['putenv(', "\$_ENV['TERMINAL_NAV", 'TERMINAL_NAV_RUNTIME =', '->write(', 'supervisorctl', 'exec('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $block, "editor must not: {$forbidden}");
        }
    }

    public function testPageRouteRendersTheDedicatedTemplate(): void
    {
        self::assertMatchesRegularExpression(
            "#SimpleRouter::get\('/terminal-navigation', function\(\) \{.*?renderResponse\('admin/terminal_navigation\.twig'\)#s",
            $this->adminRoutes
        );
    }

    // ---- template ------------------------------------------------------

    public function testTemplateIsARawJsonEditorWithValidateAndSave(): void
    {
        self::assertStringContainsString('id="configEditor"', $this->template);
        self::assertStringContainsString("onclick=\"validateConfig()\"", $this->template);
        self::assertStringContainsString("onclick=\"saveConfig()\"", $this->template);
        self::assertStringContainsString('/admin/api/terminal-navigation/validate', $this->template);
        self::assertStringContainsString('/admin/api/terminal-navigation/config', $this->template);
        self::assertStringContainsString('/admin/api/terminal-navigation/preview', $this->template);
        self::assertStringContainsString('data-geo="80x24"', $this->template);
        self::assertStringContainsString('data-geo="132x51"', $this->template);
        // States the apply / no-restart / flag-unchanged facts.
        self::assertStringContainsString('ui.admin.terminal_navigation.apply_note', $this->template);
        // No form builder, no drag-reorder in M1.
        self::assertStringNotContainsString('sortable', strtolower($this->template));
    }

    public function testI18nKeysArePresentInEveryLocale(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['en', 'es', 'fr', 'it', 'de', 'ru'] as $loc) {
            $common = (string) file_get_contents("{$root}/config/i18n/{$loc}/common.php");
            $errors = (string) file_get_contents("{$root}/config/i18n/{$loc}/errors.php");
            self::assertStringContainsString("'ui.admin.terminal_navigation.heading'", $common, $loc);
            self::assertStringContainsString("'ui.admin.terminal_navigation.apply_note'", $common, $loc);
            self::assertStringContainsString("'ui.base.admin.terminal_navigation'", $common, $loc);
            self::assertStringContainsString("'errors.admin.terminal_navigation.save_failed'", $errors, $loc);
        }
    }

    // ---- the validate endpoint's actual behaviour ---------------------

    private function loader(): NavigationDefinitionLoader
    {
        return new NavigationDefinitionLoader(TerminalActionCatalog::defaultRegistry());
    }

    public function testValidateAcceptsAWellFormedDefinition(): void
    {
        $json = json_encode([
            'schema' => 1,
            'id'     => 'test.board',
            'root'   => 'main',
            'nodes'  => [[
                'id' => 'main', 'label_fallback' => 'Main',
                'items' => [
                    ['id' => 'mail', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail'],
                    ['id' => 'off',  'label_fallback' => 'Log Off', 'hotkey' => 'q', 'action' => 'quit'],
                ],
            ]],
        ]);

        $result = $this->loader()->fromJson($json, 'terminal_navigation.json');
        self::assertTrue($result->isOk(), $result->errorSummary());
        self::assertSame([], $result->errors());
    }

    public function testValidateReportsHotkeyConflictWithItemPath(): void
    {
        $json = json_encode([
            'schema' => 1, 'id' => 't', 'root' => 'main',
            'nodes' => [[
                'id' => 'main', 'label_fallback' => 'Main',
                'items' => [
                    ['id' => 'a', 'label_fallback' => 'A', 'hotkey' => 'n', 'action' => 'netmail'],
                    ['id' => 'b', 'label_fallback' => 'B', 'hotkey' => 'N', 'action' => 'echomail'],
                    ['id' => 'q', 'label_fallback' => 'Q', 'hotkey' => 'q', 'action' => 'quit'],
                ],
            ]],
        ]);

        $errors = $this->loader()->fromJson($json, 'terminal_navigation.json')->errors();
        self::assertNotSame([], $errors);
        $codes = array_map(static fn($e) => $e->code, $errors);
        self::assertContains('hotkey_conflict', $codes);
        foreach ($errors as $e) {
            if ($e->code === 'hotkey_conflict') {
                self::assertStringContainsString('nodes[main].items[', $e->path);
            }
        }
    }

    public function testValidateRejectsUnknownActionAndBadJson(): void
    {
        $unknownAction = json_encode([
            'schema' => 1, 'id' => 't', 'root' => 'main',
            'nodes' => [[
                'id' => 'main', 'label_fallback' => 'Main',
                'items' => [['id' => 'x', 'label_fallback' => 'X', 'hotkey' => 'x', 'action' => 'no_such_action']],
            ]],
        ]);
        $codes = array_map(
            static fn($e) => $e->code,
            $this->loader()->fromJson($unknownAction, 'f')->errors()
        );
        self::assertContains('unknown_action', $codes);

        $badJson = $this->loader()->fromJson('{ not json', 'f');
        self::assertFalse($badJson->isOk());
        self::assertContains('json', array_map(static fn($e) => $e->code, $badJson->errors()));
    }

    // ---- helpers -----------------------------------------------------

    private function routeBody(string $signatureStart): string
    {
        $pos = strpos($this->adminRoutes, $signatureStart);
        self::assertNotFalse($pos, $signatureStart);
        $end = strpos($this->adminRoutes, "\n        });", $pos);
        self::assertNotFalse($end);

        return substr($this->adminRoutes, $pos, $end - $pos);
    }

    private function allTerminalNavRoutes(): string
    {
        $start = strpos($this->adminRoutes, "SimpleRouter::get('/terminal-navigation/config'");
        $end   = strpos($this->adminRoutes, 'RLogin Doors API endpoints', (int) $start);

        return substr($this->adminRoutes, (int) $start, (int) $end - (int) $start);
    }
}
