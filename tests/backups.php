<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litegig-backups-' . bin2hex(random_bytes(6));
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
require_once LITEGIG_ROOT . '/app/services/backups.php';

$checks = 0;

function check_backup(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function rm_tree_backup(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rm_tree_backup($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$pdo = db();
$now = now_iso();
$pdo->prepare("INSERT INTO users (email, password_hash, display_name, is_admin, created_at) VALUES (?, ?, ?, 1, ?)")
    ->execute(['backup-admin@example.test', password_hash('backup-test', PASSWORD_DEFAULT), 'Backup Admin', $now]);
$userId = (int)$pdo->lastInsertId();
$taskTypeId = (int)$pdo->query("SELECT id FROM task_types ORDER BY id LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO requests (requester_id, task_type_id, code, title, description, price_cents, fee_cents, status, metadata, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, 1200, 96, 'new', '{}', ?, ?)")
    ->execute([$userId, $taskTypeId, 'LG-BACKUP', 'Backup fixture', 'Restore this row from backup.', $now, $now]);

$result = create_sqlite_backup();
check_backup((bool)$result['ok'], 'backup creation returns ok');
check_backup((bool)$result['verified'], 'backup is verified after creation');
check_backup(is_file((string)$result['path']), 'backup file exists');

$verify = verify_sqlite_backup_file((string)$result['path']);
check_backup((bool)$verify['ok'], 'standalone backup verification succeeds');

$restore = new PDO('sqlite:' . (string)$result['path'], null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
check_backup((string)$restore->query("PRAGMA integrity_check")->fetchColumn() === 'ok', 'restored database integrity check is ok');
$restoredTitle = (string)$restore->query("SELECT title FROM requests WHERE code='LG-BACKUP'")->fetchColumn();
check_backup($restoredTitle === 'Backup fixture', 'restored database contains operational request row');
$migrationCount = (int)$restore->query("SELECT COUNT(*) FROM schema_migrations WHERE version='2026-07-04-bootstrap'")->fetchColumn();
check_backup($migrationCount === 1, 'restored database contains migration ledger');

$output = [];
$code = 0;
exec(PHP_BINARY . ' ' . escapeshellarg($root . '/litegig.php') . ' action=cron_backup 2>&1', $output, $code);
$cronOutput = implode("\n", $output);
check_backup($code === 0, 'cron backup command exits successfully');
check_backup(str_contains($cronOutput, 'OK backup=') && str_contains($cronOutput, 'verified=1'), 'cron backup command reports verified backup');

rm_tree_backup($tmp);
echo "Backup restore tests passed ({$checks} checks).\n";
