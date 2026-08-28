<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use BinktermPHP\ExperienceParticipation;
use BinktermPHP\ExperiencePresentation;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class ExperienceLobbyTemplateTest extends TestCase
{
    private function renderLobby(
        int $sessionCount,
        ?int $maxSessions,
        bool $launchEnabled = true,
        ?string $screenshotUrl = null,
        string $name = 'Usurper Reborn',
        bool $multiplayer = true,
        string $iconUrl = '/door-assets/usurper/icon',
        string $launchUrl = '/games/nativedoors/usurper',
        string $launchType = 'native',
        string $launchId = 'usurper',
        array $players = [],
        ?int $currentUserId = null,
        bool $participantMessaging = false,
        int $creditCost = 0,
        ?array $presentationOverride = null,
        array $experienceOverride = []
    ): string {
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates')
        );

        // The production Template environment provides t(). These tests
        // exercise Experience lobby behavior, so translation itself is
        // intentionally stubbed with a deterministic value.
        $twig->addFunction(new TwigFunction(
            't',
            static fn(string $key, array $params = [], ...$args): string => $key
        ));

        // base.twig also relies on this production Twig helper. The lobby
        // behavior under test does not depend on feature flags, so keep the
        // synthetic test environment deterministic.
        $twig->addFunction(new TwigFunction(
            'bbs_feature_enabled',
            static fn(string $feature): bool => false
        ));

        $experience = [
            'id' => 'usurper',
            'name' => $name,
            'description' => 'Usurper Reborn fantasy RPG BBS door.',
            'icon' => 'fas fa-dungeon',
            'backend' => [
                'type' => $launchType,
                'id' => $launchId,
            ],
            'presentation' => [
                'icon_url' => $iconUrl,
                'screenshot_url' => $screenshotUrl,
            ],
            'capabilities' => [
                'multiplayer' => $multiplayer,
            ],
            'participant_actions' => [
                'profile' => true,
                'message' => $participantMessaging,
            ],
            'capacity' => [
                'max_sessions' => $maxSessions,
            ],
            'surfaces' => [
                'web' => $launchEnabled ? 'full' : 'unavailable',
                'telnet' => 'planned',
            ],
            'policy' => [
                'enabled' => true,
                'credit_cost' => $creditCost,
            ],
            'actions' => [
                'launch' => $launchEnabled,
                'message_players' => $participantMessaging,
            ],
        ];

        if ($experienceOverride !== []) {
            $experience = array_replace_recursive($experience, $experienceOverride);
        }

        $state = [
            'active' => $sessionCount > 0,
            'session_count' => $sessionCount,
            'player_count' => count($players),
            'players' => $players,
        ];

        $viewerPlayer = $currentUserId !== null
            ? ExperienceParticipation::findViewerPlayer($state, $currentUserId)
            : null;

        // The route constructs ExperiencePresentation and passes it to the
        // template. Build it the same way here so these tests exercise the
        // real read model rather than a hand-rolled shape.
        $presentation = ExperiencePresentation::build(
            $experience,
            'web',
            $state,
            $viewerPlayer
        );

        if ($presentationOverride !== null) {
            $presentation = array_replace_recursive(
                $presentation,
                $presentationOverride
            );
        }

        return $twig->render('experience_lobby.twig', [
            'experience' => $experience,
            'experience_presentation' => $presentation,
            'state' => $state,
            'launch' => $launchEnabled ? [
                'type' => $launchType,
                'id' => $launchId,
                'url' => $launchUrl,
            ] : null,
            'current_user' => $currentUserId !== null ? [
                'user_id' => $currentUserId,
            ] : null,
        ]);
    }

    public function testExperienceDoesNotRenderScreenshotInLobby(): void
    {
        $html = $this->renderLobby(
            0,
            10,
            true,
            '/door-assets/usurper/screenshot'
        );

        self::assertStringNotContainsString(
            'src="/door-assets/usurper/screenshot"',
            $html
        );

        self::assertStringNotContainsString(
            'alt="Usurper Reborn screenshot"',
            $html
        );

        self::assertStringContainsString(
            'src="/door-assets/usurper/icon"',
            $html
        );
    }

    public function testExperienceOmitsScreenshotHeroWhenAbsent(): void
    {
        $html = $this->renderLobby(0, 10);

        self::assertStringNotContainsString(
            'alt="Usurper Reborn screenshot"',
            $html
        );
        self::assertStringNotContainsString(
            'src="/door-assets/usurper/screenshot"',
            $html
        );
        self::assertStringContainsString(
            'src="/door-assets/usurper/icon"',
            $html
        );
    }

    public function testEmptyExperienceUsesSingleOccupancyExpression(): void
    {
        $body = self::renderedBody($this->renderLobby(0, 10));

        // Exactly one occupancy expression. Empty state suppresses the ratio —
        // the invitation copy below already covers "come on in".
        self::assertStringContainsString('id="experience-occupancy-summary"', $body);
        self::assertSame('0 online', self::occupancySummaryText($body));
        self::assertStringContainsString(
            'No one is playing right now. Be the first one in.',
            $body
        );

        // No capacity telemetry / session mechanics / duplicate count sentence.
        self::assertStringNotContainsString('Capacity:', $body);
        self::assertStringNotContainsString('capacity</span>', $body);
        self::assertStringNotContainsString('active session', $body);
        self::assertStringNotContainsString('Waiting for players.', $body);
        self::assertStringNotContainsString('players are in this Experience', $body);
        self::assertStringNotContainsString('player is in this Experience', $body);
        self::assertStringNotContainsString('aria-label="Experience capacity"', $body);
        self::assertStringNotContainsString('class="progress', $body);
    }

    /** @return array<string,array{0:string,1:int,2:?int,3:list<array<string,mixed>>}> */
    public static function occupancyShapeProvider(): array
    {
        $one = [[
            'user_id' => 3, 'username' => 'Skrawl', 'session_id' => 's1',
            'presence' => 'Playing X', 'presence_state' => 'playing',
            'node' => 1, 'started_at' => 0,
        ]];
        $three = [];
        foreach (['Skrawl', 'Bard', 'Rogue'] as $i => $u) {
            $three[] = [
                'user_id' => 10 + $i, 'username' => $u, 'session_id' => 's' . $i,
                'presence' => 'Playing X', 'presence_state' => 'playing',
                'node' => $i + 1, 'started_at' => 0,
            ];
        }

        return [
            'empty capped'       => ['0 online',        0,  10,   []],
            'one of ten'         => ['1 online · 1/10', 1,  10,   $one],
            'three of ten'       => ['3 online · 3/10', 3,  10,   $three],
            'unlimited capacity' => ['3 online',        3,  null, $three],
            'at capacity'        => ['Full · 10/10',    10, 10,   $one],
        ];
    }

    /**
     * @dataProvider occupancyShapeProvider
     * @param list<array<string,mixed>> $players
     */
    public function testOccupancyExpressionShape(
        string $expected,
        int $sessionCount,
        ?int $maxSessions,
        array $players
    ): void {
        $body = self::renderedBody($this->renderLobby(
            $sessionCount,
            $maxSessions,
            true,
            null,
            'Occupancy Probe',
            true,
            '/door-assets/x/icon',
            '/games/nativedoors/x',
            'native',
            'x',
            $players
        ));

        self::assertSame($expected, self::occupancySummaryText($body));
    }

    public function testWebExperienceUsesSameCanonicalLobbyAndLaunchAction(): void
    {
        $html = $this->renderLobby(
            0,
            null,
            true,
            null,
            'Blackjack',
            false,
            '/webdoors/blackjack/icon.svg',
            '/games/blackjack'
        );

        self::assertStringContainsString('Blackjack', $html);
        self::assertStringContainsString('Single Player', $html);
        // Slice 5A: zero-cost Experiences no longer get storefront-style
        // "Free to play" emphasis.
        self::assertStringNotContainsString('Free to play', $html);
        self::assertStringContainsString(
            'src="/webdoors/blackjack/icon.svg"',
            $html
        );
        self::assertStringContainsString(
            'href="/games/blackjack"',
            $html
        );
        self::assertSame('Enter', self::launchLabel($html));
        self::assertStringNotContainsString('Play Blackjack', $html);

        self::assertStringNotContainsString('Capacity:', $html);
        self::assertStringNotContainsString(
            'aria-label="Experience capacity"',
            $html
        );
    }

    public function testJsdosExperienceUsesSameCanonicalLobbyAndLaunchAction(): void
    {
        $html = $this->renderLobby(
            0,
            null,
            true,
            null,
            'Doom',
            false,
            '/jsdos-doors/doomsw/icon-v2.png',
            '/games/jsdos/doomsw',
            'jsdos',
            'doomsw'
        );

        self::assertStringContainsString('Doom', $html);
        self::assertStringContainsString('Single Player', $html);
        // Slice 5A: zero-cost Experiences no longer get storefront-style
        // "Free to play" emphasis.
        self::assertStringNotContainsString('Free to play', $html);
        self::assertStringContainsString(
            'src="/jsdos-doors/doomsw/icon-v2.png"',
            $html
        );
        self::assertStringContainsString(
            'href="/games/jsdos/doomsw"',
            $html
        );
        self::assertSame('Enter', self::launchLabel($html));
        self::assertStringNotContainsString('Play Doom', $html);

        self::assertStringNotContainsString('Capacity:', $html);
        self::assertStringNotContainsString(
            'aria-label="Experience capacity"',
            $html
        );
    }

    public function testParticipantRowsNeverExposeNodeNumbers(): void
    {
        // A native door supplies a node number in runtime state; it must never
        // reach an ordinary Crossroads caller.
        $nativeHtml = $this->renderLobby(
            1,
            10,
            true,
            null,
            'Usurper Reborn',
            true,
            '/door-assets/usurper/icon',
            '/games/nativedoors/usurper',
            'native',
            'usurper',
            [[
                'user_id' => 3,
                'username' => 'Skrawl',
                'session_id' => 'native-usurper-test',
                'presence' => 'Playing Usurper Reborn',
                'presence_state' => 'playing',
                'node' => 3,
                'started_at' => time(),
            ]]
        );

        $nativeBody = self::renderedBody($nativeHtml);

        self::assertStringContainsString('Skrawl', $nativeBody);
        self::assertStringContainsString('Playing Usurper Reborn', $nativeBody);
        self::assertDoesNotMatchRegularExpression('/\bNode\s+\d+\b/', $nativeBody);
        // The JS refresh renderer must not reintroduce a node badge either.
        self::assertStringNotContainsString('Node ${Number(player.node)}', $nativeHtml);
        self::assertStringNotContainsString('player.node', $nativeHtml);
    }

    public function testLivePlayerLinksToCanonicalBinkTermProfile(): void
    {
        $html = $this->renderLobby(
            1,
            10,
            true,
            null,
            'Usurper Reborn',
            true,
            '/door-assets/usurper/icon',
            '/games/nativedoors/usurper',
            'native',
            'usurper',
            [[
                'user_id' => 3,
                'username' => 'Skrawl',
                'session_id' => 'native-usurper-test',
                'presence' => 'Playing Usurper Reborn',
                'node' => 1,
                'started_at' => time(),
            ]]
        );

        self::assertStringContainsString(
            'href="/profile/Skrawl"',
            $html
        );
        self::assertMatchesRegularExpression(
            '/<a[^>]+href="\/profile\/Skrawl"[^>]*>\s*Skrawl\s*<\/a>/',
            $html
        );
    }

    public function testLivePlayerProfileLinkUrlEncodesUsername(): void
    {
        $html = $this->renderLobby(
            1,
            10,
            true,
            null,
            'Usurper Reborn',
            true,
            '/door-assets/usurper/icon',
            '/games/nativedoors/usurper',
            'native',
            'usurper',
            [[
                'user_id' => 3,
                'username' => 'Test User',
                'session_id' => 'native-usurper-test',
                'presence' => 'Playing Usurper Reborn',
                'node' => 1,
                'started_at' => time(),
            ]]
        );

        self::assertStringContainsString(
            'href="/profile/Test%20User"',
            $html
        );
    }

    public function testAvailableExperiencePresentsPlayableLaunch(): void
    {
        $html = $this->renderLobby(0, 10);

        // Normal state: the Enter action is the whole statement — no badge,
        // no "Ready to play." line.
        self::assertSame('Enter', self::launchLabel($html));
        self::assertNull(self::statusMessage($html));
        self::assertStringNotContainsString('Ready to play.', $html);
        self::assertStringNotContainsString('Play Usurper Reborn', $html);
        self::assertStringContainsString(
            'href="/games/nativedoors/usurper"',
            $html
        );
        self::assertStringContainsString(
            'class="btn btn-fidonet"',
            $html
        );
        self::assertStringNotContainsString(
            'aria-disabled="true"',
            $html
        );
    }

    public function testExperienceAtCapacityDisablesLaunch(): void
    {
        $html = $this->renderLobby(10, 10);

        // Exceptional state keeps a concise line; the action reads Full.
        self::assertStringContainsString(
            'No open sessions right now.',
            $html
        );
        self::assertSame('Full', self::launchLabel($html));
        self::assertStringNotContainsString('>At capacity<', $html);
        self::assertStringNotContainsString('At Capacity', $html);
        self::assertStringContainsString(
            'aria-disabled="true"',
            $html
        );
        self::assertStringContainsString(
            'tabindex="-1"',
            $html
        );
        self::assertStringNotContainsString(
            'Play Usurper Reborn',
            $html
        );
    }

    public function testUnavailableLaunchDoesNotRenderPlayAction(): void
    {
        $html = $this->renderLobby(0, 10, false);

        // Exceptional surface state keeps its concise line.
        self::assertStringContainsString(
            'Not available from this surface.',
            $html
        );
        self::assertSame('unavailable', self::statusCode($html));
        self::assertStringNotContainsString(
            'Play Usurper Reborn',
            $html
        );
        self::assertStringNotContainsString(
            'href="/games/nativedoors/usurper"',
            $html
        );
    }

    public function testLivePlayerOffersUniversalParticipantActions(): void
    {
        $html = $this->renderLobby(
            1,
            10,
            true,
            null,
            'Usurper Reborn',
            true,
            '/door-assets/usurper/icon',
            '/games/nativedoors/usurper',
            'native',
            'usurper',
            [[
                'user_id' => 7,
                'username' => 'Bard',
                'session_id' => 'native-usurper-bard',
                'presence' => 'Playing Usurper Reborn',
                'presence_state' => 'playing',
                'node' => 2,
                'started_at' => time(),
            ]],
            3,
            true
        );

        $body = self::renderedBody($html);

        // Identity, human presence text, and Message survive.
        self::assertStringContainsString('href="/profile/Bard"', $body);
        self::assertStringContainsString('Playing Usurper Reborn', $body);
        self::assertStringContainsString('href="/chat?dm_user_id=7"', $body);
        self::assertStringContainsString('Message', $body);

        // No redundant "Playing" badge, no node badge.
        self::assertStringNotContainsString('aria-label="Currently playing"', $body);
        self::assertStringNotContainsString('>Playing</span>', $body);
        self::assertDoesNotMatchRegularExpression('/\bNode\s+\d+\b/', $body);
    }

    public function testExperienceLobbyIncludesParticipantPartial(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertStringContainsString(
            "{% include 'partials/experience_participant.twig' with {",
            $source
        );

        self::assertStringContainsString(
            "participant_actions: experience.participant_actions|default({})",
            $source
        );
        self::assertStringContainsString(
            'id="experience-occupancy-summary"',
            $source
        );

        self::assertStringContainsString(
            'id="experience-participants"',
            $source
        );

        // The retired session-telemetry ids are gone.
        self::assertStringNotContainsString('id="experience-player-summary"', $source);
        self::assertStringNotContainsString('id="experience-session-count"', $source);
        self::assertStringNotContainsString('id="experience-live-status"', $source);
        self::assertStringNotContainsString('id="experience-capacity"', $source);
    }

    public function testExperienceLobbyDefinesLiveStateRefreshContract(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertStringContainsString(
            'const EXPERIENCE_ID = {{ experience.id|json_encode|raw }};',
            $source
        );

        self::assertStringContainsString(
            'async function refreshExperienceState()',
            $source
        );

        self::assertStringContainsString(
            '/api/experiences/${encodeURIComponent(EXPERIENCE_ID)}/state',
            $source
        );
    }

    public function testExperienceLobbyLaunchActionReflectsViewerParticipation(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertStringContainsString(
            'function updateExperienceLiveState(state, viewer = null, presentation = null)',
            $source
        );

        self::assertStringContainsString(
            'const viewerParticipating = !!(viewer && viewer.participating);',
            $source
        );

        self::assertStringContainsString(
            'const disabled = blockedByCapacity || !launchSupported;',
            $source
        );

        // Primary action vocabulary: Full / Return / Enter — no "Play"/"Return to".
        self::assertStringContainsString(
            "const label = blockedByCapacity\n            ? 'Full'\n            : (viewerParticipating ? 'Return' : 'Enter');",
            $source
        );

        self::assertStringNotContainsString('`Return to ${EXPERIENCE_NAME}`', $source);
        self::assertStringNotContainsString('`Play ${EXPERIENCE_NAME}`', $source);

        self::assertStringContainsString(
            "launchButton.dataset.viewerParticipating =",
            $source
        );

        self::assertStringContainsString(
            'id="experience-launch-label"',
            $source
        );

        self::assertStringContainsString(
            "const labelElement = document.getElementById('experience-launch-label');",
            $source
        );

        self::assertStringContainsString(
            'labelElement.textContent = label;',
            $source
        );

        self::assertStringNotContainsString(
            'Array.from(launchButton.childNodes)',
            $source
        );

        // No launch-action icon manipulation remains.
        self::assertStringNotContainsString('fa-sign-in-alt', $source);
        self::assertStringNotContainsString('fa-play', $source);

        self::assertStringContainsString(
            'updateExperienceLiveState(state, viewer, presentation);',
            $source
        );
    }

    public function testExperienceLobbyDefinesParticipantRendererContract(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertStringContainsString(
            'function renderExperienceParticipants(state, actions)',
            $source
        );

        self::assertStringContainsString(
            "document.getElementById('experience-participants')",
            $source
        );

        self::assertStringContainsString(
            'participant_actions',
            $source
        );

        self::assertStringContainsString(
            'window.currentUserId',
            $source
        );

        self::assertStringContainsString(
            '/chat?dm_user_id=',
            $source
        );

        // The renderer no longer emits a "Playing" badge or a node badge.
        self::assertStringNotContainsString("player.presence_state === 'playing'", $source);
        self::assertStringNotContainsString('aria-label="Currently playing"', $source);
        self::assertStringNotContainsString('player.node', $source);
    }

    public function testExperienceLobbyLiveStateUpdaterRendersOneOccupancyLine(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertStringContainsString(
            'function updateExperienceLiveState(state, viewer = null, presentation = null)',
            $source
        );

        // The single occupancy expression, keyed on the canonical contract.
        self::assertStringContainsString('function experienceOccupancyText(state, presentation)', $source);
        self::assertStringContainsString(
            "document.getElementById('experience-occupancy-summary')",
            $source
        );
        self::assertStringContainsString(
            'occupancySummary.textContent = experienceOccupancyText(state, presentation);',
            $source
        );
        // Empty state suppresses the capacity ratio in the refresh path too.
        self::assertStringContainsString(
            'if (maxSessions > 0 && (atCapacity || online > 0)) {',
            $source
        );
        self::assertStringContainsString(
            "document.getElementById('experience-availability-message')",
            $source
        );

        // The retired telemetry DOM handling is gone — the refresh cannot
        // rebuild the Live badge / session count / capacity gauge / progress bar.
        self::assertStringNotContainsString("getElementById('experience-live-status')", $source);
        self::assertStringNotContainsString("getElementById('experience-session-count')", $source);
        self::assertStringNotContainsString("getElementById('experience-capacity')", $source);
        self::assertStringNotContainsString('experience-occupancy-container', $source);
        self::assertStringNotContainsString('liveOccupancy', $source);
        self::assertStringNotContainsString("getElementById('experience-availability-badge')", $source);
        self::assertStringNotContainsString("'progress mb-3'", $source);
        self::assertStringNotContainsString('class="progress', $source);
        self::assertStringNotContainsString('active session', $source);
    }

    public function testParticipantPartialUsesNormalizedParticipantActions(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/partials/experience_participant.twig'
        );

        self::assertStringContainsString(
            'participant_actions.profile',
            $source
        );

        self::assertStringContainsString(
            'participant_actions.message',
            $source
        );

        self::assertStringNotContainsString(
            'experience.actions.message_players',
            $source
        );
    }

    public function testParticipantPartialShowsHumanPresenceWithoutBadgesOrNodes(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/partials/experience_participant.twig'
        );

        // Human presence text is kept, with a readable fallback.
        self::assertStringContainsString('{{ player.presence }}', $source);
        self::assertStringContainsString('Playing {{ experience.name }}', $source);

        // No "Playing" status badge, no node badge, no presence_state gate.
        self::assertStringNotContainsString('presence_state', $source);
        self::assertStringNotContainsString('bg-success', $source);
        self::assertStringNotContainsString('Currently playing', $source);
        self::assertStringNotContainsString('player.node', $source);
        self::assertStringNotContainsString('Node ', $source);
        self::assertStringNotContainsString(
            "presence == 'Playing'",
            $source
        );
    }

    public function testParticipantPartialProvidesCanonicalChatDmLink(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/partials/experience_participant.twig'
        );

        self::assertStringContainsString(
            'href="/chat?dm_user_id={{ player.user_id }}"',
            $source
        );

        self::assertStringContainsString(
            'player.user_id != (current_user.user_id ?? current_user.id)',
            $source
        );

        self::assertStringContainsString('Message', $source);
    }

    public function testChatSupportsCanonicalDmDeepLink(): void
    {
        $source = file_get_contents(__DIR__ . '/../../public_html/js/chat-page.js');

        self::assertStringContainsString(
            "params.get('dm_user_id')",
            $source
        );
        self::assertStringContainsString(
            "setActiveThread({ type: 'dm', id: deepLinkedDmUserId })",
            $source
        );
        self::assertStringContainsString(
            'userId === currentUserId',
            $source
        );
    }


    public function testExperienceLobbyDefinesNormalizedEndParticipationControl(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertStringContainsString(
            'id="experience-end-button"',
            $source
        );

        self::assertStringContainsString(
            'End Participation',
            $source
        );

        self::assertStringContainsString(
            'viewerActions.end === true',
            $source
        );

        self::assertStringContainsString(
            "endButton.classList.toggle('d-none', !canEnd)",
            $source
        );
    }

    public function testExperienceLobbyEndsParticipationThroughNormalizedApi(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertStringContainsString(
            'async function endExperienceParticipation()',
            $source
        );

        self::assertStringContainsString(
            '/api/experiences/${encodeURIComponent(EXPERIENCE_ID)}/end',
            $source
        );

        self::assertStringContainsString(
            "method: 'POST'",
            $source
        );

        self::assertStringContainsString(
            'const payload = await refreshExperienceState();',
            $source
        );

        self::assertStringContainsString(
            'applyExperienceState(payload);',
            $source
        );
    }

    public function testExperienceLobbyDoesNotExposeBackendSessionTermination(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertStringNotContainsString(
            '/api/door/end',
            $source
        );

        self::assertStringNotContainsString(
            '/api/jsdoor/session/',
            $source
        );

        self::assertStringNotContainsString(
            'webdoor_sessions',
            $source
        );
    }

    public function testEndParticipationSendsCsrfHeader(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertIsString($source);

        self::assertStringContainsString(
            "meta[name=\"csrf-token\"]",
            $source
        );

        self::assertStringContainsString(
            "'X-CSRF-Token': csrfToken",
            $source
        );

        self::assertStringContainsString(
            "`/api/experiences/\${encodeURIComponent(EXPERIENCE_ID)}/end`",
            $source
        );
    }


    public function testExperienceLobbyDefinesRecentActivitySurface(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertIsString($source);

        self::assertStringContainsString(
            'id="experience-recent-activity"',
            $source
        );

        self::assertStringContainsString(
            'Recent Activity',
            $source
        );

        self::assertStringContainsString(
            'function renderExperienceActivity(activity = [])',
            $source
        );
    }

    public function testExperienceLobbyConsumesNormalizedRecentActivity(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertIsString($source);

        self::assertStringContainsString(
            'const recentActivity = payload.recent_activity || [];',
            $source
        );

        self::assertStringContainsString(
            'renderExperienceActivity(recentActivity);',
            $source
        );

        self::assertStringContainsString(
            'activity.slice(0, 5).map(entry => {',
            $source
        );

        self::assertStringContainsString(
            'entry.username || \'Unknown user\'',
            $source
        );

        self::assertStringContainsString(
            'entry.occurred_at || \'\'',
            $source
        );
    }


    public function testExperienceLobbyDistinguishesFirstPlayActivity(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertIsString($source);

        self::assertStringContainsString(
            "entry.type === 'first_play'",
            $source
        );

        self::assertStringContainsString(
            "'first recorded play'",
            $source
        );

        self::assertStringNotContainsString(
            "'played for the first time'",
            $source
        );

        self::assertStringContainsString(
            ": 'played';",
            $source
        );
    }

    public function testExperienceActivityUsesCompactDeterministicTimeFormatting(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'function formatExperienceActivityTime(value, nowValue = new Date())',
            $source
        );
        self::assertStringContainsString("return 'just now';", $source);
        self::assertStringContainsString(
            "minute\${elapsedMinutes === 1 ? '' : 's'} ago",
            $source
        );
        self::assertStringContainsString(
            "hour\${elapsedHours === 1 ? '' : 's'} ago",
            $source
        );
        self::assertStringContainsString("return 'yesterday';", $source);
        self::assertStringContainsString("month: 'short'", $source);
        self::assertStringContainsString("day: 'numeric'", $source);
        self::assertStringContainsString("options.year = 'numeric';", $source);
        self::assertStringContainsString(
            "Number.isNaN(date.getTime()) || Number.isNaN(now.getTime())",
            $source
        );
        self::assertStringNotContainsString('return date.toLocaleString();', $source);
    }

    public function testExperienceActivityRenderingRemainsBoundedAndPrivate(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertIsString($source);
        $start = strpos($source, 'function renderExperienceActivity(activity = [])');
        $end = strpos($source, 'function updateExperienceViewerActions', $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $renderer = substr($source, $start, $end - $start);

        self::assertStringContainsString('activity.slice(0, 5)', $renderer);
        self::assertStringContainsString("container.textContent = 'No recent activity yet.';", $renderer);
        self::assertStringContainsString("const username = escapeHtml(", $renderer);
        self::assertStringContainsString("entry.username || 'Unknown user'", $renderer);
        self::assertStringContainsString("entry.occurred_at || ''", $renderer);
        self::assertStringContainsString("? `<span class=\"text-nowrap\"> · \${escapeHtml(occurredAt)}</span>`", $renderer);
        self::assertStringNotContainsString('/profile/', $renderer);
        self::assertStringNotContainsString('EXPERIENCE_NAME', $renderer);
    }


    public function testExperienceLobbyDefinesConversationSurface(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertIsString($source);

        self::assertStringContainsString(
            'const EXPERIENCE_CAPABILITIES =',
            $source
        );

        self::assertStringContainsString(
            '{% if experience.capabilities.conversation %}',
            $source
        );

        self::assertStringContainsString(
            'id="experience-conversation"',
            $source
        );

        self::assertStringContainsString(
            'id="experience-conversation-messages"',
            $source
        );

        self::assertStringContainsString(
            'Conversation',
            $source
        );
    }


    public function testExperienceLobbyLoadsConversationFromExistingChatApi(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertIsString($source);

        self::assertStringContainsString(
            'function getExperienceConversation()',
            $source
        );

        self::assertStringContainsString(
            "conversation.type !== 'chat_room'",
            $source
        );

        self::assertStringContainsString(
            'function refreshExperienceConversation()',
            $source
        );

        self::assertStringContainsString(
            '`/api/chat/messages?room_id=${encodeURIComponent(conversation.room_id)}&limit=10`',
            $source
        );

        self::assertStringContainsString(
            'const messages = payload.messages || [];',
            $source
        );

        self::assertStringContainsString(
            'experienceConversationMessages = messages.slice(-10);',
            $source
        );

        self::assertStringContainsString(
            'renderExperienceConversation(experienceConversationMessages);',
            $source
        );

        self::assertStringContainsString(
            'experienceConversationCursor = id;',
            $source
        );
    }

    public function testExperienceLobbyRendersConversationMessages(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertIsString($source);

        self::assertStringContainsString(
            'function renderExperienceConversation(messages = [])',
            $source
        );

        self::assertStringContainsString(
            "message.from_username || 'Unknown user'",
            $source
        );

        self::assertStringContainsString(
            'message.markup_html',
            $source
        );

        self::assertStringContainsString(
            "container.textContent = 'No conversation yet.';",
            $source
        );
    }


    public function testConversationHistoryIsNotPolledAsRoomEntry(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertIsString($source);

        self::assertSame(
            2,
            substr_count(
                $source,
                'refreshExperienceConversation()'
            )
        );

        self::assertStringContainsString(
            'initializeExperienceConversation()' . PHP_EOL
                . '    .catch(() => {',
            $source
        );

        self::assertStringContainsString(
            "fetch('/api/chat/cursor')",
            $source
        );
    }

    public function testExperienceLobbyUsesCompactExperienceLayout(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertStringContainsString(
            'style="width: 120px; height: 120px; object-fit: cover;"',
            $source
        );

        // The positive status badge is gone; the availability line stays, once.
        self::assertStringNotContainsString('id="experience-availability-badge"', $source);
        self::assertStringContainsString(
            'id="experience-availability-message"',
            $source
        );

        self::assertStringNotContainsString(
            '<i class="fas fa-circle-info me-1" aria-hidden="true"></i>',
            $source
        );

        self::assertSame(
            0,
            substr_count($source, 'id="experience-availability-badge"')
        );

        self::assertSame(
            1,
            substr_count($source, 'id="experience-availability-message"')
        );

        self::assertSame(
            1,
            substr_count($source, 'id="experience-occupancy-summary"')
        );
    }




    public function testExperienceConversationProvidesMessageComposer(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertIsString($source);

        self::assertStringContainsString(
            'id="experience-conversation-input"',
            $source
        );

        self::assertStringContainsString(
            'maxlength="1000"',
            $source
        );

        self::assertStringContainsString(
            'id="experience-conversation-send"',
            $source
        );

        self::assertStringContainsString(
            'id="experience-conversation-error"',
            $source
        );
    }

    public function testExperienceConversationUsesExistingChatSendApi(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertIsString($source);

        self::assertStringContainsString(
            "async function sendExperienceConversationMessage()",
            $source
        );

        self::assertStringContainsString(
            "fetch('/api/chat/send'",
            $source
        );

        self::assertStringContainsString(
            "'X-CSRF-Token': csrfToken",
            $source
        );

        self::assertStringContainsString(
            'room_id: conversation.room_id',
            $source
        );

        self::assertStringContainsString(
            'appendExperienceConversationMessage(',
            $source
        );
    }

    public function testExperienceConversationCachesMessagesForPresenceRerender(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertIsString($source);

        self::assertStringContainsString(
            'let experienceConversationMessages = [];',
            $source
        );

        self::assertStringContainsString(
            'experienceConversationMessages = messages.slice(-10);',
            $source
        );

        self::assertStringContainsString(
            'renderExperienceConversation(experienceConversationMessages);',
            $source
        );
    }

    public function testExperienceConversationCachesNewMessages(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertIsString($source);

        self::assertStringContainsString(
            'experienceConversationMessages.push(message);',
            $source
        );

        self::assertStringContainsString(
            'experienceConversationMessages.push(' . PHP_EOL
                . '                result.local_message',
            $source
        );
    }

    public function testExperienceStateRerendersConversationAfterPresenceUpdate(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertIsString($source);

        $applyStart = strpos(
            $source,
            'function applyExperienceState(payload)'
        );

        self::assertNotFalse($applyStart);

        $applyEnd = strpos(
            $source,
            "\n}\n",
            $applyStart
        );

        self::assertNotFalse($applyEnd);

        $applyFunction = substr(
            $source,
            $applyStart,
            $applyEnd - $applyStart
        );

        self::assertStringContainsString(
            'updateExperiencePlayerSummary(state);',
            $applyFunction
        );

        self::assertStringContainsString(
            'renderExperienceConversation(experienceConversationMessages);',
            $applyFunction
        );
    }

    public function testExperienceConversationDoesNotReloadHistoryAfterSend(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertIsString($source);

        $sendStart = strpos(
            $source,
            'async function sendExperienceConversationMessage()'
        );

        $initializeStart = strpos(
            $source,
            'async function initializeExperienceConversation()'
        );

        self::assertNotFalse($sendStart);
        self::assertNotFalse($initializeStart);

        $sendFunction = substr(
            $source,
            $sendStart,
            $initializeStart - $sendStart
        );

        self::assertStringNotContainsString(
            'refreshExperienceConversation()',
            $sendFunction
        );

        self::assertStringContainsString(
            'result.local_message',
            $sendFunction
        );
    }


    public function testConversationIdentityLinksProfilesAndShowsPlayingState(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertIsString($source);

        self::assertStringContainsString(
            'let experienceCurrentPlayers = [];',
            $source
        );

        self::assertStringContainsString(
            'experienceCurrentPlayers = Array.isArray(state.players)',
            $source
        );

        self::assertStringContainsString(
            'function renderExperienceConversationIdentity(message)',
            $source
        );

        self::assertStringContainsString(
            '`<a href="/profile/${encodeURIComponent(username)}"',
            $source
        );

        self::assertStringContainsString(
            "badge bg-success ms-2\">Playing</span>",
            $source
        );

        self::assertSame(
            2,
            substr_count(
                $source,
                'renderExperienceConversationIdentity(message);'
            )
        );
    }

    /**
     * Server-rendered body with <script> blocks removed, so inert JavaScript
     * fallback strings (guarded dead code when their element is absent) do not
     * create false positives for "is this section rendered".
     */
    private static function renderedBody(string $html): string
    {
        return (string)preg_replace(
            '#<script\b[^>]*>.*?</script>#is',
            '',
            $html
        );
    }

    /** Trimmed text content of the single Live Players occupancy expression. */
    private static function occupancySummaryText(string $html): ?string
    {
        if (
            preg_match(
                '/<div id="experience-occupancy-summary"[^>]*>(.*?)<\/div>/s',
                $html,
                $m
            ) !== 1
        ) {
            return null;
        }

        return trim(preg_replace('/\s+/', ' ', $m[1]));
    }

    // ---- Slice 5A: single-player vs multiplayer lobby normalization ----

    public function testSinglePlayerLobbyOmitsMultiplayerRosterSection(): void
    {
        $html = $this->renderLobby(
            2,
            8,
            true,
            null,
            'Solo Quest',
            false
        );

        $body = self::renderedBody($html);

        self::assertStringNotContainsString('Live Players', $body);
        self::assertStringNotContainsString('id="experience-participants"', $body);
        self::assertStringNotContainsString('id="experience-occupancy-summary"', $body);
        self::assertStringNotContainsString('id="experience-session-count"', $body);
        self::assertStringNotContainsString('id="experience-live-status"', $body);
        self::assertStringNotContainsString('id="experience-occupancy"', $body);
        self::assertStringNotContainsString(
            'aria-label="Experience capacity"',
            $body
        );
        self::assertStringNotContainsString(
            'No one is playing right now. Be the first one in.',
            $body
        );

        // Identity and the non-multiplayer surfaces remain intact.
        self::assertStringContainsString('Solo Quest', $body);
        self::assertStringContainsString('Recent Activity', $body);
        self::assertStringContainsString('Single Player', $body);
    }

    public function testMultiplayerLobbyRetainsRosterSection(): void
    {
        $html = $this->renderLobby(
            2,
            8,
            true,
            null,
            'Guild Hall',
            true,
            '/door-assets/guild/icon',
            '/games/nativedoors/guild',
            'native',
            'guild',
            [[
                'user_id' => 11,
                'username' => 'Runner',
                'session_id' => 'guild-1',
                'presence' => 'Playing Guild Hall',
                'presence_state' => 'playing',
                'node' => 2,
                'started_at' => time(),
            ]]
        );

        $body = self::renderedBody($html);

        self::assertStringContainsString('Live Players', $body);
        self::assertStringContainsString('id="experience-participants"', $body);
        self::assertStringContainsString('Runner', $body);
        self::assertStringContainsString('Playing Guild Hall', $body);
        // One occupancy expression: 1 distinct player, 2 sessions, cap 8.
        self::assertSame('1 online · 2/8', self::occupancySummaryText($body));
        self::assertStringNotContainsString('id="experience-session-count"', $body);
        self::assertStringNotContainsString('active session', $body);
        self::assertStringNotContainsString('capacity</span>', $body);
        self::assertDoesNotMatchRegularExpression('/\bNode\s+\d+\b/', $body);
    }

    public function testSinglePlayerLobbyStillRendersConfiguredConversation(): void
    {
        $html = $this->renderLobby(
            0,
            null,
            true,
            null,
            'Chatty Solo',
            false,
            experienceOverride: [
                'capabilities' => [
                    'conversation' => ['type' => 'chat_room', 'room_id' => 5],
                ],
            ]
        );

        $body = self::renderedBody($html);

        self::assertStringContainsString('id="experience-conversation"', $body);
        self::assertStringContainsString('id="experience-conversation-input"', $body);
        self::assertStringNotContainsString('Live Players', $body);
    }

    public function testZeroCreditCostDoesNotRenderFreeToPlayEmphasis(): void
    {
        $html = $this->renderLobby(0, null, true, null, 'Free Door', false);

        self::assertStringNotContainsString('Free to play', $html);
        self::assertStringNotContainsString('fa-ticket-alt', $html);
        self::assertStringNotContainsString('fa-coins', $html);
    }

    public function testNonzeroCreditCostRendersConciseCostIndicator(): void
    {
        $plural = self::renderedBody(
            $this->renderLobby(0, null, true, null, 'Paid Door', false, creditCost: 5)
        );
        self::assertStringContainsString('5 credits', $plural);
        self::assertStringContainsString('fa-coins', $plural);

        $singular = self::renderedBody(
            $this->renderLobby(0, null, true, null, 'Paid Door', false, creditCost: 1)
        );
        self::assertStringContainsString('1 credit', $singular);
        self::assertStringNotContainsString('1 credits', $singular);
    }

    public function testLobbyIdentityAndBadgesSourcedFromPresentationModel(): void
    {
        // Raw catalog values deliberately disagree with the normalized
        // presentation model. The lobby must render the presentation values
        // for the fields converted in this slice.
        $html = $this->renderLobby(
            0,
            null,
            true,
            null,
            'Raw Catalog Name',
            false,
            '/raw/icon.png',
            '/games/demo',
            'web',
            'demo',
            presentationOverride: [
                'name' => 'Presented Name',
                'description' => 'Presented description.',
                'presentation' => ['icon_url' => '/presented/icon.png'],
                'capabilities' => ['multiplayer' => true],
                'cost' => ['credits' => 7, 'free' => false],
            ]
        );

        $body = self::renderedBody($html);

        self::assertStringContainsString(
            '<h1 class="h3 mb-1">Presented Name</h1>',
            $body
        );
        self::assertStringContainsString('Presented description.', $body);
        self::assertStringContainsString('src="/presented/icon.png"', $body);
        self::assertStringNotContainsString('src="/raw/icon.png"', $body);
        // Multiplayer badge + roster follow the presentation flag, not the
        // raw catalog capability.
        self::assertStringContainsString('Multiplayer', $body);
        self::assertStringContainsString('Live Players', $body);
        // Cost indicator follows the normalized presentation cost.
        self::assertStringContainsString('7 credits', $body);
    }

    public function testLobbyHeaderStacksArtworkAndIdentityOnNarrowViewports(): void
    {
        $body = self::renderedBody(
            $this->renderLobby(
                0,
                null,
                true,
                null,
                'An Unusually Long Experience Name That Needs Room',
                false
            )
        );

        // Header container: stacked (flex-column) on phones, side-by-side
        // (flex-sm-row) from the Bootstrap `sm` breakpoint up.
        self::assertMatchesRegularExpression(
            '/<div class="[^"]*\bd-flex\b[^"]*\bflex-column\b[^"]*\bflex-sm-row\b[^"]*mb-3">\s*<img/s',
            $body
        );

        // Artwork keeps its desktop dimensions; the fix does not shrink it.
        self::assertStringContainsString(
            'style="width: 120px; height: 120px; object-fit: cover;"',
            $body
        );

        // DOM order is the desired mobile hierarchy:
        // artwork -> name -> author/version -> description -> badges.
        $imgPos = strpos($body, '<img');
        $namePos = strpos($body, '<h1 class="h3 mb-1">');
        $descPos = strpos($body, 'Usurper Reborn fantasy RPG BBS door.');
        $badgePos = strpos($body, 'Single Player');
        self::assertNotFalse($imgPos);
        self::assertNotFalse($namePos);
        self::assertNotFalse($descPos);
        self::assertNotFalse($badgePos);
        self::assertLessThan($namePos, $imgPos);
        self::assertLessThan($descPos, $namePos);
        self::assertLessThan($badgePos, $descPos);
    }

    // ---- Slice 5B: server-correct initial Play / Return / End ----

    /** @return array<string,mixed> A live participant record for state.players[]. */
    private static function activePlayer(int $userId): array
    {
        return [
            'user_id' => $userId,
            'username' => 'Viewer',
            'session_id' => 'session-' . $userId,
            'presence' => 'Playing Usurper Reborn',
            'presence_state' => 'playing',
            'node' => 1,
            'started_at' => time(),
        ];
    }

    public function testParticipatingViewerInitialRenderShowsReturn(): void
    {
        $html = $this->renderLobby(
            1,
            10,
            true,
            null,
            'Usurper Reborn',
            true,
            '/door-assets/usurper/icon',
            '/games/nativedoors/usurper',
            'native',
            'usurper',
            [self::activePlayer(42)],
            42
        );

        self::assertSame('Return', self::launchLabel($html));
        self::assertStringNotContainsString('Return to Usurper Reborn', $html);
        self::assertStringNotContainsString('fa-sign-in-alt', $html);
        self::assertStringContainsString(
            'data-viewer-participating="true"',
            $html
        );
        self::assertStringNotContainsString('Play Usurper Reborn', $html);
    }

    public function testParticipatingViewerInitialRenderShowsEnabledEndControl(): void
    {
        $html = $this->renderLobby(
            1,
            10,
            true,
            null,
            'Usurper Reborn',
            true,
            '/door-assets/usurper/icon',
            '/games/nativedoors/usurper',
            'native',
            'usurper',
            [self::activePlayer(42)],
            42
        );

        // Isolate the End button element.
        self::assertSame(
            1,
            preg_match(
                '/<button\s+id="experience-end-button"([^>]*)>/s',
                $html,
                $m
            )
        );
        self::assertStringNotContainsString('d-none', $m[1]);
        self::assertStringNotContainsString('disabled', $m[1]);
    }

    public function testParticipatingViewerAtCapacityCanStillReturn(): void
    {
        $html = $this->renderLobby(
            10,
            10,
            true,
            null,
            'Usurper Reborn',
            true,
            '/door-assets/usurper/icon',
            '/games/nativedoors/usurper',
            'native',
            'usurper',
            [self::activePlayer(42)],
            42
        );

        // Isolate the launch anchor; it must not be disabled for a participant.
        self::assertSame(
            1,
            preg_match(
                '/<a\s+id="experience-launch-button"([^>]*)>/s',
                $html,
                $m
            )
        );
        self::assertStringNotContainsString('aria-disabled="true"', $m[1]);
        self::assertStringNotContainsString('tabindex="-1"', $m[1]);
        self::assertDoesNotMatchRegularExpression(
            '/class="[^"]*\bdisabled\b[^"]*"/',
            $m[1]
        );
        self::assertStringContainsString(
            'data-viewer-participating="true"',
            $html
        );

        // The primary-action label is Return, not the capacity fallback.
        self::assertSame('Return', self::launchLabel($html));
    }

    public function testNonParticipantInitialRenderShowsEnterAndHiddenEnd(): void
    {
        $html = $this->renderLobby(0, 10);

        self::assertSame('Enter', self::launchLabel($html));
        self::assertStringContainsString(
            'data-viewer-participating="false"',
            $html
        );
        self::assertStringNotContainsString('Play Usurper Reborn', $html);
        self::assertStringNotContainsString('Return to Usurper Reborn', $html);

        self::assertSame(
            1,
            preg_match(
                '/<button\s+id="experience-end-button"([^>]*)>/s',
                $html,
                $m
            )
        );
        self::assertStringContainsString('d-none', $m[1]);
        self::assertStringContainsString('disabled', $m[1]);
    }

    public function testNonParticipantAtCapacityStillBlocksLaunch(): void
    {
        $html = $this->renderLobby(10, 10);

        self::assertSame('Full', self::launchLabel($html));
        self::assertStringNotContainsString('At Capacity', $html);
        self::assertStringNotContainsString('Play Usurper Reborn', $html);
        self::assertStringNotContainsString('Return to Usurper Reborn', $html);

        self::assertSame(
            1,
            preg_match(
                '/<a\s+id="experience-launch-button"([^>]*)>/s',
                $html,
                $m
            )
        );
        self::assertStringContainsString('aria-disabled="true"', $m[1]);
        self::assertStringContainsString('tabindex="-1"', $m[1]);
        self::assertMatchesRegularExpression(
            '/class="[^"]*\bdisabled\b[^"]*"/',
            $m[1]
        );
    }

    // ---- Slice 5C: normalized capacity / status wording ----

    /**
     * Trimmed text of the availability line — null when it is empty (normal
     * states leave it blank/hidden; only exceptional states carry a line).
     */
    private static function statusMessage(string $html): ?string
    {
        if (
            preg_match(
                '/<div id="experience-availability-message"[^>]*>(.*?)<\/div>/s',
                $html,
                $m
            ) !== 1
        ) {
            return null;
        }

        $text = trim($m[1]);

        return $text === '' ? null : $text;
    }

    /** The data-status-code carried on the availability line for the JS refresh. */
    private static function statusCode(string $html): ?string
    {
        if (
            preg_match(
                '/id="experience-availability-message"[^>]*\bdata-status-code="([^"]*)"/s',
                $html,
                $m
            ) !== 1
        ) {
            return null;
        }

        return $m[1] === '' ? null : $m[1];
    }

    /** Trimmed text of the primary launch action label, or null if absent. */
    private static function launchLabel(string $html): ?string
    {
        if (
            preg_match(
                '/<span id="experience-launch-label">(.*?)<\/span>/s',
                $html,
                $m
            ) !== 1
        ) {
            return null;
        }

        return trim($m[1]);
    }

    public function testParticipantAtCapacityStaysSilentAndKeepsReturn(): void
    {
        $html = $this->renderLobby(
            10,
            10,
            true,
            null,
            'Usurper Reborn',
            true,
            '/door-assets/usurper/icon',
            '/games/nativedoors/usurper',
            'native',
            'usurper',
            [self::activePlayer(42)],
            42
        );

        // Participating is a normal state: no positive badge, no line.
        self::assertNull(self::statusMessage($html));
        self::assertSame('participating', self::statusCode($html));
        self::assertStringNotContainsString('>Active<', $html);
        self::assertStringNotContainsString('You have an active session.', $html);

        // Return remains enabled for the participant.
        self::assertSame('Return', self::launchLabel($html));
        self::assertSame(
            1,
            preg_match(
                '/<a\s+id="experience-launch-button"([^>]*)>/s',
                $html,
                $m
            )
        );
        self::assertStringNotContainsString('aria-disabled="true"', $m[1]);
        self::assertStringNotContainsString('tabindex="-1"', $m[1]);
        self::assertDoesNotMatchRegularExpression(
            '/class="[^"]*\bdisabled\b[^"]*"/',
            $m[1]
        );
    }

    public function testNonParticipantAtCapacityShowsFullAndBlockedLaunch(): void
    {
        $html = $this->renderLobby(10, 10);

        // Exceptional state keeps a concise line.
        self::assertSame('No open sessions right now.', self::statusMessage($html));
        self::assertSame('at_capacity', self::statusCode($html));
        self::assertStringNotContainsString('>At capacity<', $html);

        self::assertSame('Full', self::launchLabel($html));
        self::assertSame(
            1,
            preg_match(
                '/<a\s+id="experience-launch-button"([^>]*)>/s',
                $html,
                $m
            )
        );
        self::assertStringContainsString('aria-disabled="true"', $m[1]);
    }

    public function testAvailableNonParticipantStaysSilentWithEnterAction(): void
    {
        $html = $this->renderLobby(0, 10);

        self::assertNull(self::statusMessage($html));
        self::assertSame('available', self::statusCode($html));
        self::assertStringNotContainsString('Ready to play.', $html);
        self::assertStringNotContainsString('>Available<', $html);
        self::assertSame('Enter', self::launchLabel($html));
    }

    public function testSinglePlayerAtCapacityIsSemanticallyBlockedThroughLiveContract(): void
    {
        // Single-player DOS/native Experience at max_sessions: the audit found
        // JS could not see max_sessions here (it read #experience-capacity, which
        // is multiplayer-only), so a live refresh wrongly re-enabled the launch.
        // The presentation model now carries the capacity/status, and the JS
        // consumes payload.presentation.* rather than the DOM.
        $html = $this->renderLobby(
            4,
            4,
            true,
            null,
            'Solo Quest',
            false,               // multiplayer = false
            '/door-assets/solo/icon',
            '/games/nativedoors/solo',
            'native',
            'solo',
            [self::activePlayer(1)],
            null                 // viewer is not the participant
        );

        // Initial server render: blocked, and the primary action reads Full.
        self::assertSame('at_capacity', self::statusCode($html));
        self::assertSame('Full', self::launchLabel($html));
        self::assertSame(
            1,
            preg_match(
                '/<a\s+id="experience-launch-button"([^>]*)>/s',
                $html,
                $m
            )
        );
        self::assertStringContainsString('aria-disabled="true"', $m[1]);

        // Live-state JS derives capacity/status from payload.presentation.*,
        // not from whether #experience-capacity happens to exist in the DOM.
        $body = self::renderedBody($html);
        self::assertStringContainsString('presentation.capacity', $html);
        self::assertStringContainsString(
            'presentation.viewer.blocked_by_capacity',
            $html
        );
        self::assertStringContainsString('applyExperienceState', $html);
        self::assertStringContainsString(
            'updateExperienceLiveState(state, viewer, presentation)',
            $html
        );
        // The old DOM-derived capacity/launch policy is gone.
        self::assertStringNotContainsString(
            'sessionCount >= maxSessions',
            $html
        );
        self::assertStringNotContainsString(
            'dataset.launchSupported',
            $html
        );
        self::assertStringNotContainsString(
            'dataset.maxSessions',
            $html
        );
        // #experience-capacity is multiplayer-only; for single-player it is
        // simply absent and the JS no longer depends on it for policy.
        self::assertStringNotContainsString('id="experience-capacity"', $body);
    }

}
