<?php
declare(strict_types=1);

$root = dirname(__DIR__);
define('LITEGIG_ROOT', $root);

require_once LITEGIG_ROOT . '/app/bootstrap.php';
require_once LITEGIG_ROOT . '/app/database.php';
require_once LITEGIG_ROOT . '/app/services/backups.php';

if (in_array('--help', $argv, true)) {
    echo "Usage: php tools/maintenance.php [--apply] [--backup] [--prune-business-data] [--terminal-days=N] [--audit-days=N] [--rate-limit-days=N] [--idempotency-days=N] [--notification-days=N]\n";
    echo "Dry-run is the default. Add --apply to delete/prune rows or create a backup.\n";
    exit(0);
}

function maintenance_option(array $argv, string $name, int $default): int {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--' . $name . '=')) {
            $value = substr($arg, strlen($name) + 3);
            return preg_match('/^\d+$/', $value) ? max(0, (int)$value) : $default;
        }
    }
    return $default;
}

function maintenance_cutoff(int $days): string {
    return gmdate('Y-m-d\TH:i:s\Z', time() - ($days * 86400));
}

function maintenance_count(PDO $pdo, string $sql, array $params): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function maintenance_delete(PDO $pdo, string $sql, array $params): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

$apply = in_array('--apply', $argv, true);
$backup = in_array('--backup', $argv, true);
$pruneBusinessData = in_array('--prune-business-data', $argv, true);
$terminalDays = maintenance_option($argv, 'terminal-days', 730);
$auditDays = maintenance_option($argv, 'audit-days', 365);
$rateLimitDays = maintenance_option($argv, 'rate-limit-days', 30);
$idempotencyDays = maintenance_option($argv, 'idempotency-days', 90);
$notificationDays = maintenance_option($argv, 'notification-days', 180);

$pdo = db();
$actions = [];

if ($backup) {
    if ($apply) {
        $result = create_sqlite_backup();
        $actions[] = $result['ok']
            ? 'backup created: ' . (string)$result['file'] . ' verified=' . ((bool)$result['verified'] ? '1' : '0')
            : 'backup failed: ' . (string)$result['error'];
        if (!$result['ok']) {
            fwrite(STDERR, "Maintenance failed: backup could not be created.\n");
            exit(1);
        }
    } else {
        $actions[] = 'would create verified SQLite backup';
    }
}

$cleanup = [
    [
        'label' => 'audit rows',
        'days' => $auditDays,
        'count' => "SELECT COUNT(*) FROM audit_log WHERE created_at < ?",
        'delete' => "DELETE FROM audit_log WHERE created_at < ?",
    ],
    [
        'label' => 'expired rate-limit rows',
        'days' => $rateLimitDays,
        'count' => "SELECT COUNT(*) FROM rate_limits WHERE updated_at < ?",
        'delete' => "DELETE FROM rate_limits WHERE updated_at < ?",
    ],
    [
        'label' => 'processed idempotency rows',
        'days' => $idempotencyDays,
        'count' => "SELECT COUNT(*) FROM payment_webhook_events WHERE processed_at < ?",
        'delete' => "DELETE FROM payment_webhook_events WHERE processed_at < ?",
    ],
    [
        'label' => 'sent/failed notification rows',
        'days' => $notificationDays,
        'count' => "SELECT COUNT(*) FROM notifications WHERE status IN ('sent','failed') AND updated_at < ?",
        'delete' => "DELETE FROM notifications WHERE status IN ('sent','failed') AND updated_at < ?",
    ],
];

foreach ($cleanup as $job) {
    $cutoff = maintenance_cutoff((int)$job['days']);
    $count = maintenance_count($pdo, (string)$job['count'], [$cutoff]);
    if ($apply && $count > 0) {
        $deleted = maintenance_delete($pdo, (string)$job['delete'], [$cutoff]);
        $actions[] = 'deleted ' . $deleted . ' ' . $job['label'] . ' older than ' . $cutoff;
    } else {
        $actions[] = ($apply ? 'no-op ' : 'would delete ') . $count . ' ' . $job['label'] . ' older than ' . $cutoff;
    }
}

$terminalCutoff = maintenance_cutoff($terminalDays);
$terminalCount = maintenance_count(
    $pdo,
    "SELECT COUNT(*) FROM requests WHERE status IN ('completed','cancelled') AND updated_at < ?",
    [$terminalCutoff]
);
if ($pruneBusinessData) {
    if ($apply && $terminalCount > 0) {
        $deleted = maintenance_delete(
            $pdo,
            "DELETE FROM requests WHERE status IN ('completed','cancelled') AND updated_at < ?",
            [$terminalCutoff]
        );
        $actions[] = 'deleted ' . $deleted . ' terminal request rows older than ' . $terminalCutoff;
    } else {
        $actions[] = ($apply ? 'no-op ' : 'would delete ') . $terminalCount . ' terminal request rows older than ' . $terminalCutoff;
    }
} else {
    $actions[] = 'business-data prune skipped; ' . $terminalCount . ' terminal request rows older than ' . $terminalCutoff . ' would require --prune-business-data';
}

audit_log(null, 'maintenance_' . ($apply ? 'apply' : 'dry_run'), 'system', null, [
    'backup' => $backup,
    'prune_business_data' => $pruneBusinessData,
    'terminal_days' => $terminalDays,
    'audit_days' => $auditDays,
    'rate_limit_days' => $rateLimitDays,
    'idempotency_days' => $idempotencyDays,
    'notification_days' => $notificationDays,
]);

echo "LiteGig maintenance " . ($apply ? "apply" : "dry-run") . "\n";
echo "================================\n";
foreach ($actions as $line) {
    echo "- {$line}\n";
}
