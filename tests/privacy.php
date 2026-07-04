<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litegig-privacy-' . bin2hex(random_bytes(6));
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
require_once LITEGIG_ROOT . '/app/models/task_types.php';
require_once LITEGIG_ROOT . '/app/models/requests.php';
require_once LITEGIG_ROOT . '/app/controllers/requests.php';

$checks = 0;

function check_privacy(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function rm_tree_privacy(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rm_tree_privacy($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$redacted = redact_public_text('Call Pat at pat@example.test or +1 (555) 123-4567 before pickup');
check_privacy(!str_contains($redacted, 'pat@example.test'), 'public text redacts email addresses');
check_privacy(!str_contains($redacted, '555'), 'public text redacts phone-like values');
check_privacy(str_contains($redacted, '[redacted email]') && str_contains($redacted, '[redacted phone]'), 'public text keeps redaction markers');

$request = [
    'id' => 77,
    'code' => 'LG-ABCDEF12',
    'title' => 'Deliver docs for requester@example.test, call 555-555-1212',
    'status' => 'accepted',
    'task_type_name' => 'Delivery',
    'created_at' => '2026-07-04T12:00:00Z',
    'updated_at' => '2026-07-04T12:30:00Z',
    'requester_id' => 10,
    'runner_id' => 20,
    'requester_email' => 'requester@example.test',
    'runner_email' => 'runner@example.test',
    'metadata' => json_encode(['private_file' => 'proof.pdf', 'phone' => '555-555-1212'], JSON_UNESCAPED_SLASHES),
    'description' => 'Private delivery description.',
    'price_cents' => 2500,
    'fee_cents' => 200,
];

$summary = public_tracking_summary($request);
check_privacy($summary['title'] !== (string)$request['title'], 'public tracking title is redacted when it contains PII');
check_privacy(!str_contains($summary['title'], 'requester@example.test'), 'public tracking summary title redacts emails');
check_privacy(!str_contains($summary['title'], '555'), 'public tracking summary title redacts phones');
foreach (['id', 'requester_id', 'runner_id', 'requester_email', 'runner_email', 'metadata', 'description', 'price_cents', 'fee_cents'] as $key) {
    check_privacy(!array_key_exists($key, $summary), 'public tracking summary omits ' . $key);
}

$pdo = db();
$now = now_iso();
$pdo->prepare("INSERT INTO users (email, password_hash, display_name, phone, created_at) VALUES (?, ?, ?, ?, ?)")
    ->execute(['requester@example.test', password_hash('privacy-test', PASSWORD_DEFAULT), 'Private Requester', '+15555551212', $now]);
$requesterId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email, password_hash, display_name, phone, created_at) VALUES (?, ?, ?, ?, ?)")
    ->execute(['runner@example.test', password_hash('privacy-test', PASSWORD_DEFAULT), 'Private Runner', '+15555551313', $now]);
$runnerId = (int)$pdo->lastInsertId();
$taskTypeId = (int)$pdo->query("SELECT id FROM task_types ORDER BY id LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO requests (requester_id, task_type_id, code, title, description, price_cents, fee_cents, status, runner_id, metadata, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, 2500, 200, 'accepted', ?, ?, ?, ?)")
    ->execute([
        $requesterId,
        $taskTypeId,
        'LG-1234ABCD',
        'Deliver docs for requester@example.test, call +1 555 555 1212',
        'Private internal description with runner@example.test',
        $runnerId,
        json_encode(['private_note' => 'Call +1 555 555 1212'], JSON_UNESCAPED_SLASHES),
        $now,
        $now,
    ]);
$requestId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO events (request_id, actor_id, type, note, attachment_name, attachment_original_name, attachment_mime, created_at)
    VALUES (?, ?, 'accepted', ?, 'private-proof.txt', 'Private proof.txt', 'text/plain', ?)")
    ->execute([$requestId, $runnerId, 'Accepted by runner@example.test; call 555-555-1212', $now]);

$fetched = fetch_request_by_code('LG-1234ABCD') ?: [];
$timeline = render_public_tracking_timeline($fetched);
check_privacy(!str_contains($timeline, 'runner@example.test'), 'public timeline does not expose event notes with emails');
check_privacy(!str_contains($timeline, '555'), 'public timeline does not expose event notes with phones');
check_privacy(!str_contains($timeline, 'Private proof'), 'public timeline does not expose attachment labels');
check_privacy(str_contains($timeline, 'Accepted'), 'public timeline still shows lifecycle event labels');

$summary = public_tracking_summary($fetched);
check_privacy(!str_contains($summary['title'], 'requester@example.test'), 'fetched public summary redacts title email');
check_privacy(!str_contains($summary['title'], '555'), 'fetched public summary redacts title phone');

rm_tree_privacy($tmp);
echo "Privacy redaction tests passed ({$checks} checks).\n";
