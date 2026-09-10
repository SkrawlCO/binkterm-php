<?php

declare(strict_types=1);

use BinktermPHP\DashboardCardRegistry;
use BinktermPHP\I18n\Translator;
use BinktermPHP\RecentCallers;
use BinktermPHP\Terminal\Navigation\AccessContext;
use BinktermPHP\Terminal\Navigation\NavigationDefinition;
use BinktermPHP\Terminal\Navigation\NavigationPath;
use BinktermPHP\Terminal\Navigation\NavigationRuntime;
use BinktermPHP\Terminal\Navigation\NavigationScreenBuilder;
use BinktermPHP\Terminal\Navigation\NavigationScreenRenderer;
use BinktermPHP\Terminal\Navigation\TerminalActionCatalog;
use BinktermPHP\Tests\Support\TerminalRenderHarness;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

require_once __DIR__ . '/Support/TerminalRenderHarness.php';

final class RecentCallersPresentationTest extends TestCase
{
    private function rows(): array
    {
        return [
            ['username'=>'alice', 'last_caller_visit_at'=>'2026-09-09 12:00:00+00', 'is_online'=>true],
            ['username'=>'bob', 'last_caller_visit_at'=>'2026-09-10 11:48:00+00', 'is_online'=>false],
            ['username'=>'carol', 'last_caller_visit_at'=>'2026-09-10 10:00:00+00', 'is_online'=>false],
            ['username'=>'dave', 'last_caller_visit_at'=>'2026-09-09 11:00:00+00', 'is_online'=>false],
        ];
    }

    public function testHumanizedArrivalAndOnlineAreSeparateAndPayloadIsPublic(): void
    {
        $rows = $this->rows();
        $rows[0]['ip_address'] = '192.0.2.1';
        $rows[0]['session_id'] = 'secret';
        $entries = RecentCallers::present($rows, 'en', strtotime('2026-09-10 12:00:00 UTC'));
        self::assertSame(['Online now', '12m ago', '2h ago', 'Yesterday'], array_column($entries, 'presence'));
        self::assertSame(['username', 'presence', 'is_online'], array_keys($entries[0]));
        self::assertCount(6, RecentCallers::present(array_merge($rows, $rows)));
        self::assertStringNotContainsString('secret', json_encode($entries));
    }

    public function testOrdinaryCallerGetsCardAndEscapedProfilePresentation(): void
    {
        $available = DashboardCardRegistry::getAvailableCards(['is_admin'=>false]);
        self::assertArrayHasKey('recent_callers', $available);
        self::assertArrayNotHasKey('todays_callers', $available);
        $layout = DashboardCardRegistry::mergeLayout(['main'=>[], 'sidebar'=>[], 'hidden'=>[]], $available);
        self::assertContains('recent_callers', $layout['sidebar']);
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $translator = new Translator();
        $twig->addFunction(new TwigFunction('t', fn($key, $params=[])=>$translator->translate($key, $params, 'en', ['common'])));
        $rows = $this->rows();
        $rows[0]['username'] = '<caller&name>';
        $html = $twig->render('partials/dashboard_recent_callers.twig', [
            'locale'=>'en', 'current_user'=>['is_admin'=>false],
            'recent_callers'=>RecentCallers::present($rows, 'en', strtotime('2026-09-10 12:00:00 UTC')),
        ]);
        self::assertStringContainsString('Recent Callers', $html);
        self::assertStringContainsString('/profile/%3Ccaller%26name%3E', $html);
        self::assertStringContainsString('&lt;caller&amp;name&gt;', $html);
        self::assertStringContainsString('12m ago', $html);
        self::assertStringNotContainsString('2026-', $html);
        self::assertStringContainsString('No recent callers yet.', $twig->render('partials/dashboard_recent_callers.twig', ['recent_callers'=>[], 'locale'=>'en']));
        $route = file_get_contents(dirname(__DIR__, 2) . '/routes/web-routes.php');
        self::assertStringContainsString("'recent_callers' => \\BinktermPHP\\RecentCallers::present(\$auth->getRecentCallerVisits()", $route);
    }

    public function testTerminalCompositionAndInputFlowStayIntact(): void
    {
        $registry = TerminalActionCatalog::defaultRegistry();
        $definition = NavigationDefinition::fromArray([
            'schema'=>1, 'id'=>'recent-test', 'root'=>'main', 'nodes'=>[
                ['id'=>'main', 'label_fallback'=>'Home', 'items'=>[
                    ['id'=>'mail', 'label_fallback'=>'Netmail', 'hotkey'=>'n', 'action'=>'netmail'],
                    ['id'=>'more', 'label_fallback'=>'More', 'hotkey'=>'m', 'submenu'=>'more'],
                    ['id'=>'quit', 'label_fallback'=>'Quit', 'hotkey'=>'q', 'action'=>'quit'],
                ]],
                ['id'=>'more', 'label_fallback'=>'More', 'items'=>[]],
            ],
        ]);
        $entries = RecentCallers::present($this->rows(), 'en', strtotime('2026-09-10 12:00:00 UTC'));
        $line = RecentCallers::terminalLine($entries);
        self::assertStringContainsString('alice (Online now)', $line);
        self::assertStringContainsString('bob (12m ago)', $line);
        self::assertStringNotContainsString('carol', $line);
        $builder = new NavigationScreenBuilder($registry, fn($key,$fallback,$locale)=>$fallback, null, fn()=>$line);
        $access = new AccessContext(true, false, false, fn()=>true, fn()=>true);
        $path = NavigationPath::root('main', 'Home');
        $screen = $builder->build($definition, $access, $path);
        self::assertSame($line, $screen->ambient);
        self::assertNull($builder->build($definition, $access, $path->push('more','More'))->ambient);
        $guest = new AccessContext(false, false, true, fn()=>true, fn()=>true);
        self::assertNull($builder->build($definition, $guest, $path)->ambient);
        $renderer = new NavigationScreenRenderer();
        foreach (['utf8','cp437','ascii'] as $charset) {
            $h = TerminalRenderHarness::at(80,24)->charset($charset)->mono();
            $lines = $renderer->composeLines($h->context(), $screen, 80,24);
            self::assertLessThanOrEqual(24, count($lines));
            self::assertStringContainsString('Recent Callers:', implode("\n",$lines));
            $regions = $renderer->composeRegions($h->context(), $screen, 72,12,72,2);
            self::assertCount(2, $regions['footer']);
            self::assertStringContainsString('Recent Callers:', $regions['footer'][0]);
            self::assertStringContainsString('Q', $regions['footer'][1]);
        }
        $invoked=0;
        $registry->bind('netmail', function() use (&$invoked){$invoked++;});
        $tokens=['CHAR:n','CHAR:q'];
        $reads=0;
        $runtime = new NavigationRuntime($definition,$registry,$builder,$renderer);
        $exit = $runtime->run(function() use (&$tokens,&$reads){$reads++;return [array_shift($tokens),false,false];}, TerminalRenderHarness::at(80,24)->context(), $access, null, 'en', null, null, 3);
        self::assertSame(NavigationRuntime::EXIT_QUIT, $exit);
        self::assertSame(1,$invoked);
        self::assertSame(2,$reads);
    }

    public function testTerminalNamesCannotInjectControlsAndLineIsBounded(): void
    {
        $line = RecentCallers::terminalLine([['username'=>"evil\033\r\n\tname" . str_repeat('x',80), 'presence'=>'12m ago']], 'en', 40);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1f\x7f]/', $line);
        self::assertLessThanOrEqual(40, mb_strlen($line));
        self::assertNull(RecentCallers::terminalLine([]));
    }
}
