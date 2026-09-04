<?php

declare(strict_types=1);

use BinktermPHP\ExperienceComposition;
use BinktermPHP\ExperienceLaunch;
use BinktermPHP\CrossroadsShelves;
use PHPUnit\Framework\TestCase;

/**
 * Generic multi-backend Experience seam. Fixtures are plain arrays shaped like
 * GameCatalog normalized rows; no catalog I/O, no manifests, no Chessmata.
 */
final class ExperienceCompositionTest extends TestCase
{
    /**
     * A normalized catalog row as GameCatalog would emit it for one backend.
     *
     * @param array<string,mixed> $grouping optional experience.group metadata
     * @return array<string,mixed>
     */
    private function row(
        string $id,
        string $backendType,
        array $surfaces,
        array $grouping = [],
        string $name = '',
        string $description = '',
        ?string $icon = null,
        string $category = 'game'
    ): array {
        $row = [
            'id' => $id,
            'name' => $name !== '' ? $name : $id,
            'description' => $description,
            'category' => $category,
            'backend' => ['type' => $backendType, 'id' => $id],
            'author' => "author-of-{$id}",
            'version' => "v-{$id}",
            'capabilities' => ['multiplayer' => true, 'participant_messaging' => false],
            'actions' => ['launch' => true, 'message_players' => false],
            'surfaces' => $surfaces,
            'presentation' => ['icon' => $icon, 'icon_url' => "/i/{$id}"],
            'policy' => ['enabled' => true, 'admin_only' => false, 'credit_cost' => 0],
            'source' => ['type' => $backendType, 'manifest' => []],
        ];
        if ($grouping !== []) {
            $row['grouping'] = $grouping;
        }
        return $row;
    }

    // ---- A. LEGACY SINGLE BACKEND ------------------------------------------

    public function testUngroupedInputIsReturnedIdentical(): void
    {
        $input = [
            'lord' => $this->row('lord', 'native', ['web' => 'full', 'telnet' => 'full']),
            'openglad' => $this->row('openglad', 'web', ['web' => 'full', 'telnet' => 'planned']),
        ];

        self::assertSame($input, ExperienceComposition::compose($input));
    }

    public function testGroupedRunDoesNotRewriteUngroupedSiblings(): void
    {
        $lord = $this->row('lord', 'native', ['web' => 'full', 'telnet' => 'full']);
        $input = [
            'lord' => $lord,
            'sg-term' => $this->row('sg-term', 'native', ['web' => 'full', 'telnet' => 'full'],
                ['group' => 'shared-game', 'primary' => false, 'surface' => 'telnet']),
            'sg-web' => $this->row('sg-web', 'web', ['web' => 'full', 'telnet' => 'planned'],
                ['group' => 'shared-game', 'primary' => true, 'surface' => 'web']),
        ];

        $out = ExperienceComposition::compose($input);

        self::assertSame($lord, $out['lord'], 'ungrouped sibling must be byte-identical');
    }

    // ---- B. GROUPED TWO-BACKEND EXPERIENCE -------------------------------

    /** @return array<string,array<string,mixed>> */
    private function sharedGameInput(): array
    {
        return [
            // deliberately: terminal member discovered FIRST
            'sg-term' => $this->row(
                'sg-term', 'native', ['web' => 'full', 'telnet' => 'full'],
                ['group' => 'shared-game', 'primary' => false, 'surface' => 'telnet'],
                'Terminal Name', 'terminal description', 'term-icon'
            ),
            'sg-web' => $this->row(
                'sg-web', 'web', ['web' => 'full', 'telnet' => 'planned'],
                ['group' => 'shared-game', 'primary' => true, 'surface' => 'web'],
                'Shared Game', 'the canonical description', 'web-icon'
            ),
        ];
    }

    public function testTwoBackendGroupCollapsesToOneCanonicalExperience(): void
    {
        $out = ExperienceComposition::compose($this->sharedGameInput());

        self::assertSame(['shared-game'], array_keys($out), 'exactly one normalized Experience');
        self::assertSame('shared-game', $out['shared-game']['id']);
        self::assertSame('full', $out['shared-game']['surfaces']['web']);
        self::assertSame('full', $out['shared-game']['surfaces']['telnet']);
    }

    public function testWebLaunchResolvesToWebMemberAndTelnetToTerminalMember(): void
    {
        $exp = ExperienceComposition::compose($this->sharedGameInput())['shared-game'];

        $web = ExperienceLaunch::resolve($exp, 'web');
        $telnet = ExperienceLaunch::resolve($exp, 'telnet');

        self::assertSame('web', $web['type']);
        self::assertSame('sg-web', $web['id']);
        self::assertSame('/games/sg-web', $web['url']);

        self::assertSame('native', $telnet['type']);
        self::assertSame('sg-term', $telnet['id']);
        self::assertSame('/games/nativedoors/sg-term?experience=1', $telnet['url']);
    }

