<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litegig-admin-' . bin2hex(random_bytes(6));
@mkdir($tmp . DIRECTORY_SEPARATOR . 'data', 0750, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'uploads', 0750, true);

putenv('LITEGIG_DATA_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'data');
putenv('LITEGIG_UPLOAD_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'uploads');
putenv('LITEGIG_BACKUP_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'backups');
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
require_once LITEGIG_ROOT . '/app/controllers/admin.php';

$checks = 0;

function check_admin(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function rm_tree_admin(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rm_tree_admin($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$pdo = db();
$now = now_iso();
setting_set('default_fee_percent', '12.50');
check_admin(abs(app_fee_percent() - 12.5) < 0.001, 'fee percent setting is read from app_settings');
check_admin(admin_count("SELECT COUNT(*) FROM schema_migrations") >= 1, 'schema migration ledger is initialized');

$insertUser = $pdo->prepare("INSERT INTO users (email, password_hash, display_name, is_admin, created_at) VALUES (?, ?, ?, ?, ?)");
$insertUser->execute(['admin@example.test', password_hash('admin-test', PASSWORD_DEFAULT), 'Admin Test', 1, $now]);
$adminId = (int)$pdo->lastInsertId();
$insertUser->execute(['runner@example.test', password_hash('admin-test', PASSWORD_DEFAULT), 'Runner Test', 0, $now]);
$runnerId = (int)$pdo->lastInsertId();

$taskTypeId = (int)$pdo->query("SELECT id FROM task_types ORDER BY id LIMIT 1")->fetchColumn();
$insertRequest = $pdo->prepare("INSERT INTO requests
    (requester_id, task_type_id, title, description, price_cents, fee_cents, status, runner_id, metadata, created_at, updated_at)
    VALUES (?, ?, ?, 'Report fixture', ?, ?, ?, ?, '{}', ?, ?)");
$insertRequest->execute([$adminId, $taskTypeId, 'Completed report job', 3000, 375, 'completed', $runnerId, $now, $now]);
$completedId = (int)$pdo->lastInsertId();
$insertRequest->execute([$adminId, $taskTypeId, 'Disputed report job', 1500, 188, 'disputed', $runnerId, $now, $now]);
$pdo->prepare("INSERT INTO payments (request_id, method, amount_cents, fee_cents, status, confirmed_by, confirmed_at, receipt_no, created_at, updated_at)
    VALUES (?, 'manual', 3000, 375, 'confirmed', ?, ?, ?, ?, ?)")
    ->execute([$completedId, $adminId, $now, generate_receipt_no($pdo), $now, $now]);
$pdo->prepare("INSERT INTO ratings (request_id, rater_id, ratee_id, score, note, created_at) VALUES (?, ?, ?, 5, 'Great', ?)")
    ->execute([$completedId, $adminId, $runnerId, $now]);

$rows = report_rows('r.status', 'r.status', gmdate('Y-m-d\T00:00:00\Z', time() - 86400), gmdate('Y-m-d\T00:00:00\Z', time() + 86400));
$labels = array_map(fn($row) => (string)$row['label'], $rows);
check_admin(in_array('completed', $labels, true), 'reports include completed status');
check_admin(in_array('disputed', $labels, true), 'reports include disputed status');
check_admin(export_safe_cell('=SUM(1,1)') === "'=SUM(1,1)", 'export cells guard spreadsheet formulas');

$adminUser = user_by_id($adminId) ?: [];
$runnerUser = user_by_id($runnerId) ?: [];
$export = request_export_data($adminUser, 'all', false);
check_admin(in_array('created_at', $export['header'], true), 'request export includes timestamp columns');
check_admin(!in_array('requester_email', $export['header'], true), 'request export excludes PII by default');
$exportStatuses = array_map(fn($row) => (string)$row[4], $export['rows']);
check_admin(in_array('completed', $exportStatuses, true) && in_array('disputed', $exportStatuses, true), 'request export includes visible request rows');

$piiExport = request_export_data($adminUser, 'all', true);
check_admin(in_array('requester_email', $piiExport['header'], true), 'admin PII export includes requester email column');
check_admin(str_contains(render_excel_table($piiExport['header'], $piiExport['rows']), '<table>'), 'Excel export renders an HTML table');

$runnerExport = request_export_data($runnerUser, 'all', true);
check_admin((string)$runnerExport['scope'] === 'mine', 'non-admin export scope is clamped to mine');
check_admin(!in_array('requester_email', $runnerExport['header'], true), 'non-admin export cannot include PII');

$templates = notification_templates();
setting_set('notification_templates_json', json_encode([
    'request_comment' => ['subject' => 'Custom subject', 'body' => 'Custom body', 'sms' => 'Custom sms'],
], JSON_UNESCAPED_SLASHES));
$updated = notification_templates();
check_admin((string)$updated['request_comment']['subject'] === 'Custom subject', 'notification template overrides are loaded from settings');
check_admin((string)$templates['request_comment']['subject'] !== '', 'default notification templates remain available');

rm_tree_admin($tmp);
echo "Admin/reporting tests passed ({$checks} checks).\n";
