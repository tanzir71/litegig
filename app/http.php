<?php
declare(strict_types=1);

function input_string(array $source, string $key, int $maxLen = 1000): string {
    $value = trim((string)($source[$key] ?? ''));
    if (strlen($value) > $maxLen) $value = substr($value, 0, $maxLen);
    return $value;
}

function input_int(array $source, string $key, int $default = 0, int $min = 0, int $max = PHP_INT_MAX): int {
    $raw = $source[$key] ?? null;
    if ($raw === null || !preg_match('/^-?\d+$/', (string)$raw)) return $default;
    return max($min, min($max, (int)$raw));
}

function input_float(array $source, string $key, float $default, float $min, float $max): float {
    $raw = $source[$key] ?? null;
    if ($raw === null || $raw === '' || !is_numeric($raw)) return $default;
    return max($min, min($max, (float)$raw));
}

function current_user(bool $includeInactive = false): ?array {
    if (empty($_SESSION['uid'])) return null;
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, email, display_name, phone, is_admin, status, notify_email_enabled, notify_sms_enabled, notify_events_json, created_at FROM users WHERE id = ?");
    $stmt->execute([(int)$_SESSION['uid']]);
    $u = $stmt->fetch();
    if (!$u) return null;
    if (!$includeInactive && (string)($u['status'] ?? 'active') !== 'active') return null;
    return $u;
}

function require_login(): array {
    $u = current_user(true);
    if (!$u) {
        flash_set('error', 'Please log in.');
        redirect_to('?action=login');
    }
    if ((string)($u['status'] ?? 'active') !== 'active') {
        reset_session_state();
        flash_set('error', 'This account is suspended. Contact an admin to restore access.');
        redirect_to('?action=login');
    }
    return $u;
}

function require_admin(): array {
    $u = require_login();
    if ((int)$u['is_admin'] !== 1) {
        render_forbidden('Admin access required', 'This area is reserved for operators with admin access.');
    }
    return $u;
}

function render_forbidden(string $title = 'Forbidden', string $body = 'You do not have access to this page.'): void {
    http_response_code(403);
    render_layout('Forbidden', render_state_box($title, $body, [
        ['label' => 'Back to requests', 'href' => '?action=list_requests', 'primary' => true],
    ], 'error'));
    exit;
}

function render_not_found(string $title = 'Not found', string $body = 'That record is not available.'): void {
    http_response_code(404);
    render_layout('Not found', render_state_box($title, $body, [
        ['label' => 'Back to requests', 'href' => '?action=list_requests', 'primary' => true],
    ], 'empty'));
    exit;
}

function render_method_not_allowed(): void {
    http_response_code(405);
    render_layout('Method not allowed', render_state_box('Action needs a form submission', 'Use the buttons and forms in LiteGig to make changes.', [
        ['label' => 'Back to requests', 'href' => '?action=list_requests', 'primary' => true],
    ], 'warn'));
    exit;
}

function render_bad_request(string $title = 'Bad request', string $body = 'The request could not be processed.'): void {
    http_response_code(400);
    render_layout('Bad request', render_state_box($title, $body, [
        ['label' => 'Back to requests', 'href' => '?action=list_requests', 'primary' => true],
    ], 'error'));
    exit;
}

function status_options(): array {
    return t_options('status', [
        'new' => 'New',
        'accepted' => 'Accepted',
        'picked_up' => 'Picked up',
        'payment_confirmed' => 'Payment confirmed',
        'delivered' => 'Delivered',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'disputed' => 'Disputed',
        'expired' => 'Expired',
        'all' => 'All',
    ]);
}

function validate_status_filter(string $status): string {
    return array_key_exists($status, status_options()) ? $status : 'new';
}

function is_request_participant(array $u, array $r): bool {
    $uid = (int)$u['id'];
    return $uid === (int)$r['requester_id'] || ($r['runner_id'] !== null && $uid === (int)$r['runner_id']);
}

function can_view_request(array $u, array $r): bool {
    if ((int)$u['is_admin'] === 1) return true;
    if (is_request_participant($u, $r)) return true;
    return (string)$r['status'] === 'new';
}

