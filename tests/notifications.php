<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litegig-notifications-' . bin2hex(random_bytes(6));
@mkdir($tmp . DIRECTORY_SEPARATOR . 'data', 0750, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'uploads', 0750, true);

putenv('LITEGIG_DATA_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'data');
putenv('LITEGIG_UPLOAD_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'uploads');
putenv('LITEGIG_EMAIL_ENABLED=false');
putenv('LITEGIG_EMAIL_FROM=no-reply@example.test');
putenv('LITEGIG_SMS_ENABLED=false');
putenv('LITEGIG_SECURITY_HEADERS=false');

define('LITEGIG_ROOT', $root);

require_once LITEGIG_ROOT . '/app/bootstrap.php';
require_once LITEGIG_ROOT . '/app/database.php';
require_once LITEGIG_ROOT . '/app/http.php';
require_once LITEGIG_ROOT . '/app/models/task_types.php';
require_once LITEGIG_ROOT . '/app/models/requests.php';
require_once LITEGIG_ROOT . '/app/models/users.php';
require_once LITEGIG_ROOT . '/app/services/notifications.php';
require_once LITEGIG_ROOT . '/app/views/layout.php';
require_once LITEGIG_ROOT . '/app/controllers/requests.php';

$checks = 0;

function check_true(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function rm_tree_notifications(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rm_tree_notifications($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$pdo = db();
$now = now_iso();
$insertUser = $pdo->prepare("INSERT INTO users (email, password_hash, display_name, phone, is_admin, notify_email_enabled, notify_sms_enabled, created_at) VALUES (?, ?, ?, ?, 0, ?, ?, ?)");
$insertUser->execute(['requester@example.test', password_hash('notifications', PASSWORD_DEFAULT), 'Notify Requester', '+15550001001', 1, 1, $now]);
$requester = user_by_id((int)$pdo->lastInsertId()) ?: [];
$insertUser->execute(['runner@example.test', password_hash('notifications', PASSWORD_DEFAULT), 'Notify Runner', '+15550001002', 1, 1, $now]);
$runner = user_by_id((int)$pdo->lastInsertId()) ?: [];

$taskTypeId = (int)$pdo->query("SELECT id FROM task_types ORDER BY id LIMIT 1")->fetchColumn();
$stmt = $pdo->prepare("INSERT INTO requests
    (requester_id, task_type_id, title, description, price_cents, fee_cents, status, runner_id, metadata, created_at, updated_at)
    VALUES (?, ?, 'Notification fixture', 'Private detail', 1200, 96, 'accepted', ?, '{}', ?, ?)");
$stmt->execute([(int)$requester['id'], $taskTypeId, (int)$runner['id'], $now, $now]);
$request = fetch_request_full((int)$pdo->lastInsertId()) ?: [];

queue_request_notifications('request_accepted', $request, $runner);
$queued = $pdo->query("SELECT * FROM notifications WHERE channel = 'email' AND template = 'request_accepted' ORDER BY id DESC LIMIT 1")->fetch();
check_true($queued && (int)$queued['user_id'] === (int)$requester['id'], 'accepted notification queued for requester');
check_true((string)$queued['status'] === 'queued', 'enabled requester notification starts queued');
$queuedSms = $pdo->query("SELECT * FROM notifications WHERE channel = 'sms' AND template = 'request_accepted' ORDER BY id DESC LIMIT 1")->fetch();
check_true($queuedSms && (int)$queuedSms['user_id'] === (int)$requester['id'], 'accepted SMS queued for requester');

$summary = process_queued_notifications(10);
check_true($summary['logged'] === 2, 'email/SMS disabled queues are logged-only');
$logged = $pdo->query("SELECT * FROM notifications WHERE id = " . (int)$queued['id'])->fetch();
check_true((string)$logged['status'] === 'logged', 'logged-only notification status persisted');

$pdo->prepare("UPDATE users SET notify_email_enabled = 0, notify_sms_enabled = 0 WHERE id = ?")->execute([(int)$requester['id']]);
$request = fetch_request_full((int)$request['id']) ?: [];
queue_request_notifications('request_delivered', $request, $runner);
$skipped = $pdo->query("SELECT * FROM notifications WHERE channel = 'email' AND template = 'request_delivered' ORDER BY id DESC LIMIT 1")->fetch();
check_true((string)$skipped['status'] === 'skipped', 'disabled requester preference skips queued delivery email');
$skippedSms = $pdo->query("SELECT * FROM notifications WHERE channel = 'sms' AND template = 'request_delivered' ORDER BY id DESC LIMIT 1")->fetch();
check_true((string)$skippedSms['status'] === 'skipped', 'disabled requester preference skips queued delivery SMS');

queue_request_notifications('request_comment', $request, $requester, 'Can you confirm timing?');
$comment = $pdo->query("SELECT * FROM notifications WHERE channel = 'email' AND template = 'request_comment' ORDER BY id DESC LIMIT 1")->fetch();
check_true((int)$comment['user_id'] === (int)$runner['id'], 'comment notification targets counterpart');
check_true(str_contains((string)$comment['payload_json'], 'Can you confirm timing?'), 'comment note stored in payload');

queue_request_notifications('delivery_otp', $request, $requester, '', ['delivery_otp' => '123456']);
$otp = $pdo->query("SELECT * FROM notifications WHERE channel = 'sms' AND template = 'delivery_otp' ORDER BY id DESC LIMIT 1")->fetch();
check_true((int)$otp['user_id'] === (int)$requester['id'], 'delivery OTP targets requester even when requester generated it');
check_true(str_contains((string)$otp['payload_json'], '123456'), 'delivery OTP payload carries one-time code for outbound delivery');

$pdo->prepare("UPDATE users SET notify_events_json = ? WHERE id = ?")->execute([json_encode(['comment' => false], JSON_UNESCAPED_SLASHES), (int)$runner['id']]);
queue_request_notifications('request_comment', $request, $requester, 'Muted event');
$muted = $pdo->query("SELECT * FROM notifications WHERE channel = 'email' AND template = 'request_comment' ORDER BY id DESC LIMIT 1")->fetch();
check_true((string)$muted['status'] === 'skipped', 'per-event preference skips muted comment email');

rm_tree_notifications($tmp);
echo "Notification tests passed ({$checks} checks).\n";
