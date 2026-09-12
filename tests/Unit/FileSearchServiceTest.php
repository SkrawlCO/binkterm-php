<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/TestDatabase.php';

use BinktermPHP\Database;
use BinktermPHP\FileAreaManager;
use BinktermPHP\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * F-1 — canonical accessible-file search.
 *
 * {@see FileAreaManager::searchAccessibleFiles()} is the single implementation
 * of the search + access rules that the `GET /api/files/search` web route and
 * the terminal File Search both use. These tests pin the behaviour that was
 * extracted verbatim from the old inline route SQL:
 *
 *   - an ordinary user sees only files in areas they can access
 *   - a private area is visible only to its PRIVATE_USER_<id> owner
 *   - a guest is restricted to is_public areas
 *   - an administrator gains no extra search scope (extraction, not rewrite)
 *   - the term matches filename OR short_description, case-insensitively
 *   - the row limit is enforced, ordered by area tag then filename
 *   - a query shorter than 2 characters returns []
 *
 * The suite seeds rows inside a transaction that is always rolled back, and
 * skips when no database is configured (matching UnifiedNewscanServiceTest).
 */
final class FileSearchServiceTest extends TestCase
{
    private \PDO $pdo;
    private FileAreaManager $manager;

    /** Area ids by role, populated in seed(). */
    private array $areaIds = [];

    /** The marker every seeded filename / description shares. */
    private const TOKEN = 'f1srch';

    protected function setUp(): void
    {
        try {
            $this->pdo = TestDatabase::pdo();
        } catch (\Throwable $e) {
            self::markTestSkipped('database not available: ' . $e->getMessage());
        }
        // Install the same isolated PDO into the singleton BEFORE constructing
        // FileAreaManager below, which internally calls Database::getInstance().
        Database::setInstanceForTesting($this->pdo);

        $this->pdo->beginTransaction();
        $this->manager = new FileAreaManager();
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        Database::resetInstanceForTesting();
    }

