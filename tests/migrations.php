<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litegig-migrations-' . bin2hex(random_bytes(6));
@mkdir($tmp . DIRECTORY_SEPARATOR . 'data', 0750, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'uploads', 0750, true);

putenv('LITEGIG_DATA_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'data');
putenv('LITEGIG_UPLOAD_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'uploads');
putenv('LITEGIG_BACKUP_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'backups');
putenv('LITEGIG_SECURITY_HEADERS=false');

define('LITEGIG_ROOT', $root);

require_once LITEGIG_ROOT . '/app/bootstrap.php';
require_once LITEGIG_ROOT . '/app/database.php';
require_once LITEGIG_ROOT . '/app/migrations.php';

set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, "FAIL: unhandled migration test exception: " . $e->getMessage() . "\n");
    exit(1);
});

$checks = 0;

function check_migration(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function rm_tree_migration(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rm_tree_migration($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$pdo = db();
$definitions = migration_definitions();
check_migration(isset($definitions['2026-07-04-bootstrap']), 'bootstrap migration is registered');
check_migration(in_array('DROP TABLE IF EXISTS requests', $definitions['2026-07-04-bootstrap']['down'], true), 'bootstrap migration has rollback SQL');

$status = migration_status($pdo);
$bootstrap = array_values(array_filter($status, fn(array $row): bool => (string)$row['version'] === '2026-07-04-bootstrap'))[0] ?? null;
check_migration(is_array($bootstrap), 'bootstrap migration appears in status');
check_migration((string)$bootstrap['state'] === 'applied', 'bootstrap migration is applied after initialization');
check_migration(apply_pending_migrations($pdo) === [], 'no pending migrations after initialization');

$output = [];
$code = 0;
exec(PHP_BINARY . ' ' . escapeshellarg($root . '/tools/migrate.php') . ' status 2>&1', $output, $code);
check_migration($code === 0, 'migration status CLI exits successfully');
check_migration(str_contains(implode("\n", $output), '2026-07-04-bootstrap'), 'migration status CLI prints bootstrap version');

$blocked = false;
try {
    rollback_migration($pdo, '2026-07-04-bootstrap', false);
} catch (RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), '--yes-destroy-data');
}
check_migration($blocked, 'destructive rollback requires explicit confirmation');

check_migration(table_exists($pdo, 'requests'), 'requests table exists before confirmed rollback');
rollback_migration($pdo, '2026-07-04-bootstrap', true);
check_migration(!table_exists($pdo, 'requests'), 'confirmed rollback removes operational tables');

rm_tree_migration($tmp);
echo "Migration tests passed ({$checks} checks).\n";
