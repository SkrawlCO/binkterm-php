#!/usr/bin/env php
<?php
/**
 * IBBS (Telnet BBS Guide) directory sync — DRY-RUN ONLY in this slice.
 *
 * Reads a local IBBS ZIP archive (as published at
 * https://www.telnetbbsguide.com/lists/download-list/), parses bbslist.csv,
 * and prints a reconciliation plan against the live bbs_directory table.
 * No writes are ever issued by this script in its current form — download
 * acquisition and "apply" are not implemented yet.
 *
 * Usage: php scripts/ibbs_directory_sync.php --zip=/path/to/IBBSxxxx.ZIP [--quiet]
 *
 * NOT wired into cron. Future slices will add ENABLE_IBBS_DIRECTORY_SYNC /
 * IBBS_DIRECTORY_SYNC_SCHEDULE to docker/entrypoint.sh once apply exists.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Directory\IbbsImportService;
use BinktermPHP\Database;

$options = getopt('', ['zip:', 'quiet', 'apply']);
$quiet = isset($options['quiet']);
$apply = isset($options['apply']);

if (empty($options['zip'])) {
    fwrite(STDERR, "Usage: php scripts/ibbs_directory_sync.php --zip=/path/to/IBBSxxxx.ZIP [--quiet]\n");
    exit(1);
}

$zipPath = $options['zip'];

try {
    $service = new IbbsImportService();
    $csv = $service->readCsvFromZip($zipPath);
    $parsed = $service->parseCsv($csv);
} catch (\Throwable $e) {
    fwrite(STDERR, 'IBBS sync FAILED (no writes issued): ' . $e->getMessage() . "\n");
    exit(1);
}

$stats = $parsed['stats'];

if ($stats['valid_rows'] < IbbsImportService::MIN_PLAUSIBLE_ROWS) {
    fwrite(STDERR, sprintf(
        "IBBS sync REFUSED: only %d valid rows (< %d minimum plausible for this source). No writes issued.\n",
        $stats['valid_rows'],
        IbbsImportService::MIN_PLAUSIBLE_ROWS
    ));
    exit(1);
}

$db = Database::getInstance()->getPdo();
$serviceWithDb = new IbbsImportService($db);

// Derive a source edition label from the ZIP filename, e.g. IBBS0926.ZIP -> ibbs0926.
$sourceEdition = strtolower(pathinfo($zipPath, PATHINFO_FILENAME));

if ($apply) {
    try {
        $result = $serviceWithDb->applyPlan($parsed['records'], $sourceEdition);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'IBBS APPLY FAILED, transaction rolled back: ' . $e->getMessage() . "\n");
        exit(1);
    }

    if (!$quiet) {
        echo "=== IBBS Directory Sync — APPLY (edition: {$sourceEdition}) ===\n";
        echo "ZIP: {$zipPath}\n";
        echo "source_rows: {$stats['source_rows']}\n";
        echo "valid_rows: {$stats['valid_rows']}\n";
        foreach ($result as $k => $v) {
            echo "{$k}: {$v}\n";
        }
    } else {
        echo json_encode(['stats' => $stats, 'apply_result' => $result]) . "\n";
    }
    exit(0);
}

$plan = $serviceWithDb->planReconciliation($parsed['records']);

if (!$quiet) {
    echo "=== IBBS Directory Sync — DRY RUN ===\n";
    echo "ZIP: {$zipPath}\n";
    echo "source_rows: {$stats['source_rows']}\n";
    echo "valid_rows: {$stats['valid_rows']}\n";
    echo "invalid: {$stats['invalid_count']}\n";
    echo "missing_telnet_endpoint: {$stats['missing_telnet_endpoint_count']}\n";
    echo "invalid_port: {$stats['invalid_port_count']}\n";
    echo "--- reconciliation plan (dry run, no writes) ---\n";
    foreach ($plan['counts'] as $k => $v) {
        echo "{$k}: {$v}\n";
    }
    echo "--- ambiguous_collision groups (report-only classification, never auto-merged) ---\n";
    $seen = [];
    foreach ($plan['details']['ambiguous_collision'] as $entry) {
        $groupKey = $entry['endpoint'] . '|' . implode(',', $entry['colliding_names']);
        if (isset($seen[$groupKey])) {
            continue;
        }
        $seen[$groupKey] = true;
        echo sprintf(
            "  [%s] %s => %s (location_match=%s, name_similar=%s)\n",
            $entry['classification'],
            $entry['endpoint'],
            implode(' / ', $entry['colliding_names']),
            $entry['location_match'] ? 'yes' : 'no',
            $entry['name_similar'] ? 'yes' : 'no'
        );
    }
} else {
    echo json_encode(['stats' => $stats, 'plan_counts' => $plan['counts']]) . "\n";
}

exit(0);
