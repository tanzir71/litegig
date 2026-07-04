<?php
declare(strict_types=1);

function normalize_task_type_row(array $row): array {
    $raw = json_decode((string)$row['fields_json'], true);
    $fields = [];
    $summary = [];
    if (is_array($raw)) {
        $isAssoc = array_keys($raw) !== range(0, count($raw) - 1);
        if ($isAssoc) {
            $fields = is_array($raw['fields'] ?? null) ? $raw['fields'] : [];
            $summary = is_array($raw['summary_fields'] ?? null) ? $raw['summary_fields'] : [];
        } else {
            $fields = $raw;
        }
    }
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'fields' => $fields,
        'summary_fields' => $summary,
        'fields_json' => (string)$row['fields_json'],
        'active' => (int)($row['active'] ?? 1),
        'archived_at' => (string)($row['archived_at'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
    ];
}

function get_task_types(bool $includeArchived = false): array {
    $pdo = db();
    $sql = $includeArchived
        ? "SELECT * FROM task_types ORDER BY active DESC, name ASC"
        : "SELECT * FROM task_types WHERE active = 1 ORDER BY name ASC";
    $rows = $pdo->query($sql)->fetchAll();
    return array_map('normalize_task_type_row', $rows);
}

function get_task_type_by_id(int $id, bool $includeArchived = true): ?array {
    $pdo = db();
    $sql = $includeArchived
        ? "SELECT * FROM task_types WHERE id = ?"
        : "SELECT * FROM task_types WHERE id = ? AND active = 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? normalize_task_type_row($row) : null;
}

function archive_task_type(int $id): bool {
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE task_types SET active = 0, archived_at = ? WHERE id = ? AND active = 1");
    $stmt->execute([now_iso(), $id]);
    return $stmt->rowCount() > 0;
}

function restore_task_type(int $id): bool {
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE task_types SET active = 1, archived_at = NULL WHERE id = ? AND active = 0");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

function sanitize_upload_filename(string $name): string {
    $name = basename($name);
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'file';
    $name = trim($name, '._-');
    if ($name === '') $name = 'file';
    return substr($name, 0, 120);
}

function ensure_upload_dir(): void {
    global $CFG;
    if (!is_dir($CFG['upload_dir'])) {
        @mkdir($CFG['upload_dir'], 0750, true);
    }
    $deny = (string)$CFG['upload_dir'] . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($deny)) {
        @file_put_contents($deny, "Require all denied\nDeny from all\n");
    }
    $index = (string)$CFG['upload_dir'] . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($index)) {
        @file_put_contents($index, '');
    }
}

function validate_upload(array $file, array &$errors, string $key): ?array {
    global $CFG;
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        $errors[$key] = 'Upload failed.';
        return null;
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > (int)$CFG['max_upload_bytes']) {
        $errors[$key] = 'File is too large or empty.';
        return null;
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $errors[$key] = 'Upload was not accepted.';
        return null;
    }

    $safe = sanitize_upload_filename((string)($file['name'] ?? 'file'));
    $ext = strtolower(pathinfo($safe, PATHINFO_EXTENSION));
    if ($ext === '' || in_array($ext, $CFG['blocked_upload_ext'], true) || !in_array($ext, $CFG['allowed_upload_ext'], true)) {
        $errors[$key] = 'File type is not allowed.';
        return null;
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $tmp);
            finfo_close($finfo);
        }
    }
    if ($mime !== '' && !in_array($mime, $CFG['allowed_upload_mime'], true)) {
        $errors[$key] = 'File content type is not allowed.';
        return null;
    }

    return ['tmp' => $tmp, 'ext' => $ext, 'mime' => $mime, 'original_name' => $safe];
}

function store_uploaded_file(array $file, array &$errors, string $key, string $prefix = 'att'): ?array {
    global $CFG;
    $upload = validate_upload($file, $errors, $key);
    if (!$upload) return null;

    ensure_upload_dir();
    $safePrefix = preg_replace('/[^A-Za-z0-9_-]+/', '_', $prefix) ?: 'att';
    $final = $safePrefix . '_' . bin2hex(random_bytes(16)) . '.' . $upload['ext'];
    $dest = $CFG['upload_dir'] . DIRECTORY_SEPARATOR . $final;
    if (!move_uploaded_file((string)$upload['tmp'], $dest)) {
        $errors[$key] = 'Could not save file.';
        return null;
    }
    @chmod($dest, 0640);

    return [
        'name' => $final,
        'original_name' => (string)$upload['original_name'],
        'mime' => (string)$upload['mime'],
    ];
}

function delete_stored_upload(?string $name): void {
    global $CFG;
    if (!$name || preg_match('/[\/\\\\]/', $name)) return;
    $base = realpath((string)$CFG['upload_dir']);
    $path = realpath((string)$CFG['upload_dir'] . DIRECTORY_SEPARATOR . $name);
    if ($base && $path && str_starts_with($path, $base . DIRECTORY_SEPARATOR) && is_file($path)) {
        @unlink($path);
    }
}
