<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litegig-authz-' . bin2hex(random_bytes(6));
@mkdir($tmp . DIRECTORY_SEPARATOR . 'data', 0750, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'uploads', 0750, true);

putenv('LITEGIG_DATA_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'data');
putenv('LITEGIG_UPLOAD_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'uploads');
putenv('LITEGIG_SECURITY_HEADERS=false');

define('LITEGIG_ROOT', $root);

require_once LITEGIG_ROOT . '/app/bootstrap.php';
require_once LITEGIG_ROOT . '/app/database.php';
require_once LITEGIG_ROOT . '/app/http.php';
require_once LITEGIG_ROOT . '/app/models/task_types.php';
require_once LITEGIG_ROOT . '/app/models/requests.php';
require_once LITEGIG_ROOT . '/app/models/users.php';
require_once LITEGIG_ROOT . '/app/views/layout.php';
require_once LITEGIG_ROOT . '/app/controllers/requests.php';

$checks = 0;

function check_same(bool $expected, bool $actual, string $label): void {
    global $checks;
    $checks++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$label}\n");
        fwrite(STDERR, 'Expected ' . ($expected ? 'allow' : 'deny') . ', got ' . ($actual ? 'allow' : 'deny') . ".\n");
        exit(1);
    }
}

function check_contains(array $ids, int $id, string $label): void {
    global $checks;
    $checks++;
    if (!in_array($id, $ids, true)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        fwrite(STDERR, "Expected id {$id} in [" . implode(',', $ids) . "].\n");
        exit(1);
    }
}

function check_not_contains(array $ids, int $id, string $label): void {
    global $checks;
    $checks++;
    if (in_array($id, $ids, true)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        fwrite(STDERR, "Did not expect id {$id} in [" . implode(',', $ids) . "].\n");
        exit(1);
    }
}

function check_no_key(array $data, string $key, string $label): void {
    global $checks;
    $checks++;
    if (array_key_exists($key, $data)) {
        fwrite(STDERR, "FAIL: {$label}\n");
        fwrite(STDERR, "Unexpected public key {$key}.\n");
        exit(1);
    }
}

