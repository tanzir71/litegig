<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litegig-production-audit-' . bin2hex(random_bytes(6));
@mkdir($tmp . DIRECTORY_SEPARATOR . 'data', 0750, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'uploads', 0750, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'backups', 0750, true);

$env = [
    'LITEGIG_DATA_DIR' => $tmp . DIRECTORY_SEPARATOR . 'data',
    'LITEGIG_DB_PATH' => $tmp . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'litegig.db',
    'LITEGIG_UPLOAD_DIR' => $tmp . DIRECTORY_SEPARATOR . 'uploads',
    'LITEGIG_BACKUP_DIR' => $tmp . DIRECTORY_SEPARATOR . 'backups',
    'LITEGIG_LOG_PATH' => $tmp . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'litegig_error.log',
    'LITEGIG_CRON_TOKEN' => 'production-audit-token-1234567890abcdef',
    'LITEGIG_HTTP_CRON_ENABLED' => 'false',
    'LITEGIG_SECURITY_HEADERS' => 'true',
    'LITEGIG_SAMPLE_DATA_ENABLED' => 'false',
];
foreach ($env as $key => $value) {
    putenv($key . '=' . $value);
}

define('LITEGIG_ROOT', $root);

require_once LITEGIG_ROOT . '/app/bootstrap.php';
require_once LITEGIG_ROOT . '/app/database.php';
require_once LITEGIG_ROOT . '/app/services/backups.php';

$checks = 0;

function check_audit(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function rm_tree_audit(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rm_tree_audit($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function run_production_audit(array $baseEnv, array $overrides = []): array {
    $parts = [];
    foreach (array_merge($baseEnv, $overrides) as $key => $value) {
        $parts[] = $key . '=' . escapeshellarg((string)$value);
    }
    $cmd = implode(' ', $parts) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(LITEGIG_ROOT . '/tools/production_audit.php') . ' 2>&1';
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    return [$code, implode("\n", $output)];
}

$pdo = db();
$now = now_iso();
$pdo->prepare("INSERT INTO users (email, password_hash, display_name, is_admin, created_at) VALUES (?, ?, ?, 1, ?)")
    ->execute(['production-admin@example.test', password_hash('production-audit', PASSWORD_DEFAULT), 'Production Admin', $now]);
$adminId = (int)$pdo->lastInsertId();
$backup = create_sqlite_backup();
check_audit((bool)$backup['verified'], 'production audit fixture creates a verified .db backup');

[$code, $output] = run_production_audit($env);
check_audit($code === 0, 'production audit passes launch-safe baseline with warnings');
check_audit(str_contains($output, '[PASS] Sample-data loader is disabled'), 'production audit confirms sample loader is disabled');
check_audit(str_contains($output, '[PASS] Seeded sample accounts are absent'), 'production audit confirms seeded accounts are absent');
check_audit(str_contains($output, '[PASS] Latest SQLite backup is verified'), 'production audit recognizes verified LiteGig .db backups');

$pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$adminId]);
[$suspendedAdminCode, $suspendedAdminOutput] = run_production_audit($env);
check_audit($suspendedAdminCode === 1, 'production audit fails when only admin is suspended');
check_audit(str_contains($suspendedAdminOutput, '[FAIL] At least one active admin account exists'), 'active admin failure is reported');
$pdo->prepare("UPDATE users SET status = 'active' WHERE email = ?")->execute(['production-admin@example.test']);

[$badSampleCode, $badSampleOutput] = run_production_audit($env, ['LITEGIG_SAMPLE_DATA_ENABLED' => 'true']);
check_audit($badSampleCode === 1, 'production audit fails when sample loader is enabled');
check_audit(str_contains($badSampleOutput, '[FAIL] Sample-data loader is disabled'), 'sample loader failure is reported');
check_audit(str_contains($badSampleOutput, 'LITEGIG_SAMPLE_DATA_ENABLED=false'), 'sample loader failure includes production remediation');

[$badTokenCode, $badTokenOutput] = run_production_audit($env, [
    'LITEGIG_HTTP_CRON_ENABLED' => 'true',
    'LITEGIG_CRON_TOKEN' => 'change-me-long-random-token',
]);
check_audit($badTokenCode === 1, 'production audit fails placeholder cron token');
check_audit(str_contains($badTokenOutput, '[FAIL] Cron token is production-strength'), 'cron token failure is reported');

$pdo->prepare("INSERT INTO users (email, password_hash, display_name, is_admin, created_at) VALUES (?, ?, ?, 0, ?)")
    ->execute(['requester@example.test', password_hash('rotated-random', PASSWORD_DEFAULT), 'Sample Requester', $now]);
[$sampleAccountCode, $sampleAccountOutput] = run_production_audit($env);
check_audit($sampleAccountCode === 1, 'production audit fails when seeded sample account remains');
check_audit(str_contains($sampleAccountOutput, '[FAIL] Seeded sample accounts are absent'), 'seeded account failure is reported');

check_audit(is_file($env['LITEGIG_DATA_DIR'] . DIRECTORY_SEPARATOR . '.htaccess'), 'data directory deny file is created');
check_audit(is_file($env['LITEGIG_BACKUP_DIR'] . DIRECTORY_SEPARATOR . '.htaccess'), 'backup directory deny file is created');
check_audit(is_file($env['LITEGIG_UPLOAD_DIR'] . DIRECTORY_SEPARATOR . '.htaccess'), 'upload directory deny file is created');

rm_tree_audit($tmp);
echo "Production audit tests passed ({$checks} checks).\n";
