<?php

declare(strict_types=1);

use BinktermPHP\CuratedPlaceCatalog;
use BinktermPHP\ExperienceComposition;
use BinktermPHP\ExperienceLaunch;
use BinktermPHP\GameCatalog;
use PHPUnit\Framework\TestCase;

final class CuratedPlaceCatalogTest extends TestCase
{
    public function testLegacyWordleRemainsIndependent(): void
    {
        $catalog = $this->catalog();
        self::assertArrayHasKey('wordle', $catalog);
        self::assertSame('/games/wordle', ExperienceLaunch::resolve($catalog['wordle'], 'web')['url']);
        self::assertNotContains('wordle', array_column((new CuratedPlaceCatalog())->getDefinition('puzlmastrs-patch')['members'], 'reference'));
        self::assertSame('Wordle', json_decode(file_get_contents(dirname(__DIR__, 2) . '/public_html/webdoors/wordle/webdoor.json'), true)['game']['name']);
    }

    private function catalog(): array
    {
        $rows = [];
        foreach (['wordle', 'wordwright', 'hangman', 'blackjack', 'parlour', 'tatham-web', 'tatham-terminal', 'breaklock', 'breaklock-terminal', 'ordinary-puzzles', 'ordinary-puzzles-terminal', 'wordwright-terminal', 'dokuel', 'dokuel-terminal'] as $id) {
            $native = str_ends_with($id, '-terminal');
            $rows[$id] = [
                'id' => $id, 'name' => $id, 'description' => '',
                'backend' => ['type' => $native ? 'native' : 'web', 'id' => $id],
                'policy' => ['enabled' => true],
                'surfaces' => ['web' => 'full', 'telnet' => $native ? 'full' : 'planned'],
            ];
        }
        // Real manifests supply the capability and grouping, not a PP fixture.
        foreach (['wordwright', 'wordwright-terminal', 'tatham-web', 'tatham-terminal', 'breaklock', 'breaklock-terminal', 'ordinary-puzzles', 'ordinary-puzzles-terminal', 'dokuel', 'dokuel-terminal'] as $id) {
            $path = str_ends_with($id, '-terminal')
                ? '/native-doors/doors/' . $id . '/nativedoor.json'
                : '/public_html/webdoors/' . $id . '/webdoor.json';
            $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . $path), true, 512, JSON_THROW_ON_ERROR);
            $rows[$id]['grouping'] = $manifest['experience'];
            $rows[$id]['source']['manifest'] = $manifest;
        }
        return ExperienceComposition::compose($rows);
    }

    public function testDefinitionAndResolvedOrder(): void
    {
        $source = $this->createMock(GameCatalog::class);
        $source->expects(self::once())->method('getEnabledGames')
            ->with(['id' => 7], 'web')->willReturn($this->catalog());
        $service = new CuratedPlaceCatalog($source);
        $definition = $service->getDefinition('puzlmastrs-patch');
        self::assertSame("Puzlmastr's Patch", $definition['name']);
        self::assertSame('place', $definition['kind']);
        self::assertSame('curated', $definition['parent']);
        $order = ['wordwright', 'hangman', 'blackjack', 'parlour', 'tatham/lightup', 'breaklock', 'ordinary-puzzles', 'dokuel'];
        self::assertSame($order, array_column($definition['members'], 'reference'));
        $place = $service->getPlace('puzlmastrs-patch', ['id' => 7]);
        self::assertSame($order, array_column($place['members'], 'reference'));
        self::assertSame('Light Up', $place['members'][4]['title']);
    }

    public function testDefaultAndGroupedEntryKeepRuntimeLaunchIdentity(): void
    {
        $catalog = $this->catalog();
        $before = $catalog;
        foreach (['wordwright', 'hangman', 'blackjack', 'parlour', 'tatham/lightup'] as $ref) {
            $id = explode('/', $ref)[0];
            foreach (['web', 'telnet'] as $surface) {
                $resolved = CuratedPlaceCatalog::resolveReference($ref, $catalog, $surface);
                self::assertSame($id, $resolved['experience_id']);
                self::assertSame(ExperienceLaunch::resolve($catalog[$id], $surface), $resolved['launch']);
                self::assertSame($id === 'tatham' ? 'lightup' : null, $resolved['entry_id']);
            }
        }
        self::assertSame('tatham-web', CuratedPlaceCatalog::resolveReference('tatham/lightup', $catalog, 'web')['launch']['id']);
        self::assertSame('tatham-terminal', CuratedPlaceCatalog::resolveReference('tatham/lightup', $catalog, 'telnet')['launch']['id']);
        self::assertSame('breaklock', CuratedPlaceCatalog::resolveReference('breaklock', $catalog, 'web')['launch']['id']);
        self::assertSame('breaklock-terminal', CuratedPlaceCatalog::resolveReference('breaklock', $catalog, 'telnet')['launch']['id']);
        self::assertSame('ordinary-puzzles-terminal', CuratedPlaceCatalog::resolveReference('ordinary-puzzles', $catalog, 'telnet')['launch']['id']);
        self::assertSame($before, $catalog);
        unset($catalog['ordinary-puzzles']);
        self::assertNull(CuratedPlaceCatalog::resolveReference('ordinary-puzzles', $catalog, 'web'));

    }

    public function testUnsupportedAndMalformedReferencesFailClosed(): void
    {
        foreach (['tatham/slant', 'tatham/unequal', 'tatham/solo', 'missing',
            'wordwright/extra', '', '../wordwright', 'tatham/', 'tatham/lightup/extra', 'tatham%2Flightup'] as $ref) {
            self::assertNull(CuratedPlaceCatalog::resolveReference($ref, $this->catalog(), 'web'));
        }
        self::assertNull(CuratedPlaceCatalog::resolveReference('wordwright', $this->catalog(), 'bogus'));
    }

    public function testDisabledAndUnavailableRuntimesAreHidden(): void
    {
        $catalog = $this->catalog();
        $catalog['tatham']['policy']['enabled'] = false;
        self::assertNull(CuratedPlaceCatalog::resolveReference('tatham/lightup', $catalog, 'web'));
        $catalog['wordwright']['surfaces'] = ['web' => 'unavailable', 'telnet' => 'planned'];
        self::assertNull(CuratedPlaceCatalog::resolveReference('wordwright', $catalog, 'web'));
    }

    public function testCallerAuthorizationRemainsOwnedByGameCatalog(): void
    {
        $authorized = $this->catalog();
        $restricted = $authorized;
        unset($restricted['tatham']);
        $source = $this->createMock(GameCatalog::class);
        $source->expects(self::exactly(2))->method('getEnabledGames')->willReturnCallback(
            static function (?array $user, string $surface) use ($authorized, $restricted): array {
                self::assertSame('web', $surface);
                return $user === ['id' => 7, 'is_admin' => true] ? $authorized : $restricted;
            }
        );
        $service = new CuratedPlaceCatalog($source);
        self::assertCount(8, $service->getPlace('puzlmastrs-patch', ['id' => 7, 'is_admin' => true])['members']);
        $place = $service->getPlace('puzlmastrs-patch', ['id' => 8, 'is_admin' => false]);
        self::assertSame(['wordwright', 'hangman', 'blackjack', 'parlour', 'breaklock', 'ordinary-puzzles', 'dokuel'], array_column($place['members'], 'reference'));
    }

    public function testAnotherSurfaceRemainsVisibleWithoutInventedLaunch(): void
    {
        $source = $this->createMock(GameCatalog::class);
        $source->expects(self::once())->method('getEnabledGames')
            ->with(['id' => 7], 'telnet')->willReturn($this->catalog());
        $place = (new CuratedPlaceCatalog($source))->getPlace('puzlmastrs-patch', ['id' => 7], 'terminal');
        self::assertCount(8, $place['members']);
        self::assertSame('wordwright-terminal', $place['members'][0]['launch']['id']);
        self::assertNull($place['members'][1]['launch']);
        self::assertSame(['web' => 'full', 'telnet' => 'unavailable'], $place['members'][1]['surfaces']);
        self::assertSame(['web' => 'full', 'telnet' => 'full'], $place['members'][4]['surfaces']);
    }

    public function testMetadataCannotGrantAccessAndDisabledPlaceDoesNotDiscover(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pp-test-');
        try {
            $definition = (new CuratedPlaceCatalog())->getDefinition('puzlmastrs-patch');
            $definition['members'] = [
                ['reference' => 'wordle', 'title' => 'Local title', 'description' => 'Context',
                    'surfaces' => ['telnet' => 'full'], 'launch' => ['id' => 'other']],
                ['reference' => 'missing', 'enabled' => true],
            ];
            file_put_contents($path, json_encode(['puzlmastrs-patch' => $definition]));
            $source = $this->createMock(GameCatalog::class);
            $source->expects(self::once())->method('getEnabledGames')->willReturn($this->catalog());
            $service = new CuratedPlaceCatalog($source, $path);
            $members = $service->getPlace('puzlmastrs-patch', ['id' => 7], 'telnet')['members'];
            self::assertCount(1, $members);
            self::assertSame('Local title', $members[0]['title']);
            self::assertSame('Context', $members[0]['description']);
            self::assertNull($members[0]['launch']);
            $definition['enabled'] = false;
            file_put_contents($path, json_encode(['puzlmastrs-patch' => $definition]));
            self::assertNull($service->getPlace('puzlmastrs-patch', ['id' => 7]));
            file_put_contents($path, '{broken');
            self::assertNull($service->getDefinition('puzlmastrs-patch'));
            self::assertNull($service->getDefinition('../other'));
        } finally {
            unlink($path);
        }
    }
    public function testReturnContextValidatesMembershipAndNeverAcceptsUrls(): void
    {
        $source = $this->createMock(GameCatalog::class);
        $source->method('getEnabledGames')->willReturn($this->catalog());
        $service = new CuratedPlaceCatalog($source);
        foreach (['wordwright', 'hangman', 'blackjack', 'parlour', 'tatham-web'] as $backend) {
            self::assertSame('/places/puzlmastrs-patch', $service->returnTarget('puzlmastrs-patch', ['id' => 7], $backend));
        }
        foreach ([null, '', 'missing', 'https://evil.invalid', '//evil.invalid', '../puzlmastrs-patch', ['puzlmastrs-patch']] as $id) {
            self::assertNull($service->returnTarget($id, ['id' => 7], 'wordwright'));
        }
        self::assertNull($service->returnTarget('puzlmastrs-patch', ['id' => 7], 'unrelated'));
        $denied = $this->createMock(GameCatalog::class);
        $denied->method('getEnabledGames')->willReturn([]);
        self::assertNull((new CuratedPlaceCatalog($denied))->returnTarget('puzlmastrs-patch', ['id' => 8], 'wordwright'));
        $path = tempnam(sys_get_temp_dir(), 'pp-disabled-');
        try {
            $place = $service->getDefinition('puzlmastrs-patch');
            $place['enabled'] = false;
            file_put_contents($path, json_encode(['puzlmastrs-patch' => $place]));
            self::assertNull((new CuratedPlaceCatalog($source, $path))->returnTarget('puzlmastrs-patch', ['id' => 7], 'wordwright'));
        } finally { unlink($path); }
    }
}
