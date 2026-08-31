<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PgpPublicKeyserverFeatureTest extends TestCase
{
    private string $webRoutes;
    private string $apiRoutes;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->webRoutes = (string)file_get_contents($root . '/routes/web-routes.php');
        $this->apiRoutes = (string)file_get_contents($root . '/routes/api-routes.php');
    }

    public function testPublicKeyserverFlagRequiresCorePgp(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/BbsConfig.php');
        $method = $this->between(
            $source,
            'public static function isPgpPublicKeyserverEnabled(): bool',
            'public static function isAnonymousExperienceDiscoveryEnabled(): bool'
        );

        self::assertStringContainsString("self::isFeatureEnabled('pgp')", $method);
        self::assertStringContainsString("self::isFeatureEnabled('pgp_public_keyserver')", $method);
    }

    public function testPublicationDefaultsOnForExistingPgpInstallations(): void
    {
        $json = (string)file_get_contents(dirname(__DIR__, 2) . '/config/bbs.json.example');
        $config = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($config['features']['pgp']);
        self::assertTrue($config['features']['pgp_public_keyserver']);
    }

    public function testEveryPublicKeyserverRouteUsesThePublicationGate(): void
    {
        $routes = [
            ["SimpleRouter::get('/keyserver'", "SimpleRouter::get('/pks/lookup'"],
            ["SimpleRouter::get('/pks/lookup'", "SimpleRouter::get('/pks/lookup/v1/get/{search}'"],
            ["SimpleRouter::get('/pks/lookup/v1/get/{search}'", "SimpleRouter::get('/.well-known/openpgpkey/{domain}/hkps'"],
            ["SimpleRouter::get('/.well-known/openpgpkey/{domain}/hkps'", "SimpleRouter::post('/pks/add'"],
            ["SimpleRouter::post('/pks/add'", "SimpleRouter::get('/pks/download/{fingerprint}'"],
            ["SimpleRouter::get('/pks/download/{fingerprint}'", "SimpleRouter::get('/chat'"],
        ];

        foreach ($routes as [$start, $end]) {
            $route = $this->between($this->webRoutes, $start, $end);
            self::assertStringContainsString(
                '!\\BinktermPHP\\BbsConfig::isPgpPublicKeyserverEnabled()',
                $route,
                $start . ' must be disabled unless core PGP and publication are enabled'
            );
            self::assertStringContainsString('http_response_code(404)', $route);
        }
    }

    public function testPksAddRemainsAuthenticatedInsidePublicationBoundary(): void
    {
        $route = $this->between(
            $this->webRoutes,
            "SimpleRouter::post('/pks/add'",
            "SimpleRouter::get('/pks/download/{fingerprint}'"
        );

        self::assertStringContainsString('isPgpPublicKeyserverEnabled()', $route);
        self::assertStringContainsString('RouteHelper::requireAuth()', $route);
    }

    public function testAuthenticatedCorePgpApisDoNotRequirePublicationFlag(): void
    {
        $lookup = $this->between(
            $this->apiRoutes,
            "SimpleRouter::get('/pgp/lookup'",
            "SimpleRouter::get('/user/pgp/keys'"
        );
        $keyManagement = $this->between(
            $this->apiRoutes,
            "SimpleRouter::get('/user/pgp/keys'",
            "SimpleRouter::post('/user/pgp/keys'"
        );

        foreach ([$lookup, $keyManagement] as $route) {
            self::assertStringContainsString("isFeatureEnabled('pgp')", $route);
            self::assertStringContainsString('RouteHelper::requireAuth()', $route);
            self::assertStringNotContainsString('pgp_public_keyserver', $route);
        }
    }

    public function testAdminPersistsPublicationAsACorePgpDependentFlag(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/routes/admin-routes.php');
        self::assertStringContainsString("array_key_exists('pgp_public_keyserver'", $source);
        self::assertStringContainsString(
            "\$config['features']['pgp_public_keyserver'] = \$pgpEnabled",
            $source
        );
    }

    public function testNavigationAndSettingsDoNotAdvertiseDisabledKeyserver(): void
    {
        $root = dirname(__DIR__, 2);
        $base = (string)file_get_contents($root . '/templates/base.twig');
        $webBase = (string)file_get_contents($root . '/templates/shells/web/base.twig');
        $settings = (string)file_get_contents($root . '/templates/settings.twig');
        $settingsRoute = $this->between(
            $this->webRoutes,
            "SimpleRouter::get('/settings'",
            "SimpleRouter::get('/keyserver'"
        );

        foreach ([$base, $webBase] as $template) {
            self::assertStringContainsString(
                "bbs_feature_enabled('pgp') and bbs_feature_enabled('pgp_public_keyserver')",
                $template
            );
        }
        self::assertStringContainsString('{% if pgp_public_keyserver_enabled %}', $settings);
        self::assertStringContainsString(
            "'pgp_public_keyserver_enabled' => \\BinktermPHP\\BbsConfig::isPgpPublicKeyserverEnabled()",
            $settingsRoute
        );
    }

    private function between(string $source, string $start, string $end): string
    {
        $startPosition = strpos($source, $start);
        self::assertNotFalse($startPosition, 'Missing start marker: ' . $start);
        $endPosition = strpos($source, $end, $startPosition + strlen($start));
        self::assertNotFalse($endPosition, 'Missing end marker: ' . $end);

        return substr($source, $startPosition, $endPosition - $startPosition);
    }
}
