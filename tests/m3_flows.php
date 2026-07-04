<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litegig-m3-' . bin2hex(random_bytes(6));
@mkdir($tmp . DIRECTORY_SEPARATOR . 'data', 0750, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'uploads', 0750, true);

putenv('LITEGIG_DATA_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'data');
putenv('LITEGIG_UPLOAD_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'uploads');
putenv('LITEGIG_PAYMENT_GATEWAY_ENABLED=true');
putenv('LITEGIG_PAYMENT_GATEWAY_WEBHOOK_SECRET=test-secret');
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

function check_m3(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function rm_tree_m3(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rm_tree_m3($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$pdo = db();
$now = now_iso();
$insertUser = $pdo->prepare("INSERT INTO users (email, password_hash, display_name, phone, is_admin, notify_email_enabled, notify_sms_enabled, created_at) VALUES (?, ?, ?, ?, 0, 1, 1, ?)");
$insertUser->execute(['requester@example.test', password_hash('m3-test', PASSWORD_DEFAULT), 'M3 Requester', '+15550003001', $now]);
$requester = user_by_id((int)$pdo->lastInsertId()) ?: [];
$insertUser->execute(['runner@example.test', password_hash('m3-test', PASSWORD_DEFAULT), 'M3 Runner', '+15550003002', $now]);
$runner = user_by_id((int)$pdo->lastInsertId()) ?: [];

$taskTypeId = (int)$pdo->query("SELECT id FROM task_types ORDER BY id LIMIT 1")->fetchColumn();
$stmt = $pdo->prepare("INSERT INTO requests
    (requester_id, task_type_id, title, description, price_cents, fee_cents, status, runner_id, metadata, created_at, updated_at)
    VALUES (?, ?, 'M3 fixture', 'OTP and payment fixture', 2200, 176, 'accepted', ?, '{}', ?, ?)");
$stmt->execute([(int)$requester['id'], $taskTypeId, (int)$runner['id'], $now, $now]);
$requestId = (int)$pdo->lastInsertId();

$otp = create_delivery_otp($requestId, (int)$requester['id']);
check_m3(is_array($otp) && preg_match('/^\d{6}$/', (string)$otp['code']) === 1, 'delivery OTP is generated as six digits');
$request = fetch_request_full($requestId) ?: [];
check_m3((string)($request['delivery_otp_hash'] ?? '') !== (string)$otp['code'], 'delivery OTP is stored as a hash');
check_m3(verify_delivery_otp_for_request($request, '000000') === false, 'wrong delivery OTP is rejected');
check_m3(verify_delivery_otp_for_request($request, (string)$otp['code']) === true, 'correct delivery OTP is accepted');
mark_delivery_otp_verified($requestId);
$request = fetch_request_full($requestId) ?: [];
check_m3((string)($request['delivery_otp_verified_at'] ?? '') !== '', 'delivery OTP verification timestamp is recorded');

$payment = record_gateway_payment($request);
check_m3((string)$payment['method'] === 'gateway', 'gateway payment uses gateway method');
check_m3((string)$payment['status'] === 'pending', 'gateway payment starts pending');
check_m3(str_starts_with((string)$payment['gateway_reference'], 'lgw_'), 'gateway payment gets a reference');

$payload = ['event_id' => 'evt_1', 'reference' => (string)$payment['gateway_reference'], 'status' => 'confirmed', 'amount_cents' => 2200];
check_m3(record_payment_webhook_event('evt_1', (string)$payment['gateway_reference'], 'confirmed', $payload) === true, 'first webhook event is recorded');
check_m3(record_payment_webhook_event('evt_1', (string)$payment['gateway_reference'], 'confirmed', $payload) === false, 'duplicate webhook event is ignored');
update_gateway_payment_status((int)$payment['id'], 'confirmed', 'evt_1', $payload);
$confirmed = payment_for_request($requestId) ?: [];
check_m3((string)$confirmed['status'] === 'confirmed', 'gateway payment can be marked confirmed');

$manual = record_manual_payment($request, (int)$requester['id']);
check_m3((string)$manual['method'] === 'manual' && (string)$manual['status'] === 'confirmed', 'manual confirmation can supersede pending or confirmed gateway records');

rm_tree_m3($tmp);
echo "M3 flow tests passed ({$checks} checks).\n";
