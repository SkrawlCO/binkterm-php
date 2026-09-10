<?php

declare(strict_types=1);

use BinktermPHP\DashboardCardRegistry;
use BinktermPHP\I18n\Translator;
use BinktermPHP\Newscan\NewscanArea;
use BinktermPHP\Newscan\NewscanPlan;
use BinktermPHP\Newscan\WebNewscanSummary;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class WebNewscanSummaryTest extends TestCase
{
    private function render(?array $summary, bool $wholeCard = false, string $echoList = 'echomail'): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $translator = new Translator();
        $twig->addFunction(new TwigFunction('t', fn($key, $params = []) => $translator->translate($key, $params, 'en', ['common'])));
        $context = [
            'newscan_summary' => $summary, 'locale' => 'en', 'current_user' => ['is_admin' => false],
            'default_echo_list' => $echoList, 'interests_enabled' => true,
        ];
        if ($wholeCard) {
            $dashboard = file_get_contents(dirname(__DIR__, 2) . '/templates/dashboard.twig');
            preg_match('/<div class="dash-card-wrapper" data-card-id="unread">.*?\{# \/dash-card-wrapper unread #\}/s', $dashboard, $match);
            self::assertNotEmpty($match[0]);
            return $twig->createTemplate($match[0])->render($context);
        }
        return $twig->render('partials/dashboard_newscan.twig', $context);
    }

    public function testProjectionUsesOnlyCanonicalPlanCountsAndStates(): void
    {
        $plan = new NewscanPlan([10, 11], [new NewscanArea(7, 'PRIVATE-TAG', 'test', 'private description', [20, 21, 22])], 4, true);
        $summary = WebNewscanSummary::fromPlan($plan);
        self::assertSame([
            'netmail' => $plan->netmailCount(), 'echomail' => $plan->echomailCount(),
            'areas' => $plan->areaCount(), 'bulletins' => $plan->bulletinUnread,
            'empty' => $plan->isEmpty(), 'truncated' => $plan->truncated,
        ], $summary);
        self::assertSame(['netmail','echomail','areas','bulletins','empty','truncated'], array_keys($summary));
        $serialized = json_encode($summary);
        self::assertStringNotContainsString('PRIVATE', $serialized);
        self::assertStringNotContainsString('messageIds', $serialized);
        self::assertStringNotContainsString('description', $serialized);
    }

    public function testOrdinaryCallerGetsCompactCardWithExistingDestinations(): void
    {
        $cards = DashboardCardRegistry::getAvailableCards(['is_admin' => false]);
        self::assertArrayNotHasKey('newscan', DashboardCardRegistry::getAllCards());
        self::assertTrue($cards['unread']['required']);
        $layout = DashboardCardRegistry::mergeLayout(['main'=>['unread'], 'sidebar'=>[], 'hidden'=>['newscan']], $cards);
        self::assertContains('unread', $layout['main']);
        foreach ($layout as $zone) self::assertNotContains('newscan', $zone);
        $plan = new NewscanPlan([1, 2, 3], [new NewscanArea(8, 'TEST', '', '', [4, 5])], 1);
        $html = $this->render(WebNewscanSummary::fromPlan($plan));
        foreach (['/netmail', '/echomail', '/bulletins?unread=1'] as $url) {
            self::assertStringContainsString('href="' . $url . '"', $html);
        }
        self::assertSame(3, substr_count($html, '<a '));
        self::assertStringContainsString('<strong>3</strong>', $html);
        self::assertStringContainsString('Areas: 1', $html);
        self::assertStringNotContainsString('TEST', $html);
    }

    public function testCaughtUpBulletinsOnlyAndFailureAreDistinct(): void
    {
        $empty = $this->render(WebNewscanSummary::fromPlan(NewscanPlan::empty()));
        self::assertStringContainsString("You're caught up.", html_entity_decode($empty));
        self::assertStringNotContainsString('<a ', $empty);
        $onlyBulletins = $this->render(WebNewscanSummary::fromPlan(new NewscanPlan([], [], 1)));
        self::assertStringContainsString('/bulletins?unread=1', $onlyBulletins);
        self::assertStringNotContainsString('caught up', $onlyBulletins);
        $failed = $this->render(null);
        self::assertStringContainsString('temporarily unavailable', $failed);
        self::assertStringNotContainsString('caught up', $failed);
    }

    public function testMailAndAreasOwnsCanonicalCountsAndPreservesDiscoveryEvenWhenCaughtUp(): void
    {
        $plan = new NewscanPlan([1], [new NewscanArea(1, 'A', '', '', range(1, 45)), new NewscanArea(2, 'B', '', '', range(46, 90))], 1);
        foreach ([$plan, NewscanPlan::empty()] as $state) {
            $html = $this->render(WebNewscanSummary::fromPlan($state), true, 'echolist');
            self::assertSame(1, substr_count($html, 'data-card-id="unread"'));
            self::assertStringNotContainsString('data-card-id="newscan"', $html);
            foreach (['newEchoareaList', 'toggleNewEchoareas', 'newEchoareasLoadMore', 'href="/subscriptions"', 'href="/interests"'] as $control) {
                self::assertStringContainsString($control, $html);
            }
            if ($state->isEmpty()) {
                self::assertStringContainsString("You're caught up.", html_entity_decode($html));
            } else {
                self::assertStringContainsString('<strong>90</strong>', $html);
                self::assertStringContainsString('Areas: 2', $html);
                self::assertStringContainsString('href="/echolist"', $html);
            }
        }
        $dashboard = file_get_contents(dirname(__DIR__, 2) . '/templates/dashboard.twig');
        self::assertSame(1, substr_count($dashboard, "include 'partials/dashboard_newscan.twig'"));
        foreach (['data.total_netmail', 'data.new_echomail', '/api/notify/seen', 'data-card-id="newscan"'] as $legacy) {
            self::assertStringNotContainsString($legacy, $dashboard);
        }
    }

    public function testTruncatedCountsNeverClaimCaughtUpEvenWithNoVisibleMessages(): void
    {
        foreach ([new NewscanPlan(range(1, 300), [], 0, true), new NewscanPlan([], [], 0, true)] as $plan) {
            $html = $this->render(WebNewscanSummary::fromPlan($plan));
            self::assertStringContainsString('Scan limit reached', $html);
            self::assertStringNotContainsString('caught up', $html);
        }
    }

    public function testDashboardWiringUsesAuthenticatedCanonicalPlanWithoutNewReadLogic(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/routes/web-routes.php');
        $start = strpos($source, "SimpleRouter::get('/', function()");
        $end = strpos($source, "SimpleRouter::get('/bulletins'", $start);
        $route = substr($source, $start, $end - $start);
        self::assertLessThan(strpos($route, 'UnifiedNewscanService'), strpos($route, 'if (!$user)'));
        self::assertStringContainsString('UnifiedNewscanService())->plan($user)', $route);
        self::assertStringContainsString('WebNewscanSummary::fromPlan($newscanPlan)', $route);
        self::assertStringNotContainsString("\$availableCards['newscan']", $route);
        preg_match('/\$newscanSummary = null;(.*?)\/\/ Compose the Crossroads/s', $route, $match);
        self::assertNotEmpty($match[1]);
        foreach (['markRead', 'last_read_id', 'message_read_status', 'notify_state', 'user_activity_log', 'is_admin'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $match[1]);
        }
        self::assertStringContainsString("include 'partials/dashboard_newscan.twig'", file_get_contents(dirname(__DIR__, 2) . '/templates/dashboard.twig'));
    }
}
