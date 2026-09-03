<?php

declare(strict_types=1);

use BinktermPHP\BbsConfig;
use PHPUnit\Framework\TestCase;

/**
 * `crossroads.curated_experiences` reading/normalization contract
 * (BbsConfig::getCuratedExperienceIds()).
 *
 * The private static config cache is stubbed via reflection so the test never
 * touches the operator's real config/bbs.json; reload() restores it.
 */
final class BbsConfigCuratedExperiencesTest extends TestCase
{
    protected function tearDown(): void
    {
        BbsConfig::reload();
    }

    /** @param mixed $crossroads */
    private function stubConfig($crossroads): void
    {
        $ref = new ReflectionClass(BbsConfig::class);

        $loaded = $ref->getProperty('loaded');
        $loaded->setAccessible(true);
        $loaded->setValue(null, true);

        $config = $ref->getProperty('config');
        $config->setAccessible(true);
        $value = ['features' => []];
        if ($crossroads !== '__absent__') {
            $value['crossroads'] = $crossroads;
        }
        $config->setValue(null, $value);
    }

    public function testAbsentKeyMeansNothingCurated(): void
    {
        $this->stubConfig('__absent__');
        self::assertSame([], BbsConfig::getCuratedExperienceIds());
    }

    public function testEmptyListMeansNothingCurated(): void
    {
        $this->stubConfig(['curated_experiences' => []]);
        self::assertSame([], BbsConfig::getCuratedExperienceIds());
    }

    public function testNonArrayValueDegradesToEmpty(): void
    {
        $this->stubConfig(['curated_experiences' => 'openglad']);
        self::assertSame([], BbsConfig::getCuratedExperienceIds());
    }

    public function testOperatorOrderIsPreserved(): void
    {
        $this->stubConfig([
            'curated_experiences' => ['multizork', 'ascii-royale-m3', 'openglad'],
        ]);
        self::assertSame(
            ['multizork', 'ascii-royale-m3', 'openglad'],
            BbsConfig::getCuratedExperienceIds()
        );
    }

    public function testEntriesAreTrimmedAndBlanksDropped(): void
    {
        $this->stubConfig([
            'curated_experiences' => ['  openglad  ', '', '   ', 'multizork'],
        ]);
        self::assertSame(
            ['openglad', 'multizork'],
            BbsConfig::getCuratedExperienceIds()
        );
    }

    public function testDuplicatesAreRemovedKeepingFirstPosition(): void
    {
        $this->stubConfig([
            'curated_experiences' => ['openglad', 'multizork', 'openglad'],
        ]);
        self::assertSame(
            ['openglad', 'multizork'],
            BbsConfig::getCuratedExperienceIds()
        );
    }

    public function testExampleConfigShipsAnEmptyCuratedList(): void
    {
        // Upstream/general BinkTerm default: opt-in, nothing curated.
        $json = (string)file_get_contents(dirname(__DIR__, 2) . '/config/bbs.json.example');
        $config = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('crossroads', $config);
        self::assertSame([], $config['crossroads']['curated_experiences']);
    }
}
