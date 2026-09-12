<?php

declare(strict_types=1);

use BinktermPHP\Directory\IbbsImportService;
use BinktermPHP\Database;
use PHPUnit\Framework\TestCase;

/**
 * IBBS (Telnet BBS Guide) directory sync importer — parsing, normalization,
 * identity/dedupe, and dry-run reconciliation planning.
 */
final class IbbsImportServiceTest extends TestCase
{
    private const HEADER = "bbsName, bbsSysop, newLogin, TelnetAddress, bbsPort, sshPort, WebAddress, location, Modem, software\n";

    public function testParsesValidCsvIntoNormalizedRecords(): void
    {
        $csv = self::HEADER .
            "Test BBS,Sysop Name,NEW,bbs.example.com,2323,22,http://example.com,\"Denver, CO, USA\",,Mystic\n";
        $service = new IbbsImportService();
        $result = $service->parseCsv($csv);

        $this->assertSame(1, $result['stats']['source_rows']);
        $this->assertSame(1, $result['stats']['valid_rows']);
        $record = $result['records'][0];
        $this->assertSame('Test BBS', $record['name']);
        $this->assertSame('Sysop Name', $record['sysop']);
        $this->assertSame('bbs.example.com', $record['telnet_host']);
        $this->assertSame(2323, $record['telnet_port']);
        $this->assertSame(22, $record['ssh_port']);
        $this->assertSame('http://example.com', $record['website']);
        $this->assertSame('Denver, CO, USA', $record['location']);
        $this->assertSame('Mystic', $record['software']);
    }

    public function testBlankStringsNormalizeToNull(): void
    {
        $csv = self::HEADER . "Blank Fields BBS,,,,,,,,,,\n";
        // The row above has too many columns due to trailing comma; use exact column count instead.
        $csv = self::HEADER . "Blank Fields BBS,,,,,,,,,\n";
        $service = new IbbsImportService();
        $result = $service->parseCsv($csv);

        $this->assertSame(1, $result['stats']['valid_rows']);
        $record = $result['records'][0];
        $this->assertSame('Blank Fields BBS', $record['name']);
        $this->assertNull($record['sysop']);
        $this->assertNull($record['telnet_host']);
        $this->assertNull($record['telnet_port']);
        $this->assertNull($record['website']);
        $this->assertNull($record['location']);
        $this->assertNull($record['software']);
        $this->assertSame(1, $result['stats']['missing_telnet_endpoint_count']);
    }

    public function testDefaultsTelnetPortTo23WhenHostPresentButPortBlank(): void
    {
        $csv = self::HEADER . "Port Default BBS,,,bbs.example.net,,,,,,\n";
        $service = new IbbsImportService();
        $result = $service->parseCsv($csv);

        $this->assertSame(23, $result['records'][0]['telnet_port']);
    }

    public function testInvalidPortIsFlaggedAndEndpointDropped(): void
    {
        $csv = self::HEADER . "Bad Port BBS,,,bbs.example.net,notaport,,,,,\n";
        $service = new IbbsImportService();
        $result = $service->parseCsv($csv);

        $this->assertSame(1, $result['stats']['invalid_port_count']);
        $this->assertNull($result['records'][0]['telnet_host']);
    }

    public function testBlankNameRowIsExcludedAsInvalid(): void
    {
        $csv = self::HEADER . ",Sysop,,bbs.example.net,23,,,,,Mystic\n";
        $service = new IbbsImportService();
        $result = $service->parseCsv($csv);

        $this->assertSame(0, $result['stats']['valid_rows']);
        $this->assertSame(1, $result['stats']['invalid_count']);
    }

    public function testModemPreservedAsNotesNotMergedIntoUnrelatedField(): void
    {
        $csv = self::HEADER . "Dialup BBS,,,,,,,,555-1234,\n";
        $service = new IbbsImportService();
        $result = $service->parseCsv($csv);

        $this->assertSame('Modem: 555-1234', $result['records'][0]['notes']);
    }

    public function testMissingCsvHeaderFieldThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $service = new IbbsImportService();
        $service->parseCsv("bbsName,bbsSysop\nOnly Two Cols,Sysop\n");
    }

    public function testMissingZipThrowsWithoutWrites(): void
    {
        $this->expectException(\RuntimeException::class);
        $service = new IbbsImportService();
        $service->readCsvFromZip('/tmp/does-not-exist-ibbs-' . uniqid() . '.zip');
    }

    public function testInvalidZipThrowsWithoutWrites(): void
    {
        $badZip = tempnam(sys_get_temp_dir(), 'ibbs_bad_');
        file_put_contents($badZip, 'not a real zip file');
        try {
            $this->expectException(\RuntimeException::class);
            $service = new IbbsImportService();
            $service->readCsvFromZip($badZip);
        } finally {
            @unlink($badZip);
        }
    }

    public function testZipMissingBbslistCsvThrows(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'ibbs_nocsv_') . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'no csv here');
        $zip->close();

        try {
            $this->expectException(\RuntimeException::class);
            $service = new IbbsImportService();
            $service->readCsvFromZip($zipPath);
        } finally {
            @unlink($zipPath);
        }
    }

    public function testLowRecordCountGuardConstantMatchesRecon(): void
    {
        // Recon established ~1,041-1,047 records for a healthy monthly edition;
        // the apply-time guard must reject anything drastically smaller.
        $this->assertSame(500, IbbsImportService::MIN_PLAUSIBLE_ROWS);
    }

    public function testDuplicateEndpointInBatchIsAmbiguousCollisionNotAutoMerged(): void
    {
        $csv = self::HEADER .
            "Board A,,,bbs.shared.net,23,,,,,\n" .
            "Board B,,,BBS.SHARED.NET,23,,,,,\n"; // same endpoint, different case
        $service = new IbbsImportService(self::db());
        $parsed = $service->parseCsv($csv);
        $plan = $service->planReconciliation($parsed['records']);

        // Different names sharing an endpoint remain distinct candidate records:
        // still flagged for review, but (having no name collision in the DB and
        // dissimilar names/no location match) eligible for insertion as two
        // separate boards rather than silently merged or dropped.
        $this->assertSame(2, $plan['counts']['ambiguous_collision']);
        $this->assertSame(2, $plan['counts']['ambiguous_collision_distinct_shared_endpoint']);
        $this->assertSame(0, $plan['counts']['ambiguous_collision_likely_alias']);
        $this->assertSame(2, $plan['counts']['would_add']);
    }

    public function testIsLocalRowIsProtectedAndNeverProposedForUpdate(): void
    {
        $db = self::db();
        $name = 'Test Protected Local BBS ' . uniqid();
        $db->prepare("INSERT INTO bbs_directory (name, sysop, telnet_host, telnet_port, source, is_local, status)
                      VALUES (:name, 'Old Sysop', 'old.example.com', 23, 'manual', TRUE, 'active')")
            ->execute(['name' => $name]);

        try {
            $csv = self::HEADER . "{$name},New Sysop From Upstream,,new.example.com,2323,,,,,\n";
            $service = new IbbsImportService($db);
            $parsed = $service->parseCsv($csv);
            $plan = $service->planReconciliation($parsed['records']);

            $this->assertSame(1, $plan['counts']['protected_local']);
            $this->assertSame(0, $plan['counts']['would_update']);
        } finally {
            $db->prepare('DELETE FROM bbs_directory WHERE name = :name')->execute(['name' => $name]);
        }
    }

    public function testDryRunPlanningIssuesNoWrites(): void
    {
        $db = self::db();
        $countBefore = (int)$db->query('SELECT COUNT(*) FROM bbs_directory')->fetchColumn();

        $csv = self::HEADER . "Dry Run Only BBS " . uniqid() . ",,,dryrun.example.com,23,,,,,\n";
        $service = new IbbsImportService($db);
        $parsed = $service->parseCsv($csv);
        $plan = $service->planReconciliation($parsed['records']);

        $this->assertSame(1, $plan['counts']['would_add']);
        $countAfter = (int)$db->query('SELECT COUNT(*) FROM bbs_directory')->fetchColumn();
        $this->assertSame($countBefore, $countAfter, 'planReconciliation() must not write to bbs_directory');
    }

    private static function db(): \PDO
    {
        return Database::getInstance()->getPdo();
    }
}
