#!/usr/bin/env php
<?php
/**
 * IBBS (Telnet BBS Guide) monthly directory sync.
 *
 * By default, discovers and downloads the current official monthly archive
 * from https://www.telnetbbsguide.com/lists/download-list/, validates it,
 * and prints a dry-run reconciliation plan. Pass --apply to actually write.
 * Pass --zip=<path> to use a local archive instead of downloading (manual
 * recovery / testing) — acquisition and reconciliation are separable.
 *
 * On ANY acquisition/validation failure, the directory is left untouched
 * and the script exits non-zero.
 *
 * Usage:
 *   php scripts/ibbs_directory_sync.php [--zip=/path/to/IBBSxxxx.ZIP] [--apply] [--quiet]
 *
 * Cron (see docker/entrypoint.sh): ENABLE_IBBS_DIRECTORY_SYNC / IBBS_DIRECTORY_SYNC_SCHEDULE.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Directory\IbbsImportService;
use BinktermPHP\Database;

$options = getopt('', ['zip:', 'quiet', 'apply']);
$quiet = isset($options['quiet']);
$apply = isset($options['apply']);
$logger = getServerLogger();

$service = new IbbsImportService();
$downloadedTempPath = null;
$zipPath = $options['zip'] ?? null;
$sourceEdition = null;
$sha256 = null;

try {
    if ($zipPath === null) {
        $acquired = $service->downloadCurrentArchive();
        $zipPath = $downloadedTempPath = $acquired['path'];
        $sourceEdition = $acquired['edition'];
        $sha256 = $acquired['sha256'];
    } else {
        $sourceEdition = strtolower(pathinfo($zipPath, PATHINFO_FILENAME));
        $sha256 = hash_file('sha256', $zipPath) ?: null;
    }

    $csv = $service->readCsvFromZip($zipPath);
    $parsed = $service->parseCsv($csv);
} catch (\Throwable $e) {
    $msg = 'IBBS sync FAILED, directory untouched: ' . $e->getMessage();
    fwrite(STDERR, $msg . "\n");
    $logger->error($msg, ['component' => 'ibbs_directory_sync']);
    if ($downloadedTempPath !== null) {
        @unlink($downloadedTempPath);
    }
    exit(1);
}

$stats = $parsed['stats'];

if ($stats['valid_rows'] < IbbsImportService::MIN_PLAUSIBLE_ROWS) {
    $msg = sprintf(
        'IBBS sync REFUSED: only %d valid rows (< %d minimum plausible). Directory untouched.',
        $stats['valid_rows'],
        IbbsImportService::MIN_PLAUSIBLE_ROWS
    );
    fwrite(STDERR, $msg . "\n");
    $logger->error($msg, ['component' => 'ibbs_directory_sync', 'edition' => $sourceEdition]);
    if ($downloadedTempPath !== null) {
        @unlink($downloadedTempPath);
    }
    exit(1);
}

$db = Database::getInstance()->getPdo();
$serviceWithDb = new IbbsImportService($db);

if ($apply) {
    try {
        $result = $serviceWithDb->applyPlan($parsed['records'], $sourceEdition);
    } catch (\Throwable $e) {
        $msg = 'IBBS apply FAILED, transaction rolled back: ' . $e->getMessage();
        fwrite(STDERR, $msg . "\n");
        $logger->error($msg, ['component' => 'ibbs_directory_sync', 'edition' => $sourceEdition]);
        if ($downloadedTempPath !== null) {
            @unlink($downloadedTempPath);
        }
        exit(1);
    }

    $runRecord = array_merge(
        [
            'timestamp' => gmdate('c'),
            'source_url' => 'https://www.telnetbbsguide.com/lists/download-list/',
            'source_edition' => $sourceEdition,
            'archive_sha256' => $sha256,
            'source_rows' => $stats['source_rows'],
            'valid_rows' => $stats['valid_rows'],
            'invalid' => $stats['invalid_count'],
        ],
        $result
    );
    $logger->info('IBBS directory sync applied', array_merge(['component' => 'ibbs_directory_sync'], $runRecord));

    if (!$quiet) {
        echo "=== IBBS Directory Sync — APPLY (edition: {$sourceEdition}) ===\n";
        echo "sha256: {$sha256}\n";
        foreach ($runRecord as $k => $v) {
            echo "{$k}: {$v}\n";
        }
    } else {
        echo json_encode(['run' => $runRecord]) . "\n";
    }

    if ($downloadedTempPath !== null) {
        @unlink($downloadedTempPath);
    }
    exit(0);
}

$plan = $serviceWithDb->planReconciliation($parsed['records']);

if (!$quiet) {
    echo "=== IBBS Directory Sync — DRY RUN (edition: {$sourceEdition}) ===\n";
    echo "zip: {$zipPath}\n";
    echo "sha256: {$sha256}\n";
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
    echo json_encode(['stats' => $stats, 'plan_counts' => $plan['counts'], 'edition' => $sourceEdition, 'sha256' => $sha256]) . "\n";
}

if ($downloadedTempPath !== null) {
    @unlink($downloadedTempPath);
}
exit(0);
