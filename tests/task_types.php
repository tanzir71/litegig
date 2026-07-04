<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litegig-task-types-' . bin2hex(random_bytes(6));
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
require_once LITEGIG_ROOT . '/app/models/task_types.php';

$checks = 0;

function check_task_types(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function rm_tree_task_types(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rm_tree_task_types($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function task_type_column_exists(PDO $pdo, string $column): bool {
    foreach ($pdo->query('PRAGMA table_info(task_types)')->fetchAll() as $row) {
        if ((string)$row['name'] === $column) return true;
    }
    return false;
}

$pdo = db();
check_task_types(task_type_column_exists($pdo, 'active'), 'task types table has active flag');
check_task_types(task_type_column_exists($pdo, 'archived_at'), 'task types table has archive timestamp');

$fieldsJson = json_encode([
    'summary_fields' => ['where', 'price_cents'],
    'fields' => [
        ['key' => 'where', 'label' => 'Where', 'type' => 'text', 'required' => true],
        ['key' => 'price_cents', 'label' => 'Price', 'type' => 'price', 'required' => true],
    ],
], JSON_UNESCAPED_SLASHES);

$insert = $pdo->prepare("INSERT INTO task_types (name, fields_json, created_at) VALUES (?, ?, ?)");
$insert->execute(['Archive Fixture', $fieldsJson, now_iso()]);
$id = (int)$pdo->lastInsertId();

$active = get_task_type_by_id($id, false);
check_task_types($active !== null && (int)$active['active'] === 1, 'new task type is active by default');
check_task_types(in_array($id, array_map(fn(array $t): int => (int)$t['id'], get_task_types()), true), 'active task type appears in active list');

check_task_types(archive_task_type($id), 'task type can be archived');
$archived = get_task_type_by_id($id, true);
check_task_types($archived !== null && (int)$archived['active'] === 0, 'archived task type remains readable for history');
check_task_types((string)$archived['archived_at'] !== '', 'archived task type records archived timestamp');
check_task_types(get_task_type_by_id($id, false) === null, 'archived task type is hidden from active lookup');
check_task_types(!in_array($id, array_map(fn(array $t): int => (int)$t['id'], get_task_types()), true), 'archived task type is hidden from active list');
check_task_types(in_array($id, array_map(fn(array $t): int => (int)$t['id'], get_task_types(true)), true), 'archived task type appears when explicitly requested');
$rowCount = (int)$pdo->query("SELECT COUNT(*) FROM task_types WHERE id = {$id}")->fetchColumn();
check_task_types($rowCount === 1, 'archived task type row is not hard-deleted');

check_task_types(restore_task_type($id), 'task type can be restored');
$restored = get_task_type_by_id($id, false);
check_task_types($restored !== null && (int)$restored['active'] === 1, 'restored task type returns to active lookup');
check_task_types((string)$restored['archived_at'] === '', 'restored task type clears archived timestamp');

$taskModel = file_get_contents(LITEGIG_ROOT . '/app/models/task_types.php') ?: '';
$taskController = file_get_contents(LITEGIG_ROOT . '/app/controllers/task_types.php') ?: '';
check_task_types(!preg_match('/DELETE\s+FROM\s+task_types/i', $taskModel . "\n" . $taskController), 'task type archive paths do not hard-delete rows');

rm_tree_task_types($tmp);
echo "Task type archive tests passed ({$checks} checks).\n";
