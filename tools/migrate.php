<?php
declare(strict_types=1);

$root = dirname(__DIR__);
define('LITEGIG_ROOT', $root);

require_once LITEGIG_ROOT . '/app/bootstrap.php';
require_once LITEGIG_ROOT . '/app/database.php';
require_once LITEGIG_ROOT . '/app/migrations.php';

function migrate_usage(): void {
    fwrite(STDERR, "Usage: php tools/migrate.php status|up|rollback [version] [--yes-destroy-data]\n");
}

function print_migration_status(PDO $pdo): void {
    echo "Version              State     Applied At              Rollback  Description\n";
    echo "-------------------  --------  ----------------------  --------  -----------\n";
    foreach (migration_status($pdo) as $row) {
        printf(
            "%-19s  %-8s  %-22s  %-8s  %s\n",
            (string)$row['version'],
            (string)$row['state'],
            (string)$row['applied_at'],
            !empty($row['destructive_rollback']) ? 'destr.' : 'safe',
            (string)$row['description']
        );
    }
}

$command = (string)($argv[1] ?? 'status');
$pdo = db();

try {
    if ($command === 'status') {
        print_migration_status($pdo);
        exit(0);
    }

    if ($command === 'up') {
        $applied = apply_pending_migrations($pdo);
        if (!$applied) {
            echo "No pending migrations.\n";
        } else {
            echo "Applied migrations: " . implode(', ', $applied) . "\n";
        }
        exit(0);
    }

    if ($command === 'rollback') {
        $version = (string)($argv[2] ?? '');
        if ($version === '') {
            migrate_usage();
            exit(2);
        }
        $allowDestructive = in_array('--yes-destroy-data', $argv, true);
        rollback_migration($pdo, $version, $allowDestructive);
        echo "Rolled back migration: {$version}\n";
        exit(0);
    }

    migrate_usage();
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
