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

    public function testEmptyExperienceUsesCompactOccupancyState(): void
    {
        $html = $this->renderLobby(0, 10);

        self::assertStringContainsString('Capacity: 10', $html);
        self::assertStringContainsString('Waiting for players.', $html);
        self::assertStringContainsString('0 / 10 capacity', $html);
        self::assertStringContainsString(
            'No one is playing right now. Be the first one in.',
            $html
        );

        self::assertStringNotContainsString('Quiet', $html);
        self::assertStringNotContainsString('0 active sessions', $html);
        self::assertStringNotContainsString(
            'aria-label="Experience capacity"',
            $html
        );
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
        self::assertStringContainsString('Play Blackjack', $html);

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
        self::assertStringContainsString('Play Doom', $html);

        self::assertStringNotContainsString('Capacity:', $html);
        self::assertStringNotContainsString(
            'aria-label="Experience capacity"',
            $html
        );
    }

    public function testPlayerNodeIsShownOnlyWhenRuntimeProvidesOne(): void
    {
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
                'node' => 3,
                'started_at' => time(),
            ]]
        );

        self::assertStringContainsString('Skrawl', $nativeHtml);
        self::assertMatchesRegularExpression(
            '/<span class="badge bg-secondary-subtle text-dark border">\s*Node 3\s*<\/span>/',
            $nativeHtml
        );

        // Multiplayer so the roster renders; this case verifies node-badge
        // suppression, not the Slice 5A single-player roster gating.
        $webHtml = $this->renderLobby(
            1,
            null,
            true,
            null,
            'Blackjack',
            true,
            '/webdoors/blackjack/icon.svg',
            '/games/blackjack',
            'web',
            'blackjack',
            [[
                'user_id' => 3,
                'username' => 'Skrawl',
                'session_id' => 'webdoor-blackjack-test',
                'presence' => 'Playing Blackjack',
                'node' => null,
                'started_at' => time(),
            ]]
        );

        self::assertStringContainsString('Skrawl', $webHtml);
        self::assertStringContainsString('Playing Blackjack', $webHtml);
        self::assertDoesNotMatchRegularExpression(
            '/<span class="badge bg-secondary-subtle text-dark border">\s*Node \d+\s*<\/span>/',
            $webHtml
        );
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

        self::assertStringContainsString('Ready to launch.', $html);
        self::assertStringContainsString('Available', $html);
        self::assertStringContainsString('Play Usurper Reborn', $html);
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

        self::assertStringContainsString(
            'This Experience is currently at capacity.',
            $html
        );
        self::assertStringContainsString('At capacity', $html);
        self::assertStringContainsString('At Capacity', $html);
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

        self::assertStringContainsString(
            'Launch is not currently available from this surface.',
            $html
        );
        self::assertStringContainsString('Unavailable', $html);
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

        self::assertStringContainsString(
            'href="/profile/Bard"',
            $html
        );

        self::assertStringContainsString(
            '<span class="badge bg-success me-1" aria-label="Currently playing">Playing</span>',
            $html
        );

        self::assertStringContainsString(
            'href="/chat?dm_user_id=7"',
            $html
        );

        self::assertStringContainsString('Message', $html);
        self::assertStringContainsString('Node 2', $html);
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
            'id="experience-player-summary"',
            $source
        );

        self::assertStringContainsString(
            'id="experience-session-count"',
            $source
        );

        self::assertStringContainsString(
            'id="experience-participants"',
            $source
        );

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
            'function updateExperienceLiveState(state, viewer = null)',
            $source
        );

        self::assertStringContainsString(
            'const viewerParticipating = !!(viewer && viewer.participating);',
            $source
        );

        self::assertStringContainsString(
            '(atCapacity && !viewerParticipating) || !launchSupported;',
            $source
        );

        self::assertStringContainsString(
            'const label = viewerParticipating',
            $source
        );

        self::assertStringContainsString(
            '`Return to ${EXPERIENCE_NAME}`',
            $source
        );

        self::assertStringContainsString(
            '`Play ${EXPERIENCE_NAME}`',
            $source
        );

        self::assertStringContainsString(
            "launchButton.dataset.viewerParticipating =",
            $source
        );

        self::assertStringContainsString(
            'id="experience-launch-label"',
            $source
        );

        self::assertStringContainsString(
            "document.getElementById(\n                'experience-launch-label'\n            )",
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

        self::assertStringContainsString(
            'updateExperienceLiveState(state, viewer);',
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
            "player.presence_state === 'playing'",
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
    }

    public function testExperienceLobbyDefinesLiveStateUpdaterContract(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/experience_lobby.twig'
        );

        self::assertStringContainsString(
            'function updateExperienceLiveState(state, viewer = null)',
            $source
        );

        self::assertStringContainsString(
            "document.getElementById('experience-live-status')",
            $source
        );

        self::assertStringContainsString(
            "document.getElementById('experience-session-count')",
            $source
        );

        self::assertStringContainsString(
            "document.getElementById('experience-capacity')",
            $source
        );

        self::assertStringContainsString(
            "document.getElementById(\n        'experience-occupancy-container'\n    )",
            $source
        );

        self::assertStringContainsString(
            "document.getElementById('experience-availability-message')",
            $source
        );

        self::assertStringContainsString(
            "document.getElementById('experience-availability-badge')",
            $source
        );

        self::assertStringContainsString(
            "liveOccupancy.remove()",
            $source
        );

        self::assertStringContainsString(
            "liveOccupancy = document.createElement('div')",
            $source
        );
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

    public function testParticipantPartialUsesNormalizedPresenceState(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../templates/partials/experience_participant.twig'
        );

        self::assertStringContainsString(
            'player.presence_state',
            $source
        );

        self::assertStringContainsString(
            'bg-success',
            $source
        );

        self::assertStringContainsString(
            'playing',
            $source
        );

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
            "'played for the first time'",
            $source
        );
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

        self::assertStringContainsString(
            'id="experience-availability-badge"',
            $source
        );

        self::assertStringContainsString(
            'id="experience-availability-message"',
            $source
        );

        self::assertStringNotContainsString(
            '<i class="fas fa-circle-info me-1" aria-hidden="true"></i>',
            $source
        );

        self::assertSame(
            1,
            substr_count($source, 'id="experience-availability-badge"')
        );

        self::assertSame(
            1,
            substr_count($source, 'id="experience-availability-message"')
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
        self::assertStringContainsString('id="experience-session-count"', $body);
        self::assertStringContainsString('Runner', $body);
        self::assertStringContainsString('/ 8 capacity', $body);
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

        self::assertStringContainsString('Return to Usurper Reborn', $html);
        self::assertStringContainsString('fa-sign-in-alt', $html);
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

        self::assertStringContainsString('Return to Usurper Reborn', $html);

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
        self::assertSame(
            1,
            preg_match(
                '/<span id="experience-launch-label">\s*([^<]+?)\s*<\/span>/s',
                $html,
                $label
            )
        );
        self::assertSame('Return to Usurper Reborn', trim($label[1]));
    }

    public function testNonParticipantInitialRenderShowsPlayAndHiddenEnd(): void
    {
        $html = $this->renderLobby(0, 10);

        self::assertStringContainsString('Play Usurper Reborn', $html);
        self::assertStringContainsString(
            'data-viewer-participating="false"',
            $html
        );
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

        self::assertStringContainsString('At Capacity', $html);
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

}
