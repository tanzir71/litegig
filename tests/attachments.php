<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litegig-attachments-' . bin2hex(random_bytes(6));
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
require_once LITEGIG_ROOT . '/app/http.php';
require_once LITEGIG_ROOT . '/app/models/task_types.php';
require_once LITEGIG_ROOT . '/app/models/requests.php';

$checks = 0;

function check_attachment(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function rm_tree_attachments(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rm_tree_attachments($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$uploadDir = $tmp . DIRECTORY_SEPARATOR . 'uploads';
file_put_contents($uploadDir . DIRECTORY_SEPARATOR . 'request-proof.txt', 'private request proof');
file_put_contents($uploadDir . DIRECTORY_SEPARATOR . 'event-proof.txt', 'private event proof');

$fieldsJson = json_encode([
    'summary_fields' => ['dropoff'],
    'fields' => [
        ['key' => 'dropoff', 'label' => 'Dropoff', 'type' => 'text', 'required' => true],
        ['key' => 'private_file', 'label' => 'Private file', 'type' => 'attachment', 'required' => false],
    ],
], JSON_UNESCAPED_SLASHES);

$request = [
    'id' => 42,
    'requester_id' => 10,
    'runner_id' => 20,
    'status' => 'accepted',
    'task_type_id' => 7,
    'task_type_name' => 'Attachment Fixture',
    'task_type_fields_json' => $fieldsJson,
    'metadata' => json_encode([
        'dropoff' => 'Desk',
        'private_file' => 'request-proof.txt',
    ], JSON_UNESCAPED_SLASHES),
];

$requester = ['id' => 10, 'is_admin' => 0];
$runner = ['id' => 20, 'is_admin' => 0];
$admin = ['id' => 30, 'is_admin' => 1];
$stranger = ['id' => 40, 'is_admin' => 0];

$download = request_attachment_download($request, 'private_file');
check_attachment($download !== null, 'request attachment resolves for a valid stored file');
check_attachment((string)$download['stored_name'] === 'request-proof.txt', 'request attachment exposes stored filename');
check_attachment(file_get_contents((string)$download['path']) === 'private request proof', 'request attachment path points to private upload content');
check_attachment(str_starts_with((string)$download['path'], realpath($uploadDir) . DIRECTORY_SEPARATOR), 'request attachment path stays inside upload directory');

check_attachment(can_view_request($requester, $request), 'requester can reach own request attachment gate');
check_attachment(can_view_request($runner, $request), 'assigned runner can reach request attachment gate');
check_attachment(can_view_request($admin, $request), 'admin can reach request attachment gate');
check_attachment(!can_view_request($stranger, $request), 'stranger is blocked before attachment resolution on assigned request');

check_attachment(request_attachment_download($request, 'dropoff') === null, 'non-attachment schema fields cannot be downloaded');
check_attachment(request_attachment_download($request, '../private_file') === null, 'invalid attachment field names are rejected');

$badRequest = $request;
$badRequest['metadata'] = json_encode(['private_file' => '../request-proof.txt'], JSON_UNESCAPED_SLASHES);
check_attachment(request_attachment_download($badRequest, 'private_file') === null, 'request attachment traversal names are rejected');

$missingRequest = $request;
$missingRequest['metadata'] = json_encode(['private_file' => 'missing-proof.txt'], JSON_UNESCAPED_SLASHES);
check_attachment(request_attachment_download($missingRequest, 'private_file') === null, 'missing request attachment files are rejected');

$event = [
    'request_id' => 42,
    'attachment_name' => 'event-proof.txt',
    'attachment_original_name' => 'Runner Proof.txt',
    'attachment_mime' => 'text/plain',
];
$eventDownload = event_attachment_download($event);
check_attachment($eventDownload !== null, 'event attachment resolves for a valid stored file');
check_attachment((string)$eventDownload['download_name'] === 'Runner_Proof.txt', 'event attachment download name is sanitized');
check_attachment((string)$eventDownload['mime'] === 'text/plain', 'event attachment preserves stored mime');
check_attachment(file_get_contents((string)$eventDownload['path']) === 'private event proof', 'event attachment path points to private upload content');

$badEvent = $event;
$badEvent['attachment_name'] = 'nested/event-proof.txt';
check_attachment(event_attachment_download($badEvent) === null, 'event attachment traversal names are rejected');

check_attachment(stored_upload_basename('plain.txt') === 'plain.txt', 'plain stored upload names are accepted');
check_attachment(stored_upload_basename('nested/plain.txt') === '', 'nested stored upload names are rejected');
check_attachment(stored_upload_basename('..\\plain.txt') === '', 'backslash stored upload names are rejected');

rm_tree_attachments($tmp);
echo "Attachment access tests passed ({$checks} checks).\n";