function rm_tree(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rm_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$pdo = db();
$now = now_iso();

$insertUser = $pdo->prepare("INSERT INTO users (email, password_hash, display_name, is_admin, created_at) VALUES (?, ?, ?, ?, ?)");
$makeUser = function (string $email, string $name, bool $admin = false) use ($pdo, $insertUser, $now): array {
    $insertUser->execute([$email, password_hash('authz-test', PASSWORD_DEFAULT), $name, $admin ? 1 : 0, $now]);
    return user_by_id((int)$pdo->lastInsertId()) ?: [];
};

$admin = $makeUser('admin@example.test', 'Matrix Admin', true);
$requester = $makeUser('requester@example.test', 'Matrix Requester');
$runner = $makeUser('runner@example.test', 'Matrix Runner');
$stranger = $makeUser('stranger@example.test', 'Matrix Stranger');
$other = $makeUser('other@example.test', 'Matrix Other');

$taskTypeId = (int)$pdo->query("SELECT id FROM task_types ORDER BY id LIMIT 1")->fetchColumn();
$insertRequest = $pdo->prepare("INSERT INTO requests
    (requester_id, task_type_id, title, description, price_cents, fee_cents, status, runner_id, metadata, created_at, updated_at)
    VALUES (?, ?, ?, ?, 1500, 120, ?, ?, ?, ?, ?)");
$makeRequest = function (int $requesterId, ?int $runnerId, string $status, string $title) use ($pdo, $insertRequest, $taskTypeId, $now): array {
    $insertRequest->execute([
        $requesterId,
        $taskTypeId,
        $title,
        'Contains private fixture details that public summaries must not expose.',
        $status,
        $runnerId,
        json_encode(['private_note' => 'redact me'], JSON_UNESCAPED_SLASHES),
        $now,
        $now,
    ]);
    return fetch_request_full((int)$pdo->lastInsertId()) ?: [];
};

$openOwn = $makeRequest((int)$requester['id'], null, 'new', 'Own open job');
$openOther = $makeRequest((int)$other['id'], null, 'new', 'Other open job');
$assigned = $makeRequest((int)$requester['id'], (int)$runner['id'], 'accepted', 'Assigned job');
$pickedUp = $makeRequest((int)$requester['id'], (int)$runner['id'], 'picked_up', 'Picked-up job');
$delivered = $makeRequest((int)$requester['id'], (int)$runner['id'], 'delivered', 'Delivered job');
$cancelled = $makeRequest((int)$requester['id'], null, 'cancelled', 'Cancelled job');
$disputed = $makeRequest((int)$requester['id'], (int)$runner['id'], 'disputed', 'Disputed job');
$closedOwn = $makeRequest((int)$requester['id'], (int)$runner['id'], 'completed', 'Own closed job');
$closedOther = $makeRequest((int)$other['id'], (int)$stranger['id'], 'completed', 'Other closed job');

check_same(true, can_view_request($admin, $closedOther), 'admin can view every request');
check_same(true, can_view_request($requester, $assigned), 'requester can view own assigned request');
check_same(true, can_view_request($runner, $assigned), 'runner can view assigned request');
check_same(true, can_view_request($runner, $openOther), 'runner can view open-pool request');
check_same(false, can_view_request($stranger, $assigned), 'stranger cannot view assigned request');
check_same(false, can_view_request($requester, $closedOther), 'requester cannot view another closed request');

check_same(true, can_comment_request($admin, $closedOther), 'admin can comment on visible operations');
check_same(true, can_comment_request($requester, $assigned), 'requester can comment on own request');
check_same(true, can_comment_request($runner, $assigned), 'runner can comment on assigned request');
check_same(false, can_comment_request($runner, $openOther), 'open-pool viewer cannot comment before accepting');
check_same(false, can_comment_request($stranger, $assigned), 'stranger cannot comment on assigned request');

check_same(true, can_accept_request($runner, $openOther), 'runner can accept unassigned request from another user');
check_same(false, can_accept_request($requester, $openOwn), 'requester cannot accept own open request');
check_same(false, can_accept_request($runner, $assigned), 'runner cannot accept already assigned request');
check_same(true, can_mark_picked_up_request($runner, $assigned), 'assigned runner can mark pickup');
check_same(false, can_mark_picked_up_request($requester, $assigned), 'requester cannot mark pickup');
check_same(true, can_confirm_payment_request($requester, $assigned), 'requester can confirm payment after accept');
check_same(false, can_confirm_payment_request($runner, $assigned), 'runner cannot confirm requester payment');
check_same(true, can_start_gateway_payment_request($requester, $assigned), 'requester can start gateway payment after accept');
check_same(false, can_start_gateway_payment_request($runner, $assigned), 'runner cannot start requester gateway payment');
check_same(true, can_generate_delivery_otp_request($requester, $assigned), 'requester can generate delivery OTP for assigned request');
check_same(true, can_generate_delivery_otp_request($admin, $assigned), 'admin can generate delivery OTP for assigned request');
check_same(false, can_generate_delivery_otp_request($runner, $assigned), 'runner cannot generate requester delivery OTP');
check_same(true, can_mark_delivered_request($runner, $pickedUp), 'assigned runner can mark delivery');
check_same(false, can_mark_delivered_request($requester, $pickedUp), 'requester cannot mark runner delivery');
check_same(true, can_confirm_delivery_request($requester, $delivered), 'requester can confirm delivered request');
check_same(false, can_confirm_delivery_request($runner, $delivered), 'runner cannot confirm final requester delivery');
check_same(true, can_rate_request($requester, $delivered), 'requester can rate after delivery');
check_same(true, can_rate_request($runner, $delivered), 'runner can rate after delivery');
check_same(false, can_rate_request($stranger, $delivered), 'stranger cannot rate request');
check_same(true, can_cancel_request($requester, $assigned), 'requester can cancel active own request');
check_same(false, can_cancel_request($runner, $assigned), 'runner cannot cancel requester job');
check_same(true, can_decline_request($runner, $assigned), 'assigned runner can decline before pickup');
check_same(false, can_decline_request($requester, $assigned), 'requester cannot decline runner assignment');
check_same(true, can_dispute_request($runner, $pickedUp), 'runner can dispute active request');
check_same(true, can_dispute_request($requester, $pickedUp), 'requester can dispute active request');
check_same(false, can_dispute_request($stranger, $pickedUp), 'stranger cannot dispute active request');
check_same(true, can_reopen_request($requester, $cancelled), 'requester can reopen cancelled request');
check_same(true, can_reopen_request($admin, $disputed), 'admin can reopen disputed request');
check_same(false, can_reopen_request($runner, $cancelled), 'runner cannot reopen requester cancellation');
check_same(true, can_edit_request($requester, $openOwn), 'requester can edit own unassigned new request');
check_same(true, can_edit_request($admin, $openOther), 'admin can edit unassigned new request');
check_same(false, can_edit_request($runner, $openOther), 'runner cannot edit another open request');
check_same(false, can_edit_request($requester, $assigned), 'requester cannot edit after assignment');

$requesterQueue = array_map('intval', array_column(fetch_requests_for_list($requester, 'all', 0, '', false), 'id'));
check_contains($requesterQueue, (int)$openOwn['id'], 'requester queue includes own open request');
check_contains($requesterQueue, (int)$assigned['id'], 'requester queue includes assigned own request');
check_contains($requesterQueue, (int)$closedOwn['id'], 'requester queue includes own closed history');
check_not_contains($requesterQueue, (int)$openOther['id'], 'requester queue excludes other open requests');
check_not_contains($requesterQueue, (int)$closedOther['id'], 'requester queue excludes other closed requests');

$requesterPool = array_map('intval', array_column(fetch_requests_for_list($requester, 'new', 0, '', true), 'id'));
check_contains($requesterPool, (int)$openOther['id'], 'open pool includes unassigned request from another user');
check_not_contains($requesterPool, (int)$openOwn['id'], 'open pool excludes own open request');
check_not_contains($requesterPool, (int)$assigned['id'], 'open pool excludes assigned request');

$adminQueue = array_map('intval', array_column(fetch_requests_for_list($admin, 'all', 0, '', false), 'id'));
check_contains($adminQueue, (int)$closedOther['id'], 'admin queue includes other closed request');

$public = public_tracking_summary($assigned);
check_same(true, isset($public['title'], $public['status'], $public['task_type_name']), 'public summary includes only operational fields');
foreach (['id', 'requester_id', 'runner_id', 'requester_email', 'runner_email', 'metadata', 'description', 'price_cents', 'fee_cents'] as $key) {
    check_no_key($public, $key, 'public tracking summary redacts ' . $key);
}

rm_tree($tmp);
echo "Authz matrix passed ({$checks} checks).\n";