    private function newArea(string $tag, bool $active, bool $private, bool $public): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO file_areas (tag, domain, is_active, is_private, is_public)
             VALUES (:tag, 'f1test', :active, :private, :public)
             RETURNING id"
        );
        $stmt->execute([
            ':tag'     => $tag,
            ':active'  => $active ? 'true' : 'false',
            ':private' => $private ? 'true' : 'false',
            ':public'  => $public ? 'true' : 'false',
        ]);

        return (int)$stmt->fetch(\PDO::FETCH_ASSOC)['id'];
    }

    private function newFile(
        int $areaId,
        string $filename,
        string $shortDescription = '',
        string $status = 'approved',
        string $sourceType = 'fidonet'
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO files (file_area_id, filename, filesize, short_description, status, source_type, file_hash)
             VALUES (:aid, :fn, 123, :sd, :st, :src, :hash)
             RETURNING id"
        );
        $stmt->execute([
            ':aid'  => $areaId,
            ':fn'   => $filename,
            ':sd'   => $shortDescription,
            ':st'   => $status,
            ':src'  => $sourceType,
            ':hash' => bin2hex(random_bytes(8)),
        ]);

        return (int)$stmt->fetch(\PDO::FETCH_ASSOC)['id'];
    }

    private function seed(): void
    {
        // Unique tag suffix so a leaked row (should never happen — we roll back)
        // never collides with a rerun.
        $s = strtoupper(bin2hex(random_bytes(3)));

        $this->areaIds = [
            'public'    => $this->newArea("F1_PUB_$s",  active: true,  private: false, public: true),
            'members'   => $this->newArea("F1_MEM_$s",  active: true,  private: false, public: false),
            'inactive'  => $this->newArea("F1_OFF_$s",  active: false, private: false, public: false),
            'priv_101'  => $this->newArea('PRIVATE_USER_9199001', active: true, private: true, public: false),
            'priv_102'  => $this->newArea('PRIVATE_USER_9199002', active: true, private: true, public: false),
        ];

        // One matching file per area.
        $this->newFile($this->areaIds['public'],   self::TOKEN . '-public.zip');
        $this->newFile($this->areaIds['members'],  self::TOKEN . '-members.zip');
        $this->newFile($this->areaIds['inactive'], self::TOKEN . '-inactive.zip');
        $this->newFile($this->areaIds['priv_101'], self::TOKEN . '-priv101.zip');
        $this->newFile($this->areaIds['priv_102'], self::TOKEN . '-priv102.zip');

        // Description-only match, and rows that must never appear.
        $this->newFile($this->areaIds['public'], 'unrelated-name.txt', 'contains ' . self::TOKEN . ' in the blurb');
        $this->newFile($this->areaIds['public'], 'not-a-match.txt', 'nothing to see');
        $this->newFile($this->areaIds['public'], self::TOKEN . '-pending.zip', '', 'pending');
        $this->newFile($this->areaIds['public'], self::TOKEN . '-isosub.zip', '', 'approved', 'iso_subdir');
    }

    /** @return string[] filenames from a result set */
    private function names(array $rows): array
    {
        return array_map(static fn(array $r): string => (string)$r['filename'], $rows);
    }

    public function testOrdinaryUserSeesOnlyAccessibleAreas(): void
    {
        $rows  = $this->manager->searchAccessibleFiles(self::TOKEN, 9199001, false, false, 100);
        $names = $this->names($rows);

        self::assertContains(self::TOKEN . '-public.zip', $names);
        self::assertContains(self::TOKEN . '-members.zip', $names);
        self::assertContains(self::TOKEN . '-priv101.zip', $names, 'own private area is visible');

        self::assertNotContains(self::TOKEN . '-inactive.zip', $names, 'inactive area excluded');
        self::assertNotContains(self::TOKEN . '-priv102.zip', $names, "another user's private area excluded");
        self::assertNotContains(self::TOKEN . '-pending.zip', $names, 'non-approved file excluded');
        self::assertNotContains(self::TOKEN . '-isosub.zip', $names, 'iso_subdir pseudo-row excluded');
    }

    public function testPrivateAreaIsScopedToItsOwner(): void
    {
        $for102 = $this->names($this->manager->searchAccessibleFiles(self::TOKEN, 9199002, false, false, 100));
        self::assertContains(self::TOKEN . '-priv102.zip', $for102);
        self::assertNotContains(self::TOKEN . '-priv101.zip', $for102);

        $for101 = $this->names($this->manager->searchAccessibleFiles(self::TOKEN, 9199001, false, false, 100));
        self::assertNotContains(self::TOKEN . '-priv102.zip', $for101);
    }

    public function testGuestIsRestrictedToPublicAreas(): void
    {
        $names = $this->names($this->manager->searchAccessibleFiles(self::TOKEN, null, false, true, 100));

        self::assertContains(self::TOKEN . '-public.zip', $names);
        self::assertNotContains(self::TOKEN . '-members.zip', $names, 'non-public area hidden from guests');
        self::assertNotContains(self::TOKEN . '-priv101.zip', $names);
        self::assertNotContains(self::TOKEN . '-priv102.zip', $names);
    }

    public function testAdminGainsNoExtraSearchScope(): void
    {
        $asUser  = $this->names($this->manager->searchAccessibleFiles(self::TOKEN, 9199001, false, false, 100));
        $asAdmin = $this->names($this->manager->searchAccessibleFiles(self::TOKEN, 9199001, true, false, 100));

        sort($asUser);
        sort($asAdmin);
        self::assertSame($asUser, $asAdmin, 'admin flag must not widen search scope');
        self::assertNotContains(self::TOKEN . '-priv102.zip', $asAdmin);
    }

    public function testQueryMatchesFilenameOrDescriptionAndFiltersOut(): void
    {
        $names = $this->names($this->manager->searchAccessibleFiles(self::TOKEN, 9199001, false, false, 100));
        self::assertContains('unrelated-name.txt', $names, 'description-only match is found');
        self::assertNotContains('not-a-match.txt', $names);

        self::assertSame([], $this->manager->searchAccessibleFiles('zzz-no-such-term-zzz', 9199001, false, false, 100));
    }

    public function testResultLimitIsEnforcedAndOrdered(): void
    {
        $all = $this->manager->searchAccessibleFiles(self::TOKEN, 9199001, false, false, 100);
        self::assertGreaterThan(2, count($all));

        $limited = $this->manager->searchAccessibleFiles(self::TOKEN, 9199001, false, false, 2);
        self::assertCount(2, $limited);

        // Ordered by area tag ASC, then filename ASC.
        $tags = array_map(static fn(array $r): string => (string)$r['area_tag'], $all);
        $sorted = $tags;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $tags, 'rows ordered by area tag');
    }

    public function testShortQueryReturnsEmpty(): void
    {
        self::assertSame([], $this->manager->searchAccessibleFiles('z', 9199001, false, false, 100));
        self::assertSame([], $this->manager->searchAccessibleFiles('  ', 9199001, false, false, 100));
    }

    public function testNumericFieldsAreCastToInt(): void
    {
        $rows = $this->manager->searchAccessibleFiles(self::TOKEN, 9199001, false, false, 1);
        self::assertNotEmpty($rows);
        $row = $rows[0];
        self::assertIsInt($row['id']);
        self::assertIsInt($row['area_id']);
        self::assertIsInt($row['filesize']);
        self::assertArrayHasKey('area_tag', $row);
        self::assertArrayHasKey('short_description', $row);
        self::assertArrayHasKey('created_at', $row);
        self::assertArrayHasKey('subfolder', $row);
    }
}
