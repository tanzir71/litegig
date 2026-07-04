<?php
declare(strict_types=1);

function backup_required_tables(): array {
    return [
        'users',
        'task_types',
        'requests',
        'events',
        'ratings',
        'audit_log',
        'schema_migrations',
        'notifications',
        'payments',
        'saved_views',
    ];
}

function verify_sqlite_backup_file(string $path): array {
    if (!is_file($path) || filesize($path) === 0) {
        return ['ok' => false, 'error' => 'Backup file is missing or empty.'];
    }

    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $integrity = (string)$pdo->query('PRAGMA integrity_check')->fetchColumn();
        if (strtolower($integrity) !== 'ok') {
            return ['ok' => false, 'error' => 'SQLite integrity_check failed: ' . $integrity];
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name = ?");
        foreach (backup_required_tables() as $table) {
            $stmt->execute([$table]);
            $exists = (int)$stmt->fetchColumn() > 0;
            $stmt->closeCursor();
            if (!$exists) {
                return ['ok' => false, 'error' => 'Required table missing from backup: ' . $table];
            }
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM schema_migrations WHERE version = ?");
        $stmt->execute(['2026-07-04-bootstrap']);
        $hasBootstrap = (int)$stmt->fetchColumn() > 0;
        $stmt->closeCursor();
        if (!$hasBootstrap) {
            return ['ok' => false, 'error' => 'Bootstrap migration record missing from backup.'];
        }

        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function litegig_backup_candidates(string $dir): array {
    if ($dir === '' || !is_dir($dir)) return [];
    $base = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $files = array_merge(
        glob($base . 'litegig-*.db') ?: [],
        glob($base . 'litegig-*.sqlite') ?: []
    );
    usort($files, static function (string $a, string $b): int {
        return (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0);
    });
    return $files;
}

function latest_litegig_backup_file(string $dir): ?string {
    $files = litegig_backup_candidates($dir);
    return $files[0] ?? null;
}

function create_sqlite_backup(): array {
    global $CFG;

    $dbPath = (string)$CFG['db_path'];
    $backupDir = (string)$CFG['backup_dir'];
    if (!is_dir($backupDir)) @mkdir($backupDir, 0750, true);
    db();
    if (!is_file($dbPath) || !is_dir($backupDir)) {
        return ['ok' => false, 'verified' => false, 'file' => '', 'path' => '', 'error' => 'backup_path_unavailable'];
    }

    $dest = '';
    for ($i = 0; $i < 20; $i++) {
        $candidate = $backupDir . DIRECTORY_SEPARATOR . 'litegig-' . gmdate('Ymd-His') . '-' . strtolower(bin2hex(random_bytes(2))) . '.db';
        if (!is_file($candidate)) {
            $dest = $candidate;
            break;
        }
    }
    if ($dest === '') {
        return ['ok' => false, 'verified' => false, 'file' => '', 'path' => '', 'error' => 'backup_name_unavailable'];
    }
    $created = false;
    $error = '';
    try {
        db()->exec('VACUUM INTO ' . db()->quote($dest));
        $created = is_file($dest);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $created = @copy($dbPath, $dest);
    }

    if (!$created) {
        return ['ok' => false, 'verified' => false, 'file' => basename($dest), 'path' => $dest, 'error' => $error !== '' ? $error : 'backup_failed'];
    }

    $verification = verify_sqlite_backup_file($dest);
    $files = litegig_backup_candidates($backupDir);
    foreach (array_slice($files, 14) as $old) {
        @unlink($old);
    }

    return [
        'ok' => (bool)$verification['ok'],
        'verified' => (bool)$verification['ok'],
        'file' => basename($dest),
        'path' => $dest,
        'error' => (string)$verification['error'],
    ];
}
