<?php

namespace BinktermPHP\Directory;

/**
 * Import service for the Telnet BBS Guide "IBBS" downloadable list
 * (https://www.telnetbbsguide.com/lists/download-list/).
 *
 * Acquisition (locating/downloading the ZIP) is deliberately kept separate
 * from parsing and reconciliation: this service only ever reads a local ZIP
 * path. Reconciliation is read-only/planning by default (dry run); nothing
 * in this class writes to bbs_directory. Applying a plan is a future slice.
 *
 * Reconciliation mirrors the matching semantics already used by
 * BbsDirectory::upsertByName(): telnet host+port first, case-insensitive
 * name as a fallback, and is_local=TRUE rows are always protected.
 */
class IbbsImportService
{
    /** Minimum plausible valid-row count for this source; below this, refuse to apply. */
    public const MIN_PLAUSIBLE_ROWS = 500;

    private const REQUIRED_CSV_FIELDS = ['bbsName', 'bbsSysop', 'newLogin', 'TelnetAddress', 'bbsPort', 'sshPort', 'WebAddress', 'location', 'Modem', 'software'];

    /** @var \PDO|null */
    private ?\PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db;
    }

    /**
     * Extract bbslist.csv from a local IBBS ZIP archive and return its raw text.
     *
     * @throws \RuntimeException on missing/invalid ZIP or missing bbslist.csv
     */
    public function readCsvFromZip(string $zipPath): string
    {
        if (!is_file($zipPath)) {
            throw new \RuntimeException("IBBS archive not found: {$zipPath}");
        }

        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            throw new \RuntimeException("Failed to open IBBS archive (ZipArchive error code {$openResult}): {$zipPath}");
        }

        $csv = $zip->getFromName('bbslist.csv');
        $zip->close();

        if ($csv === false) {
            throw new \RuntimeException("bbslist.csv not found inside archive: {$zipPath}");
        }

        return $csv;
    }

    /**
     * Parse raw bbslist.csv text into normalized directory records.
     *
     * Returns ['records' => array, 'stats' => array] where stats includes
     * source_rows, valid_rows, invalid rows and anomaly counters/samples.
     *
     * @throws \RuntimeException on malformed CSV (missing/mismatched header)
     */
    public function parseCsv(string $csvText): array
    {
        $lines = preg_split("/\r\n|\r|\n/", $csvText);
        // fgetcsv-over-string trick: use a memory stream so we get proper CSV quoting/escaping.
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csvText);
        rewind($stream);

        $header = fgetcsv($stream, 0, ',', '"', '\\');
        if ($header === false) {
            fclose($stream);
            throw new \RuntimeException('IBBS CSV is empty or unreadable');
        }
        $header = array_map('trim', $header);

        $missingFields = array_diff(self::REQUIRED_CSV_FIELDS, $header);
        if (!empty($missingFields)) {
            fclose($stream);
            throw new \RuntimeException('IBBS CSV header missing expected fields: ' . implode(', ', $missingFields));
        }

        $sourceRows = 0;
        $records = [];
        $anomalies = [
            'invalid' => [],
            'missing_telnet_endpoint' => [],
            'invalid_port' => [],
        ];

        while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            // Skip blank trailing lines (fgetcsv on an empty line yields [null]).
            if ($row === [null] || $row === ['']) {
                continue;
            }
            $sourceRows++;

            if (count($row) !== count($header)) {
                $anomalies['invalid'][] = ['row_number' => $sourceRows, 'reason' => 'column count mismatch'];
                continue;
            }

            $raw = array_combine($header, $row);
            $normalized = $this->normalizeRecord($raw, $sourceRows, $anomalies);
            if ($normalized !== null) {
                $records[] = $normalized;
            }
        }
        fclose($stream);

        $stats = [
            'source_rows' => $sourceRows,
            'valid_rows' => count($records),
            'invalid_count' => count($anomalies['invalid']),
            'missing_telnet_endpoint_count' => count($anomalies['missing_telnet_endpoint']),
            'invalid_port_count' => count($anomalies['invalid_port']),
            'anomaly_samples' => [
                'invalid' => array_slice($anomalies['invalid'], 0, 5),
                'missing_telnet_endpoint' => array_slice($anomalies['missing_telnet_endpoint'], 0, 5),
                'invalid_port' => array_slice($anomalies['invalid_port'], 0, 5),
            ],
        ];

        return ['records' => $records, 'stats' => $stats];
    }

    /**
     * Normalize one raw CSV row into a directory-shaped record.
     *
     * Field mapping:
     *   bbsName -> name, bbsSysop -> sysop, TelnetAddress -> telnet_host,
     *   bbsPort -> telnet_port, sshPort -> ssh_port, WebAddress -> website,
     *   location -> location, software -> software, Modem -> notes (preserved,
     *   not merged into an unrelated field). newLogin is a per-user login
     *   hint, not board metadata, and is ignored.
     *
     * Returns null (and records an anomaly) for rows with no usable name.
     */
    private function normalizeRecord(array $raw, int $rowNumber, array &$anomalies): ?array
    {
        $name = trim($raw['bbsName'] ?? '');
        if ($name === '') {
            $anomalies['invalid'][] = ['row_number' => $rowNumber, 'reason' => 'blank bbsName'];
            return null;
        }

        $sysop = $this->blankToNull(trim($raw['bbsSysop'] ?? ''));
        $host = $this->blankToNull(trim($raw['TelnetAddress'] ?? ''));
        $host = $host !== null ? mb_strtolower($host) : null;
        $website = $this->blankToNull(trim($raw['WebAddress'] ?? ''));
        $location = $this->blankToNull(trim($raw['location'] ?? ''));
        $software = $this->blankToNull(trim($raw['software'] ?? ''));
        $modem = $this->blankToNull(trim($raw['Modem'] ?? ''));

        $telnetPort = null;
        $portRaw = trim($raw['bbsPort'] ?? '');
        if ($host !== null) {
            if ($portRaw === '') {
                $telnetPort = 23;
            } elseif (ctype_digit($portRaw)) {
                $telnetPort = (int)$portRaw;
            } else {
                $anomalies['invalid_port'][] = ['row_number' => $rowNumber, 'name' => $name, 'bbsPort' => $portRaw];
                $host = null; // can't form a usable endpoint
            }
        }

        if ($host === null) {
            $anomalies['missing_telnet_endpoint'][] = ['row_number' => $rowNumber, 'name' => $name];
        }

        $sshPort = null;
        $sshRaw = trim($raw['sshPort'] ?? '');
        if ($sshRaw !== '' && ctype_digit($sshRaw)) {
            $sshPort = (int)$sshRaw;
        }

        return [
            'row_number' => $rowNumber,
            'name' => $name,
            'sysop' => $sysop,
            'telnet_host' => $host,
            'telnet_port' => $telnetPort,
            'ssh_port' => $sshPort,
            'website' => $website,
            'location' => $location,
            'software' => $software,
            'notes' => $modem !== null ? "Modem: {$modem}" : null,
        ];
    }

    private function blankToNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * Build a dry-run reconciliation plan for normalized records against the
     * live bbs_directory table. READ-ONLY — issues no writes.
     *
     * Matching mirrors BbsDirectory::upsertByName(): endpoint (telnet_host +
     * telnet_port, case-insensitive host) first, case-insensitive name as
     * fallback. is_local=TRUE rows are always classified as protected_local
     * and never proposed for update.
     *
     * Records whose derived identity (endpoint) collides with another
     * record in the same import batch are classified as ambiguous_collision
     * rather than silently merged. Different names sharing an endpoint
     * always remain distinct candidate records; a shared endpoint or a
     * matching location is NEVER, by itself, grounds to merge them.
     *
     * Within that bucket, each collision group is additionally tagged (for
     * report/review purposes only — it changes nothing about how the row is
     * planned) as LIKELY_ALIAS only when ALL of the following hold:
     *   - same endpoint (the grouping key itself)
     *   - same normalized location
     *   - strongly similar normalized name
     * Location match alone (without name similarity) is NOT sufficient.
     * A location mismatch is treated as strong evidence the records are
     * distinct boards and the group is tagged DISTINCT_SHARED_ENDPOINT.
     */
    public function planReconciliation(array $records): array
    {
        if ($this->db === null) {
            throw new \RuntimeException('planReconciliation() requires a database connection');
        }

        // Detect in-batch endpoint collisions first (data-level ambiguity,
        // independent of what's already in the DB).
        $endpointGroups = [];
        foreach ($records as $r) {
            if ($r['telnet_host'] === null) {
                continue;
            }
            $key = $r['telnet_host'] . ':' . $r['telnet_port'];
            $endpointGroups[$key][] = $r;
        }
        $collisionKeys = [];
        $collisionClassification = [];
        foreach ($endpointGroups as $key => $group) {
            if (count($group) > 1) {
                $collisionKeys[$key] = array_column($group, 'name');
                $collisionClassification[$key] = $this->classifyCollisionGroup($group);
            }
        }

        $plan = [
            'would_add' => [],
            'would_update' => [],
            'unchanged' => [],
            'protected_local' => [],
            'ambiguous_collision' => [],
        ];

        $findByEndpoint = $this->db->prepare(
            "SELECT id, name, sysop, location, os, telnet_host, telnet_port, ssh_port, website, software, is_local
             FROM bbs_directory WHERE LOWER(telnet_host) = LOWER(:host) AND telnet_port = :port"
        );
        $findByName = $this->db->prepare(
            "SELECT id, name, sysop, location, os, telnet_host, telnet_port, ssh_port, website, software, is_local
             FROM bbs_directory WHERE LOWER(name) = LOWER(:name)"
        );

        foreach ($records as $r) {
            $key = $r['telnet_host'] !== null ? ($r['telnet_host'] . ':' . $r['telnet_port']) : null;
            $isCollision = $key !== null && isset($collisionKeys[$key]);
            $sharedEndpoint = false;

            if ($isCollision) {
                $plan['ambiguous_collision'][] = [
                    'name' => $r['name'],
                    'endpoint' => $key,
                    'colliding_names' => $collisionKeys[$key],
                    'classification' => $collisionClassification[$key]['classification'],
                    'location_match' => $collisionClassification[$key]['location_match'],
                    'name_similar' => $collisionClassification[$key]['name_similar'],
                ];

                if ($collisionClassification[$key]['classification'] === 'LIKELY_ALIAS') {
                    // Report-only; never inserted/updated/guessed at automatically.
                    continue;
                }

                // DISTINCT_SHARED_ENDPOINT: a legitimate separate board. Skip the
                // endpoint lookup (it's ambiguous by definition) and match by
                // name only, so it is still eligible for insertion.
                $sharedEndpoint = true;
                $findByName->execute(['name' => $r['name']]);
                $existing = $findByName->fetch(\PDO::FETCH_ASSOC) ?: null;
            } else {
                $existing = null;
                if ($r['telnet_host'] !== null) {
                    $findByEndpoint->execute(['host' => $r['telnet_host'], 'port' => $r['telnet_port']]);
                    $existing = $findByEndpoint->fetch(\PDO::FETCH_ASSOC) ?: null;
                }
                if ($existing === null) {
                    $findByName->execute(['name' => $r['name']]);
                    $existing = $findByName->fetch(\PDO::FETCH_ASSOC) ?: null;
                }
            }

            if ($existing === null) {
                $plan['would_add'][] = [
                    'name' => $r['name'],
                    'telnet_host' => $r['telnet_host'],
                    'telnet_port' => $r['telnet_port'],
                    'shared_endpoint' => $sharedEndpoint,
                    'record' => $r,
                ];
                continue;
            }

            if ($this->isTruthy($existing['is_local'])) {
                $plan['protected_local'][] = ['name' => $r['name'], 'existing_id' => (int)$existing['id']];
                continue;
            }

            $changed = $this->recordDiffers($r, $existing);
            if ($changed) {
                $plan['would_update'][] = [
                    'name' => $r['name'],
                    'existing_id' => (int)$existing['id'],
                    'changed_fields' => $changed,
                    'record' => $r,
                ];
            } else {
                $plan['unchanged'][] = ['name' => $r['name'], 'existing_id' => (int)$existing['id']];
            }
        }

        $likelyAliasRows = 0;
        $distinctSharedEndpointRows = 0;
        foreach ($plan['ambiguous_collision'] as $entry) {
            if ($entry['classification'] === 'LIKELY_ALIAS') {
                $likelyAliasRows++;
            } else {
                $distinctSharedEndpointRows++;
            }
        }

        return [
            'counts' => [
                'would_add' => count($plan['would_add']),
                'would_update' => count($plan['would_update']),
                'unchanged' => count($plan['unchanged']),
                'protected_local' => count($plan['protected_local']),
                'ambiguous_collision' => count($plan['ambiguous_collision']),
                'ambiguous_collision_likely_alias' => $likelyAliasRows,
                'ambiguous_collision_distinct_shared_endpoint' => $distinctSharedEndpointRows,
            ],
            'details' => $plan,
        ];
    }

    /**
     * Apply a reconciliation plan built by planReconciliation(): INSERT
     * would_add rows and UPDATE would_update rows, transactionally. Never
     * touches is_local rows (already excluded from would_update by
     * planReconciliation), never deletes, and never inserts/updates
     * LIKELY_ALIAS rows (they never entered would_add/would_update in the
     * first place). Returns the same counts as planReconciliation() plus
     * added/updated/skipped_likely_alias/distinct_shared_endpoint_added.
     */
    public function applyPlan(array $records, string $sourceEdition): array
    {
        if ($this->db === null) {
            throw new \RuntimeException('applyPlan() requires a database connection');
        }

        $plan = $this->planReconciliation($records);

        $insertStmt = $this->db->prepare("
            INSERT INTO bbs_directory
                (name, sysop, location, telnet_host, telnet_port, ssh_port, website, software, notes,
                 source, source_edition, updated_from_source_at, last_seen, status, is_local)
            VALUES
                (:name, :sysop, :location, :telnet_host, :telnet_port, :ssh_port, :website, :software, :notes,
                 'ibbs', :edition, NOW(), NOW(), 'active', FALSE)
        ");
        $updateStmt = $this->db->prepare("
            UPDATE bbs_directory SET
                sysop = COALESCE(:sysop, sysop),
                location = COALESCE(:location, location),
                website = COALESCE(:website, website),
                software = COALESCE(:software, software),
                ssh_port = COALESCE(:ssh_port, ssh_port),
                source = 'ibbs',
                source_edition = :edition,
                updated_from_source_at = NOW(),
                last_seen = NOW(),
                missing_since = NULL,
                updated_at = NOW()
            WHERE id = :id AND is_local = FALSE
        ");

        $added = 0;
        $distinctSharedEndpointAdded = 0;
        $updated = 0;

        $this->db->beginTransaction();
        try {
            foreach ($plan['details']['would_add'] as $entry) {
                $r = $entry['record'];
                $insertStmt->execute([
                    'name' => $r['name'],
                    'sysop' => $r['sysop'],
                    'location' => $r['location'],
                    'telnet_host' => $r['telnet_host'],
                    'telnet_port' => $r['telnet_port'],
                    'ssh_port' => $r['ssh_port'],
                    'website' => $r['website'],
                    'software' => $r['software'],
                    'notes' => $r['notes'],
                    'edition' => $sourceEdition,
                ]);
                $added++;
                if ($entry['shared_endpoint']) {
                    $distinctSharedEndpointAdded++;
                }
            }

            foreach ($plan['details']['would_update'] as $entry) {
                $r = $entry['record'];
                $updateStmt->execute([
                    'sysop' => $r['sysop'],
                    'location' => $r['location'],
                    'website' => $r['website'],
                    'software' => $r['software'],
                    'ssh_port' => $r['ssh_port'],
                    'edition' => $sourceEdition,
                    'id' => $entry['existing_id'],
                ]);
                $updated += $updateStmt->rowCount();
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'added' => $added,
            'distinct_shared_endpoint_added' => $distinctSharedEndpointAdded,
            'updated' => $updated,
            'unchanged' => count($plan['details']['unchanged']),
            'protected_local' => count($plan['details']['protected_local']),
            'skipped_likely_alias' => $plan['counts']['ambiguous_collision_likely_alias'],
            'distinct_shared_endpoint_total' => $plan['counts']['ambiguous_collision_distinct_shared_endpoint'],
        ];
    }

    /**
     * Classify one endpoint-collision group for REPORT/REVIEW purposes only.
     * This never causes a merge — every record in an ambiguous_collision
     * group is still excluded from would_add/would_update regardless of
     * classification.
     *
     * LIKELY_ALIAS requires same endpoint (given, it's the grouping key) +
     * same normalized location + strongly similar normalized name. A
     * location mismatch always yields DISTINCT_SHARED_ENDPOINT, even if the
     * names look alike; location match alone (dissimilar names) also yields
     * DISTINCT_SHARED_ENDPOINT.
     */
    private function classifyCollisionGroup(array $group): array
    {
        $locations = array_map(fn($r) => $this->normalizeLocationForCompare($r['location']), $group);
        $uniqueLocations = array_unique($locations);
        // Only treat as a location "match" when every record in the group
        // has a non-empty, identical normalized location.
        $locationMatch = count($uniqueLocations) === 1 && $uniqueLocations[array_key_first($uniqueLocations)] !== '';

        $names = array_map(fn($r) => $this->normalizeNameForSimilarity($r['name']), $group);
        $nameSimilar = true;
        for ($i = 1; $i < count($names); $i++) {
            if (!$this->namesStronglySimilar($names[0], $names[$i])) {
                $nameSimilar = false;
                break;
            }
        }

        $classification = ($locationMatch && $nameSimilar) ? 'LIKELY_ALIAS' : 'DISTINCT_SHARED_ENDPOINT';

        return [
            'classification' => $classification,
            'location_match' => $locationMatch,
            'name_similar' => $nameSimilar,
        ];
    }

    /**
     * Normalize a name for similarity comparison: lowercase, strip
     * everything but letters/digits, then drop the generic "bbs" token
     * wherever it appears so "BBS Retrocampus" and "Retrocampus BBS"
     * reduce to the same string.
     */
    private function normalizeNameForSimilarity(string $name): string
    {
        $lower = mb_strtolower($name);
        $alnumOnly = preg_replace('/[^a-z0-9]/u', '', $lower) ?? '';
        return str_replace('bbs', '', $alnumOnly);
    }

    /**
     * Two normalized names are "strongly similar" if they're identical, or
     * near-identical by character-similarity ratio. Deliberately strict —
     * this only ever produces a report-only LIKELY_ALIAS tag, never a merge.
     */
    private function namesStronglySimilar(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        similar_text($a, $b, $percent);
        return $percent >= 85.0;
    }

    /**
     * Normalize a location string for equality comparison: lowercase, trim
     * each comma-separated segment, drop empty segments, rejoin. This is
     * NOT a fuzzy/typo-tolerant match — "Beijing" and "Bejing" are treated
     * as different, which only makes the classifier more conservative
     * (fewer LIKELY_ALIAS tags), never less.
     */
    private function normalizeLocationForCompare(?string $location): string
    {
        if ($location === null || trim($location) === '') {
            return '';
        }
        $segments = array_map('trim', explode(',', mb_strtolower($location)));
        $segments = array_filter($segments, fn($s) => $s !== '');
        return implode(',', $segments);
    }

    private function isTruthy($value): bool
    {
        return $value === true || $value === 't' || $value === 1 || $value === '1';
    }

    /**
     * Compare a normalized source record against an existing row's
     * source-owned fields. Returns a list of changed field names.
     */
    private function recordDiffers(array $r, array $existing): array
    {
        $changed = [];
        $fieldMap = [
            'sysop' => 'sysop',
            'location' => 'location',
            'website' => 'website',
            'software' => 'software',
            'ssh_port' => 'ssh_port',
        ];
        foreach ($fieldMap as $recordField => $dbField) {
            $new = $r[$recordField];
            $old = $existing[$dbField];
            if ($new !== null && (string)$new !== (string)($old ?? '')) {
                $changed[] = $dbField;
            }
        }
        return $changed;
    }
}
