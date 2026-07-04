<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litegig-health-' . bin2hex(random_bytes(6));
@mkdir($tmp . DIRECTORY_SEPARATOR . 'data', 0750, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'uploads', 0750, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'backups', 0750, true);

putenv('LITEGIG_DATA_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'data');
putenv('LITEGIG_DB_PATH=' . $tmp . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'litegig.db');
putenv('LITEGIG_UPLOAD_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'uploads');
putenv('LITEGIG_BACKUP_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'backups');
putenv('LITEGIG_SECURITY_HEADERS=false');

define('LITEGIG_ROOT', $root);

require_once LITEGIG_ROOT . '/app/bootstrap.php';
require_once LITEGIG_ROOT . '/app/database.php';
require_once LITEGIG_ROOT . '/app/http.php';
require_once LITEGIG_ROOT . '/app/models/users.php';
require_once LITEGIG_ROOT . '/app/services/notifications.php';
require_once LITEGIG_ROOT . '/app/services/backups.php';
require_once LITEGIG_ROOT . '/app/views/layout.php';
require_once LITEGIG_ROOT . '/app/controllers/admin.php';

$checks = 0;

function check_health(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function rm_tree_health(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rm_tree_health($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$pdo = db();
$first = health_snapshot();
$firstData = $first['data'];
check_health((int)$first['http_status'] === 200, 'health liveness is HTTP 200 when database responds');
check_health((bool)$firstData['ok'], 'health reports ok for live database');
check_health(!(bool)$firstData['ready'], 'fresh unlaunched app is not ready without active admin and backup');
check_health(($firstData['checks']['active_admin']['status'] ?? '') === 'warn', 'health warns when no active admin exists');
check_health(($firstData['checks']['backup']['status'] ?? '') === 'warn', 'health warns when no backup exists');
check_health(array_key_exists('queued_notifications', $firstData), 'health includes queued notification count');
check_health(array_key_exists('failed_notifications', $firstData), 'health includes failed notification count');

$now = now_iso();
$pdo->prepare("INSERT INTO users (email, password_hash, display_name, is_admin, status, created_at) VALUES (?, ?, ?, 1, 'active', ?)")
    ->execute(['health-admin@example.test', password_hash('health-test', PASSWORD_DEFAULT), 'Health Admin', $now]);
$backup = create_sqlite_backup();
check_health((bool)$backup['verified'], 'health fixture creates verified backup');

$ready = health_snapshot();
$readyData = $ready['data'];
check_health((bool)$readyData['ready'], 'health readiness passes when active admin and verified backup exist');
check_health(($readyData['checks']['db_integrity']['status'] ?? '') === 'pass', 'health verifies SQLite integrity');
check_health(($readyData['checks']['migration_ledger']['status'] ?? '') === 'pass', 'health verifies migration ledger');
check_health(($readyData['checks']['active_admin']['status'] ?? '') === 'pass', 'health verifies active admin');
check_health(($readyData['checks']['backup']['status'] ?? '') === 'pass', 'health verifies latest backup');
check_health((string)$readyData['latest_backup'] === basename((string)$backup['path']), 'health reports latest backup filename only');
check_health((bool)$readyData['latest_backup_verified'], 'health reports latest backup verification');

rm_tree_health($tmp);
echo "Health tests passed ({$checks} checks).\n";
