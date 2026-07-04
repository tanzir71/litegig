<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litegig-admin-access-' . bin2hex(random_bytes(6));
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

$checks = 0;

function check_admin_access(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function expect_access_exception(callable $fn, string $label): void {
    global $checks;
    $checks++;
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return;
    }
    fwrite(STDERR, "FAIL: {$label}\n");
    exit(1);
}

function rm_tree_admin_access(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rm_tree_admin_access($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$pdo = db();
$now = now_iso();
$pdo->prepare("INSERT INTO users (email, password_hash, display_name, is_admin, status, created_at) VALUES (?, ?, ?, 1, 'active', ?)")
    ->execute(['seed-admin@example.test', password_hash('seed-pass', PASSWORD_DEFAULT), 'Seed Admin', $now]);
$seedId = (int)$pdo->lastInsertId();

expect_access_exception(fn() => assert_access_audit_payload('user.update_access', '', ['x' => 1], ['y' => 1]), 'access audit requires reason');
expect_access_exception(fn() => assert_access_audit_payload('user.update_access', 'reason', [], ['y' => 1]), 'access audit requires before snapshot');
expect_access_exception(fn() => assert_access_audit_payload('user.update_access', 'reason', ['x' => 1], []), 'access audit requires after snapshot');
expect_access_exception(fn() => assert_access_audit_payload('user.unknown', 'reason', ['x' => 1], ['y' => 1]), 'access audit rejects unknown actions');

$create = create_production_admin_idempotent(
    $seedId,
    'prod-admin@example.test',
    'Production Admin',
    '+15555550123',
    '',
    'Create real production admin before rotating seed access'
);
check_admin_access((bool)$create['created'], 'production admin is created');
check_admin_access((bool)$create['generated_password'] && strlen((string)$create['password']) >= 8, 'blank create-admin password generates temporary password');
$prodId = (int)$create['id'];
$prod = user_by_email('prod-admin@example.test') ?: [];
check_admin_access((int)$prod['is_admin'] === 1 && (string)$prod['status'] === 'active', 'production admin is active admin');
check_admin_access(password_verify((string)$create['password'], (string)$prod['password_hash']), 'generated production admin password works');

$auditCount = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'user.create_admin'")->fetchColumn();
$again = create_production_admin_idempotent(
    $seedId,
    'prod-admin@example.test',
    'Production Admin',
    '+15555550123',
    '',
    'Repeat create-admin safely'
);
check_admin_access(empty($again['changed']), 'repeat create-admin without changes is idempotent');
check_admin_access((int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'user.create_admin'")->fetchColumn() === $auditCount, 'idempotent create-admin does not duplicate audit rows');

$disable = update_user_access_idempotent($prodId, $seedId, false, 'suspended', 'Disable seeded admin after production admin verification');
check_admin_access((bool)$disable['changed'], 'seed admin can be disabled after production admin exists');
$seed = user_by_email('seed-admin@example.test') ?: [];
check_admin_access(!user_is_active($seed), 'disabled seed admin is not active');
check_admin_access(!password_verify('wrong-pass', (string)$seed['password_hash']), 'incorrect seed password fails');
check_admin_access(password_verify('seed-pass', (string)$seed['password_hash']), 'old seed password still matches before explicit reset');

$reset = reset_user_password_with_audit($prodId, $seedId, '', 'Rotate seeded admin password after disabling account');
$seed = user_by_email('seed-admin@example.test') ?: [];
check_admin_access((bool)$reset['generated_password'], 'blank password reset generates temporary password');
check_admin_access(password_verify((string)$reset['password'], (string)$seed['password_hash']), 'generated reset password works');
check_admin_access(!password_verify('seed-pass', (string)$seed['password_hash']), 'old seed password fails after rotation');
check_admin_access(!user_is_active($seed), 'rotated seed account remains disabled');

expect_access_exception(fn() => update_user_access_idempotent($prodId, $prodId, false, 'suspended', 'Disable self'), 'current active admin cannot disable own session');
expect_access_exception(fn() => update_user_access_idempotent($seedId, $prodId, false, 'suspended', 'Disable final admin'), 'last active admin cannot be disabled');

$metaJson = (string)$pdo->query("SELECT meta_json FROM audit_log WHERE action = 'user.update_access' ORDER BY id DESC LIMIT 1")->fetchColumn();
$meta = json_decode($metaJson, true, 512, JSON_THROW_ON_ERROR);
check_admin_access((string)($meta['reason'] ?? '') === 'Disable seeded admin after production admin verification', 'access audit stores reason');
check_admin_access(is_array($meta['before'] ?? null) && ($meta['before']['status'] ?? '') === 'active', 'access audit stores before snapshot');
check_admin_access(is_array($meta['after'] ?? null) && ($meta['after']['status'] ?? '') === 'suspended', 'access audit stores after snapshot');

$resetMetaJson = (string)$pdo->query("SELECT meta_json FROM audit_log WHERE action = 'user.reset_password' ORDER BY id DESC LIMIT 1")->fetchColumn();
$resetMeta = json_decode($resetMetaJson, true, 512, JSON_THROW_ON_ERROR);
check_admin_access(isset($resetMeta['reason'], $resetMeta['before'], $resetMeta['after']), 'password reset audit includes reason before and after');

rm_tree_admin_access($tmp);
echo "Admin access tests passed ({$checks} checks).\n";
