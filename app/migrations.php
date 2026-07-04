<?php
declare(strict_types=1);

function migration_definitions(): array {
    return [
        '2026-07-04-bootstrap' => [
            'description' => 'Current SQLite schema with modular LiteGig tables',
            'destructive' => true,
            'up' => [],
            'down' => [
                'PRAGMA foreign_keys=OFF',
                'DROP TABLE IF EXISTS payment_webhook_events',
                'DROP TABLE IF EXISTS saved_views',
                'DROP TABLE IF EXISTS payments',
                'DROP TABLE IF EXISTS notifications',
                'DROP TABLE IF EXISTS rate_limits',
                'DROP TABLE IF EXISTS app_settings',
                'DROP TABLE IF EXISTS imports',
                'DROP TABLE IF EXISTS audit_log',
                'DROP TABLE IF EXISTS ratings',
                'DROP TABLE IF EXISTS events',
                'DROP TABLE IF EXISTS requests',
                'DROP TABLE IF EXISTS task_types',
                'DROP TABLE IF EXISTS users',
                'DROP TABLE IF EXISTS schema_migrations',
                'PRAGMA foreign_keys=ON',
            ],
        ],
    ];
}

function migration_status(PDO $pdo): array {
    $applied = [];
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version TEXT PRIMARY KEY,
        description TEXT NOT NULL,
        applied_at TEXT NOT NULL
    )");
    foreach ($pdo->query("SELECT version, description, applied_at FROM schema_migrations ORDER BY version ASC")->fetchAll() as $row) {
        $applied[(string)$row['version']] = $row;
    }

    $rows = [];
    foreach (migration_definitions() as $version => $definition) {
        $row = $applied[$version] ?? null;
        $rows[] = [
            'version' => $version,
            'description' => (string)$definition['description'],
            'state' => $row ? 'applied' : 'pending',
            'applied_at' => $row ? (string)$row['applied_at'] : '',
            'destructive_rollback' => !empty($definition['destructive']),
        ];
        unset($applied[$version]);
    }

    foreach ($applied as $version => $row) {
        $rows[] = [
            'version' => $version,
            'description' => (string)($row['description'] ?? 'Unknown migration not present in this build'),
            'state' => 'unknown',
            'applied_at' => (string)($row['applied_at'] ?? ''),
            'destructive_rollback' => false,
        ];
    }

    usort($rows, fn(array $a, array $b): int => strcmp((string)$a['version'], (string)$b['version']));
    return $rows;
}

function apply_pending_migrations(PDO $pdo): array {
    $appliedVersions = [];
    foreach (migration_status($pdo) as $row) {
        if ((string)$row['state'] === 'applied') $appliedVersions[(string)$row['version']] = true;
    }

    $appliedNow = [];
    foreach (migration_definitions() as $version => $definition) {
        if (isset($appliedVersions[$version])) continue;

        $pdo->beginTransaction();
        try {
            foreach (($definition['up'] ?? []) as $sql) {
                $sql = trim((string)$sql);
                if ($sql !== '') $pdo->exec($sql);
            }
            $stmt = $pdo->prepare("INSERT INTO schema_migrations (version, description, applied_at) VALUES (?, ?, ?)");
            $stmt->execute([$version, (string)$definition['description'], now_iso()]);
            $pdo->commit();
            $appliedNow[] = $version;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    return $appliedNow;
}

function rollback_migration(PDO $pdo, string $version, bool $allowDestructive = false): void {
    $definitions = migration_definitions();
    if (!isset($definitions[$version])) {
        throw new RuntimeException('Unknown migration: ' . $version);
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM schema_migrations WHERE version = ?");
    $stmt->execute([$version]);
    $isApplied = (int)$stmt->fetchColumn() > 0;
    $stmt->closeCursor();
    if (!$isApplied) {
        throw new RuntimeException('Migration is not applied: ' . $version);
    }

    $definition = $definitions[$version];
    if (!empty($definition['destructive']) && !$allowDestructive) {
        throw new RuntimeException('Rollback for ' . $version . ' destroys data; rerun with --yes-destroy-data.');
    }

    $down = $definition['down'] ?? [];
    if (!is_array($down) || !$down) {
        throw new RuntimeException('Migration has no rollback SQL: ' . $version);
    }

    $pdo->beginTransaction();
    try {
        foreach ($down as $sql) {
            $sql = trim((string)$sql);
            if ($sql !== '') $pdo->exec($sql);
        }
        if (table_exists($pdo, 'schema_migrations')) {
            $delete = $pdo->prepare("DELETE FROM schema_migrations WHERE version = ?");
            $delete->execute([$version]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name = ?");
    $stmt->execute([$table]);
    $exists = (int)$stmt->fetchColumn() > 0;
    $stmt->closeCursor();
    return $exists;
}