    public function testMembersListRecordsEveryContributingBackend(): void
    {
        $exp = ExperienceComposition::compose($this->sharedGameInput())['shared-game'];

        $members = [];
        foreach ($exp['members'] as $m) {
            $members[$m['type']] = $m['id'];
        }
        self::assertSame(['native' => 'sg-term', 'web' => 'sg-web'], $members);
    }

    // ---- C. PRESENTATION DETERMINISM ----------------------------------

    public function testPresentationComesFromPrimaryRegardlessOfDiscoveryOrder(): void
    {
        $forward = $this->sharedGameInput();
        $reversed = array_reverse($forward, true);

        foreach (['forward' => $forward, 'reversed' => $reversed] as $label => $input) {
            $exp = ExperienceComposition::compose($input)['shared-game'];
            self::assertSame('Shared Game', $exp['name'], $label);
            self::assertSame('the canonical description', $exp['description'], $label);
            self::assertSame('web-icon', $exp['presentation']['icon'], $label);
            self::assertSame('game', $exp['category'], $label);
            self::assertSame('v-sg-web', $exp['version'], $label);
            self::assertSame('author-of-sg-web', $exp['author'], $label);
        }
    }

    // ---- D. CURATION on the canonical id ------------------------------

    public function testCuratingTheCanonicalIdProducesExactlyOneCuratedCard(): void
    {
        $out = ExperienceComposition::compose($this->sharedGameInput());

        // apply curation the way GameCatalog::applyCuration does
        $order = array_flip(['shared-game']);
        foreach ($out as $id => &$e) {
            $e['curation'] = ['curated' => isset($order[$id]), 'order' => $order[$id] ?? null];
        }
        unset($e);

        $shelves = CrossroadsShelves::group($out);
        self::assertCount(1, $shelves[CrossroadsShelves::CURATED]);
        self::assertSame('shared-game', $shelves[CrossroadsShelves::CURATED][0]['id']);
        self::assertCount(0, $shelves[CrossroadsShelves::GAME_HALL]);
    }

    // ---- E. NO DUPLICATE --------------------------------------------

    public function testContributingBackendIdsDoNotAppearIndependently(): void
    {
        $out = ExperienceComposition::compose($this->sharedGameInput());

        self::assertArrayNotHasKey('sg-web', $out);
        self::assertArrayNotHasKey('sg-term', $out);
    }

    // ---- F. INVALID / AMBIGUOUS GROUP -> FAIL CLOSED -----------------

    public function testTwoPrimaryMembersDropsTheGroup(): void
    {
        $input = $this->sharedGameInput();
        $input['sg-term']['grouping']['primary'] = true; // now both claim primary

        $out = ExperienceComposition::compose($input);

        self::assertArrayNotHasKey('shared-game', $out);
        self::assertSame([], $out, 'both members dropped, nothing chosen by order');
    }

    public function testNoPrimaryMemberDropsTheGroup(): void
    {
        $input = $this->sharedGameInput();
        $input['sg-web']['grouping']['primary'] = false;

        self::assertArrayNotHasKey('shared-game', ExperienceComposition::compose($input));
    }

    public function testTwoMembersClaimingTheSameSurfaceDropsTheGroup(): void
    {
        $input = [
            'a' => $this->row('a', 'web', ['web' => 'full', 'telnet' => 'planned'],
                ['group' => 'g', 'primary' => true, 'surface' => 'web']),
            'b' => $this->row('b', 'web', ['web' => 'full', 'telnet' => 'planned'],
                ['group' => 'g', 'primary' => false, 'surface' => 'web']),
        ];

        self::assertArrayNotHasKey('g', ExperienceComposition::compose($input));
    }

    public function testMemberDeclaringASurfaceItIsNotFullOnDropsTheGroup(): void
    {
        $input = $this->sharedGameInput();
        // web member claims telnet, but a WebDoor is only 'planned' on telnet
        $input['sg-web']['grouping']['surface'] = 'telnet';

        self::assertArrayNotHasKey('shared-game', ExperienceComposition::compose($input));
    }

    public function testInvalidSurfaceValueDropsTheGroup(): void
    {
        $input = $this->sharedGameInput();
        $input['sg-web']['grouping']['surface'] = 'carrier-pigeon';

        self::assertArrayNotHasKey('shared-game', ExperienceComposition::compose($input));
    }
}
