<?php
/** Test-only JSONL database fixture; never uses application database defaults. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('TATHAM_TEST_DSN') !== 'pgsql:host=127.0.0.1;dbname=tatham_slice1') exit(2);
require dirname(__DIR__, 3) . '/vendor/autoload.php';
require __DIR__ . '/Storage.php';
$db = new PDO(getenv('TATHAM_TEST_DSN'), 'postgres', 'slice1-test-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($db->query('SELECT current_database()')->fetchColumn() !== 'tatham_slice1') exit(2);
$service = new \BreakLock\Storage($db, (int)($argv[1] ?? 1));
while (($line = fgets(STDIN)) !== false) {
    try {
        $input = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        $result = $service->request($input);
    } catch (Throwable $error) { $result = ['success' => false, 'reason' => 'invalid']; }
    echo json_encode($result), "\n"; flush();
}
