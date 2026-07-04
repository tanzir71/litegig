<?php
declare(strict_types=1);

function user_status_options(): array {
    return [
        'active' => 'Active',
        'suspended' => 'Disabled',
    ];
}

function normalize_user_status(string $status): string {
    return array_key_exists($status, user_status_options()) ? $status : 'active';
}

function user_is_active(array $user): bool {
    return normalize_user_status((string)($user['status'] ?? 'active')) === 'active';
}

function user_by_email(string $email): ?array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE lower(email) = lower(?)");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function user_by_id(int $id): ?array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, email, display_name, phone, is_admin, status, notify_email_enabled, notify_sms_enabled, notify_events_json, created_at FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function count_users(): int {
    return (int)db()->query("SELECT COUNT(*) AS c FROM users")->fetch()['c'];
}

function user_rating_summary(int $userId): array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT COUNT(*) AS rating_count, AVG(score) AS rating_avg FROM ratings WHERE ratee_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: ['rating_count' => 0, 'rating_avg' => null];
    $count = (int)($row['rating_count'] ?? 0);
    return [
        'count' => $count,
        'avg' => $count > 0 ? (float)$row['rating_avg'] : null,
    ];
}

function user_profile_stats(int $userId): array {
    $pdo = db();
    $posted = $pdo->prepare("SELECT COUNT(*) AS c FROM requests WHERE requester_id = ?");
    $posted->execute([$userId]);

    $accepted = $pdo->prepare("SELECT COUNT(*) AS c FROM requests WHERE runner_id = ?");
    $accepted->execute([$userId]);

    $completedAsRunner = $pdo->prepare("SELECT COUNT(*) AS c FROM requests WHERE runner_id = ? AND status = 'completed'");
    $completedAsRunner->execute([$userId]);

    $completedAsRequester = $pdo->prepare("SELECT COUNT(*) AS c FROM requests WHERE requester_id = ? AND status = 'completed'");
    $completedAsRequester->execute([$userId]);

    return [
        'posted' => (int)$posted->fetch()['c'],
        'accepted' => (int)$accepted->fetch()['c'],
        'completed_as_runner' => (int)$completedAsRunner->fetch()['c'],
        'completed_as_requester' => (int)$completedAsRequester->fetch()['c'],
    ];
}

