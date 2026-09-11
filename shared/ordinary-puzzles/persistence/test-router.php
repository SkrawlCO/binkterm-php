<?php
require __DIR__ . '/test-bootstrap.php';
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/state') {
    // Avoid unrelated credit processing in this isolated Auth/CSRF/storage fixture.
    session_start();
    $_SESSION['daily_credit_last_check_1'] = time();
    $_SESSION['daily_credit_last_check_2'] = time();
    require __DIR__ . '/api.php';
    return;
}
return false;