function can_comment_request(array $u, array $r): bool {
    return (int)$u['is_admin'] === 1 || is_request_participant($u, $r);
}

function can_accept_request(array $u, array $r): bool {
    return request_transition_allows('accept', (string)$r['status'])
        && $r['runner_id'] === null
        && (int)$r['requester_id'] !== (int)$u['id'];
}

function can_mark_picked_up_request(array $u, array $r): bool {
    return request_transition_allows('mark_picked_up', (string)$r['status'])
        && $r['runner_id'] !== null
        && (int)$r['runner_id'] === (int)$u['id'];
}

function can_confirm_payment_request(array $u, array $r): bool {
    return request_transition_allows('confirm_payment', (string)$r['status'])
        && (int)$r['requester_id'] === (int)$u['id'];
}

function can_start_gateway_payment_request(array $u, array $r): bool {
    return request_transition_allows('gateway_payment_confirmed', (string)$r['status'])
        && (int)$r['requester_id'] === (int)$u['id'];
}

function can_mark_delivered_request(array $u, array $r): bool {
    return request_transition_allows('mark_delivered', (string)$r['status'])
        && $r['runner_id'] !== null
        && (int)$r['runner_id'] === (int)$u['id'];
}

function can_generate_delivery_otp_request(array $u, array $r): bool {
    if (!delivery_otp_required($r)) return false;
    return (int)$u['is_admin'] === 1 || (int)$r['requester_id'] === (int)$u['id'];
}

function can_confirm_delivery_request(array $u, array $r): bool {
    return request_transition_allows('confirm_delivery', (string)$r['status'])
        && (int)$r['requester_id'] === (int)$u['id'];
}

function can_rate_request(array $u, array $r): bool {
    if (!in_array((string)$r['status'], ['delivered', 'completed'], true)) return false;
    if (!is_request_participant($u, $r)) return false;
    return $r['runner_id'] !== null;
}

function can_cancel_request(array $u, array $r): bool {
    if (!request_transition_allows('cancel', (string)$r['status'])) return false;
    return (int)$u['is_admin'] === 1 || (int)$r['requester_id'] === (int)$u['id'];
}

function can_decline_request(array $u, array $r): bool {
    return request_transition_allows('decline', (string)$r['status'])
        && $r['runner_id'] !== null
        && (int)$r['runner_id'] === (int)$u['id'];
}

function can_dispute_request(array $u, array $r): bool {
    if (!request_transition_allows('dispute', (string)$r['status'])) return false;
    return (int)$u['is_admin'] === 1 || is_request_participant($u, $r);
}

function can_reopen_request(array $u, array $r): bool {
    if (!request_transition_allows('reopen', (string)$r['status'])) return false;
    return (int)$u['is_admin'] === 1 || (int)$r['requester_id'] === (int)$u['id'];
}

function can_edit_request(array $u, array $r): bool {
    if ((string)$r['status'] !== 'new' || $r['runner_id'] !== null) return false;
    return (int)$u['is_admin'] === 1 || (int)$r['requester_id'] === (int)$u['id'];
}

function redact_public_text(string $text): string {
    $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted email]', $text) ?? $text;
    $text = preg_replace('/(?<!\w)(?:\+?\d[\d .()\-]{7,}\d)(?!\w)/', '[redacted phone]', $text) ?? $text;
    return trim($text);
}

function public_tracking_summary(array $r): array {
    return [
        'code' => (string)($r['code'] ?? ''),
        'title' => redact_public_text((string)$r['title']),
        'status' => (string)$r['status'],
        'task_type_name' => (string)($r['task_type_name'] ?? ''),
        'created_at' => (string)$r['created_at'],
        'updated_at' => (string)$r['updated_at'],
    ];
}

function require_request_view(array $u, array $r): void {
    if (!can_view_request($u, $r)) {
        render_forbidden('Request access unavailable', 'You can view open requests, requests you posted, requests assigned to you, or admin-visible records.');
    }
}