function user_recent_ratings(int $userId, int $limit = 8): array {
    $pdo = db();
    $limit = max(1, min(20, $limit));
    $stmt = $pdo->prepare("SELECT r.*, u.display_name AS rater_name, req.title AS request_title
        FROM ratings r
        JOIN users u ON u.id = r.rater_id
        JOIN requests req ON req.id = r.request_id
        WHERE r.ratee_id = ?
        ORDER BY r.id DESC
        LIMIT " . $limit);
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function access_audit_actions(): array {
    return [
        'user.create_admin',
        'user.update_access',
        'user.reset_password',
    ];
}

function user_access_snapshot(?array $user): array {
    if (!$user) {
        return ['exists' => false];
    }
    return [
        'exists' => true,
        'id' => (int)$user['id'],
        'email' => (string)$user['email'],
        'display_name' => (string)$user['display_name'],
        'phone' => (string)($user['phone'] ?? ''),
        'is_admin' => (int)$user['is_admin'] === 1,
        'status' => normalize_user_status((string)($user['status'] ?? 'active')),
    ];
}

function assert_access_audit_payload(string $action, string $reason, array $before, array $after): void {
    if (!in_array($action, access_audit_actions(), true)) {
        throw new InvalidArgumentException('Unknown access audit action.');
    }
    if (trim($reason) === '') {
        throw new InvalidArgumentException('Access changes require a reason.');
    }
    if ($before === []) {
        throw new InvalidArgumentException('Access changes require a before snapshot.');
    }
    if ($after === []) {
        throw new InvalidArgumentException('Access changes require an after snapshot.');
    }
}

function audit_user_access_change(int $actorId, string $action, int $targetId, array $before, array $after, string $reason): void {
    $reason = trim($reason);
    assert_access_audit_payload($action, $reason, $before, $after);
    audit_log($actorId, $action, 'user', $targetId, [
        'reason' => $reason,
        'before' => $before,
        'after' => $after,
    ]);
}

function active_admin_count(): int {
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE is_admin = 1 AND COALESCE(status, 'active') = 'active'")->fetchColumn();
}

function generated_access_passphrase(): string {
    return bin2hex(random_bytes(6)) . '-' . bin2hex(random_bytes(3));
}

function create_production_admin_idempotent(int $actorId, string $email, string $displayName, string $phone, string $password, string $reason): array {
    $email = strtolower(trim($email));
    $displayName = trim($displayName);
    $phone = trim($phone);
    $reason = trim($reason);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid admin email.');
    }
    if ($displayName === '') {
        throw new InvalidArgumentException('Enter the admin display name.');
    }
    if ($reason === '') {
        throw new InvalidArgumentException('Enter a reason for the access change.');
    }

    $pdo = db();
    $existing = user_by_email($email);
    $before = user_access_snapshot($existing);
    $credential = $password;
    $generatedPassword = false;
    if ($credential === '' && !$existing) {
        $credential = generated_access_passphrase();
        $generatedPassword = true;
    }
    if ($credential !== '' && strlen($credential) < 8) {
        throw new InvalidArgumentException('Use an 8+ character temporary password.');
    }

    if ($existing) {
        $id = (int)$existing['id'];
        $after = $before;
        $after['display_name'] = $displayName;
        $after['phone'] = $phone;
        $after['is_admin'] = true;
        $after['status'] = 'active';
        $changed = $after !== $before || $credential !== '';
        if ($changed) {
            if ($credential !== '') {
                $stmt = $pdo->prepare("UPDATE users SET display_name = ?, phone = ?, is_admin = 1, status = 'active', password_hash = ? WHERE id = ?");
                $stmt->execute([$displayName, $phone !== '' ? $phone : null, password_hash($credential, PASSWORD_DEFAULT), $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET display_name = ?, phone = ?, is_admin = 1, status = 'active' WHERE id = ?");
                $stmt->execute([$displayName, $phone !== '' ? $phone : null, $id]);
            }
            $after = user_access_snapshot(user_by_id($id));
            audit_user_access_change($actorId, 'user.create_admin', $id, $before, $after, $reason);
        }
        return ['id' => $id, 'created' => false, 'changed' => $changed, 'password' => $credential, 'generated_password' => $generatedPassword];
    }

    $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, display_name, phone, is_admin, status, created_at) VALUES (?, ?, ?, ?, 1, 'active', ?)");
    $stmt->execute([$email, password_hash($credential, PASSWORD_DEFAULT), $displayName, $phone !== '' ? $phone : null, now_iso()]);
    $id = (int)$pdo->lastInsertId();
    $after = user_access_snapshot(user_by_id($id));
    audit_user_access_change($actorId, 'user.create_admin', $id, $before, $after, $reason);
    return ['id' => $id, 'created' => true, 'changed' => true, 'password' => $credential, 'generated_password' => $generatedPassword];
}

function update_user_access_idempotent(int $actorId, int $targetId, bool $makeAdmin, string $status, string $reason): array {
    $status = normalize_user_status($status);
    $reason = trim($reason);
    if ($reason === '') {
        throw new InvalidArgumentException('Enter a reason for the access change.');
    }
    $target = user_by_id($targetId);
    if (!$target) {
        throw new InvalidArgumentException('User not found.');
    }
    if ($targetId === $actorId && (!$makeAdmin || $status !== 'active')) {
        throw new InvalidArgumentException('Use another active admin account before disabling your own session.');
    }

    $targetWasActiveAdmin = (int)$target['is_admin'] === 1 && normalize_user_status((string)($target['status'] ?? 'active')) === 'active';
    $targetWouldStopBeingActiveAdmin = !$makeAdmin || $status !== 'active';
    if ($targetWasActiveAdmin && $targetWouldStopBeingActiveAdmin && active_admin_count() <= 1) {
        throw new InvalidArgumentException('At least one active admin must remain.');
    }

    $before = user_access_snapshot($target);
    $after = $before;
    $after['is_admin'] = $makeAdmin;
    $after['status'] = $status;
    if ($after === $before) {
        return ['id' => $targetId, 'changed' => false];
    }

    $stmt = db()->prepare("UPDATE users SET is_admin = ?, status = ? WHERE id = ?");
    $stmt->execute([$makeAdmin ? 1 : 0, $status, $targetId]);
    $after = user_access_snapshot(user_by_id($targetId));
    audit_user_access_change($actorId, 'user.update_access', $targetId, $before, $after, $reason);
    return ['id' => $targetId, 'changed' => true];
}

function reset_user_password_with_audit(int $actorId, int $targetId, string $password, string $reason): array {
    $reason = trim($reason);
    if ($reason === '') {
        throw new InvalidArgumentException('Enter a reason for the password reset.');
    }
    $target = user_by_id($targetId);
    if (!$target) {
        throw new InvalidArgumentException('User not found.');
    }
    $credential = $password;
    $generatedPassword = false;
    if ($credential === '') {
        $credential = generated_access_passphrase();
        $generatedPassword = true;
    }
    if (strlen($credential) < 8) {
        throw new InvalidArgumentException('Use an 8+ character temporary password.');
    }
    $before = user_access_snapshot($target) + ['password_reset_at' => null];
    db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($credential, PASSWORD_DEFAULT), $targetId]);
    $after = user_access_snapshot(user_by_id($targetId)) + ['password_reset_at' => now_iso()];
    audit_user_access_change($actorId, 'user.reset_password', $targetId, $before, $after, $reason);
    return ['id' => $targetId, 'changed' => true, 'password' => $credential, 'generated_password' => $generatedPassword];
}
