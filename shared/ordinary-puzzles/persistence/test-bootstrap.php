<?php
/** Test only: inject an explicit disposable PDO; never reads application DB configuration. */
if (getenv('TATHAM_TEST_DSN') !== 'pgsql:host=127.0.0.1;dbname=tatham_slice1') exit(2);
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
$db = new PDO(getenv('TATHAM_TEST_DSN'), 'postgres', 'slice1-test-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($db->query('SELECT current_database()')->fetchColumn() !== 'tatham_slice1') exit(2);
$r = new ReflectionClass(\BinktermPHP\Database::class);
$instance = $r->newInstanceWithoutConstructor();
$r->getProperty('pdo')->setValue($instance, $db);
$r->getProperty('instance')->setValue(null, $instance);
