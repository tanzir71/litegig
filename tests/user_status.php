<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litegig-user-status-' . bin2hex(random_bytes(6));
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

$checks = 0;

function check_user_status(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function rm_tree_user_status(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rm_tree_user_status($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function user_column_exists(PDO $pdo, string $column): bool {
    foreach ($pdo->query('PRAGMA table_info(users)')->fetchAll() as $row) {
        if ((string)$row['name'] === $column) return true;
    }
    return false;
}

$pdo = db();
$now = now_iso();
check_user_status(user_column_exists($pdo, 'status'), 'users table has account status column');
check_user_status(normalize_user_status('active') === 'active', 'active status normalizes');
check_user_status(normalize_user_status('suspended') === 'suspended', 'suspended status normalizes');
check_user_status(normalize_user_status('weird') === 'active', 'unknown status falls back to active');

$pdo->prepare("INSERT INTO users (email, password_hash, display_name, is_admin, notify_email_enabled, notify_sms_enabled, phone, created_at) VALUES (?, ?, ?, 0, 1, 1, ?, ?)")
    ->execute(['active@example.test', password_hash('status-test', PASSWORD_DEFAULT), 'Active User', '+15555550100', $now]);
$activeId = (int)$pdo->lastInsertId();
$active = user_by_id($activeId) ?: [];
check_user_status((string)$active['status'] === 'active', 'new users default to active status');
check_user_status(user_is_active($active), 'active user passes active gate');
check_user_status(notification_channel_enabled_for_user($active, 'email', 'request_accepted'), 'active user can receive enabled email notifications');

$pdo->prepare("INSERT INTO users (email, password_hash, display_name, status, is_admin, notify_email_enabled, notify_sms_enabled, phone, created_at) VALUES (?, ?, ?, 'suspended', 0, 1, 1, ?, ?)")
    ->execute(['suspended@example.test', password_hash('status-test', PASSWORD_DEFAULT), 'Suspended User', '+15555550200', $now]);
$suspendedId = (int)$pdo->lastInsertId();
$suspended = user_by_id($suspendedId) ?: [];
check_user_status((string)$suspended['status'] === 'suspended', 'suspended status is returned by user lookup');
check_user_status(!user_is_active($suspended), 'suspended user fails active gate');
check_user_status(!notification_channel_enabled_for_user($suspended, 'email', 'request_accepted'), 'suspended user cannot receive email notifications');
check_user_status(!notification_channel_enabled_for_user($suspended, 'sms', 'delivery_otp'), 'suspended user cannot receive SMS notifications');

$_SESSION['uid'] = $suspendedId;
$sessionUser = current_user(true) ?: [];
check_user_status((string)$sessionUser['status'] === 'suspended', 'current user lookup includes suspended status for session gate');
check_user_status(current_user() === null, 'default current user lookup hides suspended accounts');
unset($_SESSION['uid']);

$adminController = file_get_contents(LITEGIG_ROOT . '/app/controllers/admin.php') ?: '';
$userModel = file_get_contents(LITEGIG_ROOT . '/app/models/users.php') ?: '';
$authController = file_get_contents(LITEGIG_ROOT . '/app/controllers/auth.php') ?: '';
check_user_status(str_contains($userModel, 'active_admin_count') && str_contains($userModel, "status = 'active'"), 'admin guard counts active admins');
check_user_status(str_contains($adminController, 'update_user_access_idempotent'), 'admin controller uses access guard operation');
check_user_status(str_contains($authController, 'login_blocked_suspended'), 'login flow audits suspended-account blocks');

rm_tree_user_status($tmp);
echo "User status tests passed ({$checks} checks).\n";
