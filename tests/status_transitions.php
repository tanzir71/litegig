<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litegig-status-transitions-' . bin2hex(random_bytes(6));
@mkdir($tmp . DIRECTORY_SEPARATOR . 'data', 0750, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'uploads', 0750, true);

putenv('LITEGIG_DATA_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'data');
putenv('LITEGIG_UPLOAD_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'uploads');
putenv('LITEGIG_SECURITY_HEADERS=false');

define('LITEGIG_ROOT', $root);

require_once LITEGIG_ROOT . '/app/bootstrap.php';
require_once LITEGIG_ROOT . '/app/models/requests.php';
require_once LITEGIG_ROOT . '/app/http.php';

$checks = 0;

function check_transition(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function rm_tree_status_transitions(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rm_tree_status_transitions($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$rules = request_transition_rules();
foreach (['accept', 'mark_picked_up', 'confirm_payment', 'gateway_payment_confirmed', 'mark_delivered', 'confirm_delivery', 'complete_after_ratings', 'cancel', 'expire', 'decline', 'dispute', 'reopen'] as $transition) {
    check_transition(isset($rules[$transition]), $transition . ' transition is registered');
    check_transition(request_transition_target_status($transition) !== '', $transition . ' transition declares target status');
    check_transition(request_transition_event_type($transition) !== '', $transition . ' transition declares event type');
    check_transition(request_transition_source_statuses($transition) !== [], $transition . ' transition declares source statuses');
}

$statusCases = [
    ['new', 'accepted', true],
    ['new', 'picked_up', false],
    ['accepted', 'picked_up', true],
    ['accepted', 'payment_confirmed', true],
    ['picked_up', 'payment_confirmed', true],
    ['payment_confirmed', 'delivered', true],
    ['picked_up', 'delivered', true],
    ['delivered', 'completed', true],
    ['accepted', 'completed', false],
    ['new', 'expired', true],
    ['accepted', 'expired', false],
    ['accepted', 'new', true],
    ['cancelled', 'new', true],
    ['expired', 'new', true],
    ['disputed', 'new', true],
    ['completed', 'new', false],
    ['completed', 'cancelled', false],
    ['cancelled', 'delivered', false],
    ['new', 'disputed', false],
    ['delivered', 'disputed', true],
];

foreach ($statusCases as [$from, $to, $expected]) {
    check_transition(
        request_status_transition_allowed($from, $to) === $expected,
        $from . ' -> ' . $to . ' status transition is ' . ($expected ? 'allowed' : 'blocked')
    );
}

check_transition(request_transition_allows('decline', 'accepted'), 'runner decline allows accepted -> new');
check_transition(!request_transition_allows('reopen', 'accepted'), 'reopen does not reuse decline source status');
check_transition(request_transition_allows('reopen', 'cancelled'), 'reopen allows cancelled -> new');
check_transition(!request_transition_allows('decline', 'cancelled'), 'decline does not reuse reopen source status');
check_transition(request_transition_guard_sql('confirm_payment') === "status IN ('accepted','picked_up')", 'SQL guard is generated from transition sources');

$requester = ['id' => 10, 'is_admin' => 0];
$runner = ['id' => 20, 'is_admin' => 0];
$admin = ['id' => 30, 'is_admin' => 1];

$request = static function (string $status, ?int $runnerId = 20) use ($requester): array {
    return [
        'status' => $status,
        'requester_id' => (int)$requester['id'],
        'runner_id' => $runnerId,
    ];
};

check_transition(can_accept_request($runner, $request('new', null)), 'accept gate follows new source status');
check_transition(!can_accept_request($runner, $request('accepted')), 'accept gate blocks accepted source status');
check_transition(can_mark_picked_up_request($runner, $request('accepted')), 'pickup gate follows accepted source status');
check_transition(!can_mark_picked_up_request($runner, $request('new', null)), 'pickup gate blocks new source status');
check_transition(can_confirm_payment_request($requester, $request('picked_up')), 'payment gate follows picked-up source status');
check_transition(!can_confirm_payment_request($requester, $request('delivered')), 'payment gate blocks delivered source status');
check_transition(can_mark_delivered_request($runner, $request('payment_confirmed')), 'delivery gate follows payment-confirmed source status');
check_transition(!can_mark_delivered_request($runner, $request('accepted')), 'delivery gate blocks accepted source status');
check_transition(can_confirm_delivery_request($requester, $request('delivered')), 'completion gate follows delivered source status');
check_transition(!can_confirm_delivery_request($requester, $request('payment_confirmed')), 'completion gate blocks payment-confirmed source status');
check_transition(can_cancel_request($requester, $request('disputed')), 'cancel gate follows cancellable source statuses');
check_transition(!can_cancel_request($requester, $request('completed')), 'cancel gate blocks completed requests');
check_transition(can_decline_request($runner, $request('accepted')), 'decline gate follows accepted source status');
check_transition(!can_decline_request($runner, $request('picked_up')), 'decline gate blocks picked-up source status');
check_transition(can_dispute_request($runner, $request('delivered')), 'dispute gate follows delivered source status');
check_transition(!can_dispute_request($runner, $request('new', null)), 'dispute gate blocks new source status');
check_transition(can_reopen_request($admin, $request('expired', null)), 'reopen gate follows expired source status');
check_transition(!can_reopen_request($admin, $request('accepted')), 'reopen gate blocks accepted source status');

rm_tree_status_transitions($tmp);
echo "Status transition rules passed ({$checks} checks).\n";
