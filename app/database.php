<?php
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    global $CFG;
    if ($pdo instanceof PDO) return $pdo;

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        fatal_setup('SQLite driver missing', 'This server does not have PDO SQLite enabled. Enable the SQLite PDO extension (pdo_sqlite) or deploy to a host that supports PDO + SQLite.');
    }

    $pdo = new PDO('sqlite:' . $CFG['db_path'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("PRAGMA journal_mode=WAL");
    $pdo->exec("PRAGMA synchronous=NORMAL");
    $pdo->exec("PRAGMA foreign_keys=ON");
    init_db($pdo);
    return $pdo;
}

function init_db(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        display_name TEXT NOT NULL,
        phone TEXT NULL,
        is_admin INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'active',
        notify_email_enabled INTEGER NOT NULL DEFAULT 1,
        notify_sms_enabled INTEGER NOT NULL DEFAULT 0,
        notify_events_json TEXT NOT NULL DEFAULT '{}',
        created_at TEXT NOT NULL
    )");
    ensure_column($pdo, 'users', 'phone', "ALTER TABLE users ADD COLUMN phone TEXT NULL");
    ensure_column($pdo, 'users', 'status', "ALTER TABLE users ADD COLUMN status TEXT NOT NULL DEFAULT 'active'");
    ensure_column($pdo, 'users', 'notify_email_enabled', "ALTER TABLE users ADD COLUMN notify_email_enabled INTEGER NOT NULL DEFAULT 1");
    ensure_column($pdo, 'users', 'notify_sms_enabled', "ALTER TABLE users ADD COLUMN notify_sms_enabled INTEGER NOT NULL DEFAULT 0");
    ensure_column($pdo, 'users', 'notify_events_json', "ALTER TABLE users ADD COLUMN notify_events_json TEXT NOT NULL DEFAULT '{}'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS task_types (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        fields_json TEXT NOT NULL,
        active INTEGER NOT NULL DEFAULT 1,
        archived_at TEXT NULL,
        created_at TEXT NOT NULL
    )");
    ensure_column($pdo, 'task_types', 'active', "ALTER TABLE task_types ADD COLUMN active INTEGER NOT NULL DEFAULT 1");
    ensure_column($pdo, 'task_types', 'archived_at', "ALTER TABLE task_types ADD COLUMN archived_at TEXT NULL");

    $pdo->exec("CREATE TABLE IF NOT EXISTS requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        requester_id INTEGER NOT NULL,
        task_type_id INTEGER NOT NULL,
        code TEXT NULL,
        title TEXT NOT NULL,
        description TEXT NOT NULL,
        price_cents INTEGER NOT NULL DEFAULT 0,
        fee_cents INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL,
        runner_id INTEGER NULL,
        metadata TEXT NOT NULL,
        pickup_window_start TEXT NULL,
        pickup_window_end TEXT NULL,
        delivery_window_start TEXT NULL,
        delivery_window_end TEXT NULL,
        sla_due_at TEXT NULL,
        delivery_otp_hash TEXT NULL,
        delivery_otp_hint TEXT NULL,
        delivery_otp_created_at TEXT NULL,
        delivery_otp_verified_at TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (runner_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (task_type_id) REFERENCES task_types(id) ON DELETE RESTRICT
    )");
    ensure_column($pdo, 'requests', 'code', "ALTER TABLE requests ADD COLUMN code TEXT NULL");
    ensure_column($pdo, 'requests', 'pickup_window_start', "ALTER TABLE requests ADD COLUMN pickup_window_start TEXT NULL");
    ensure_column($pdo, 'requests', 'pickup_window_end', "ALTER TABLE requests ADD COLUMN pickup_window_end TEXT NULL");
    ensure_column($pdo, 'requests', 'delivery_window_start', "ALTER TABLE requests ADD COLUMN delivery_window_start TEXT NULL");
    ensure_column($pdo, 'requests', 'delivery_window_end', "ALTER TABLE requests ADD COLUMN delivery_window_end TEXT NULL");
    ensure_column($pdo, 'requests', 'sla_due_at', "ALTER TABLE requests ADD COLUMN sla_due_at TEXT NULL");
    ensure_column($pdo, 'requests', 'delivery_otp_hash', "ALTER TABLE requests ADD COLUMN delivery_otp_hash TEXT NULL");
    ensure_column($pdo, 'requests', 'delivery_otp_hint', "ALTER TABLE requests ADD COLUMN delivery_otp_hint TEXT NULL");
    ensure_column($pdo, 'requests', 'delivery_otp_created_at', "ALTER TABLE requests ADD COLUMN delivery_otp_created_at TEXT NULL");
    ensure_column($pdo, 'requests', 'delivery_otp_verified_at', "ALTER TABLE requests ADD COLUMN delivery_otp_verified_at TEXT NULL");
    backfill_request_codes($pdo);
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_requests_code ON requests(code)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_requests_status ON requests(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_requests_task_type ON requests(task_type_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_requests_requester_id ON requests(requester_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_requests_runner_id ON requests(runner_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_requests_price_cents ON requests(price_cents)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_requests_sla_due_at ON requests(sla_due_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_requests_created_at ON requests(created_at)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id INTEGER NOT NULL,
        actor_id INTEGER NULL,
        type TEXT NOT NULL,
        note TEXT NOT NULL,
        attachment_name TEXT NULL,
        attachment_original_name TEXT NULL,
        attachment_mime TEXT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
        FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
    )");
    ensure_column($pdo, 'events', 'attachment_name', "ALTER TABLE events ADD COLUMN attachment_name TEXT NULL");
    ensure_column($pdo, 'events', 'attachment_original_name', "ALTER TABLE events ADD COLUMN attachment_original_name TEXT NULL");
    ensure_column($pdo, 'events', 'attachment_mime', "ALTER TABLE events ADD COLUMN attachment_mime TEXT NULL");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_request_id ON events(request_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ratings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id INTEGER NOT NULL,
        rater_id INTEGER NOT NULL,
        ratee_id INTEGER NOT NULL,
        score INTEGER NOT NULL,
        note TEXT NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
        FOREIGN KEY (rater_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (ratee_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ratings_request_id ON ratings(request_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        actor_id INTEGER NULL,
        action TEXT NOT NULL,
        target_type TEXT NOT NULL,
        target_id INTEGER NULL,
        ip TEXT NOT NULL,
        user_agent TEXT NOT NULL,
        meta_json TEXT NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_created_at ON audit_log(created_at)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS imports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        kind TEXT NOT NULL,
        status TEXT NOT NULL,
        cursor INTEGER NOT NULL DEFAULT 0,
        total INTEGER NOT NULL DEFAULT 0,
        payload_json TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version TEXT PRIMARY KEY,
        description TEXT NOT NULL,
        applied_at TEXT NOT NULL
    )");
    $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (version, description, applied_at) VALUES (?, ?, ?)")
        ->execute(['2026-07-04-bootstrap', 'Current SQLite schema with modular LiteGig tables', now_iso()]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        rate_key TEXT PRIMARY KEY,
        hits INTEGER NOT NULL DEFAULT 0,
        window_start INTEGER NOT NULL,
        updated_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        channel TEXT NOT NULL,
        template TEXT NOT NULL,
        payload_json TEXT NOT NULL,
        status TEXT NOT NULL,
        retries INTEGER NOT NULL DEFAULT 0,
        last_error TEXT NOT NULL DEFAULT '',
        sent_at TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifications_status ON notifications(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifications_user_id ON notifications(user_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id INTEGER NOT NULL UNIQUE,
        method TEXT NOT NULL,
        amount_cents INTEGER NOT NULL,
        fee_cents INTEGER NOT NULL,
        status TEXT NOT NULL,
        confirmed_by INTEGER NULL,
        confirmed_at TEXT NULL,
        receipt_no TEXT NOT NULL UNIQUE,
        gateway_reference TEXT NULL,
        gateway_event_id TEXT NULL,
        gateway_payload_json TEXT NOT NULL DEFAULT '{}',
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
        FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    ensure_column($pdo, 'payments', 'gateway_reference', "ALTER TABLE payments ADD COLUMN gateway_reference TEXT NULL");
    ensure_column($pdo, 'payments', 'gateway_event_id', "ALTER TABLE payments ADD COLUMN gateway_event_id TEXT NULL");
    ensure_column($pdo, 'payments', 'gateway_payload_json', "ALTER TABLE payments ADD COLUMN gateway_payload_json TEXT NOT NULL DEFAULT '{}'");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_payments_status ON payments(status)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_payments_gateway_reference ON payments(gateway_reference)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_webhook_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id TEXT NOT NULL UNIQUE,
        gateway_reference TEXT NOT NULL,
        status TEXT NOT NULL,
        payload_json TEXT NOT NULL,
        processed_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_payment_webhook_reference ON payment_webhook_events(gateway_reference)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS saved_views (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        scope TEXT NOT NULL,
        name TEXT NOT NULL,
        query_json TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_saved_views_user_scope ON saved_views(user_id, scope)");

    $count = (int)$pdo->query("SELECT COUNT(*) AS c FROM task_types")->fetch()['c'];
    if ($count === 0) {
        $stmt = $pdo->prepare("INSERT INTO task_types (name, fields_json, created_at) VALUES (?, ?, ?)");
        foreach (default_task_types() as $tt) {
            $stmt->execute([$tt['name'], json_encode($tt['fields'], JSON_UNESCAPED_SLASHES), now_iso()]);
        }
    }
}

function default_task_types(): array {
    return [
        [
            'name' => 'Delivery',
            'fields' => [
                'summary_fields' => ['pickup', 'dropoff', 'price_cents', 'note'],
                'fields' => [
                    ['key' => 'pickup', 'label' => 'Pickup', 'type' => 'geo', 'required' => true, 'placeholder' => 'Address / area'],
                    ['key' => 'dropoff', 'label' => 'Dropoff', 'type' => 'geo', 'required' => true, 'placeholder' => 'Address / area'],
                    ['key' => 'price_cents', 'label' => 'Price (USD)', 'type' => 'price', 'required' => true, 'placeholder' => 'e.g., 15'],
                    ['key' => 'note', 'label' => 'Brief note', 'type' => 'textarea', 'required' => false, 'placeholder' => 'Anything the runner should know'],
                ],
            ],
        ],
        [
            'name' => 'Buy-and-Bring',
            'fields' => [
                'summary_fields' => ['store', 'delivery', 'budget_cents', 'price_cents'],
                'fields' => [
                    ['key' => 'store', 'label' => 'Store / pickup', 'type' => 'geo', 'required' => true, 'placeholder' => 'Store address'],
                    ['key' => 'items', 'label' => 'Items to buy', 'type' => 'textarea', 'required' => true, 'placeholder' => 'List items, sizes, brands'],
                    ['key' => 'budget_cents', 'label' => 'Max budget (USD)', 'type' => 'price', 'required' => false, 'placeholder' => 'Optional'],
                    ['key' => 'delivery', 'label' => 'Delivery address', 'type' => 'geo', 'required' => true, 'placeholder' => 'Where to bring the items'],
                    ['key' => 'price_cents', 'label' => 'Runner fee (USD)', 'type' => 'price', 'required' => true, 'placeholder' => 'e.g., 20'],
                    ['key' => 'note', 'label' => 'Brief note', 'type' => 'textarea', 'required' => false, 'placeholder' => 'Substitutions allowed? Timing?'],
                ],
            ],
        ],
        [
            'name' => 'Flyer Distribution',
            'fields' => [
                'summary_fields' => ['preferred_area', 'num_copies', 'time_start', 'time_end', 'price_cents'],
                'fields' => [
                    ['key' => 'preferred_area', 'label' => 'Preferred area', 'type' => 'select', 'required' => true, 'options' => [
                        ['value' => 'downtown', 'label' => 'Downtown'],
                        ['value' => 'university', 'label' => 'University'],
                        ['value' => 'suburbs', 'label' => 'Suburbs'],
                        ['value' => 'other', 'label' => 'Other'],
                    ]],
                    ['key' => 'area_notes', 'label' => 'Area notes', 'type' => 'textarea', 'required' => false, 'placeholder' => 'Cross streets, neighborhoods, exclusions'],
                    ['key' => 'num_copies', 'label' => 'Number of flyers', 'type' => 'number', 'required' => true, 'placeholder' => 'e.g., 500'],
                    ['key' => 'time_start', 'label' => 'Time window start', 'type' => 'datetime', 'required' => false],
                    ['key' => 'time_end', 'label' => 'Time window end', 'type' => 'datetime', 'required' => false],
                    ['key' => 'pickup', 'label' => 'Flyer pickup (optional)', 'type' => 'geo', 'required' => false, 'placeholder' => 'Where to pick up the flyers'],
                    ['key' => 'price_cents', 'label' => 'Price (USD)', 'type' => 'price', 'required' => true, 'placeholder' => 'e.g., 45'],
                    ['key' => 'note', 'label' => 'Brief note', 'type' => 'textarea', 'required' => false, 'placeholder' => 'Distribution rules / photos needed?'],
                ],
            ],
        ],
    ];
}

function audit_log(?int $actorId, string $action, string $targetType, ?int $targetId, array $meta = []): void {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO audit_log (actor_id, action, target_type, target_id, ip, user_agent, meta_json, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $actorId,
        $action,
        $targetType,
        $targetId,
        client_ip(),
        user_agent(),
        json_encode($meta, JSON_UNESCAPED_SLASHES),
        now_iso(),
    ]);
}

function setting_get(string $key, string $default = ''): string {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? (string)$row['setting_value'] : $default;
}

function setting_set(string $key, string $value): void {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, ?)
        ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = excluded.updated_at");
    $stmt->execute([$key, $value, now_iso()]);
}

function app_fee_percent(): float {
    global $CFG;
    $raw = setting_get('default_fee_percent', (string)$CFG['default_fee_percent']);
    return max(0.0, min(50.0, is_numeric($raw) ? (float)$raw : (float)$CFG['default_fee_percent']));
}

function generate_request_code(PDO $pdo): string {
    for ($i = 0; $i < 20; $i++) {
        $code = 'LG-' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM requests WHERE code = ?");
        $stmt->execute([$code]);
        if ((int)$stmt->fetch()['c'] === 0) return $code;
    }
    return 'LG-' . strtoupper(bin2hex(random_bytes(6)));
}

function backfill_request_codes(PDO $pdo): void {
    $rows = $pdo->query("SELECT id FROM requests WHERE code IS NULL OR code = '' ORDER BY id ASC LIMIT 1000")->fetchAll();
    if (!$rows) return;
    $stmt = $pdo->prepare("UPDATE requests SET code = ? WHERE id = ? AND (code IS NULL OR code = '')");
    foreach ($rows as $row) {
        $stmt->execute([generate_request_code($pdo), (int)$row['id']]);
    }
}

function generate_receipt_no(PDO $pdo): string {
    for ($i = 0; $i < 20; $i++) {
        $receipt = 'RCPT-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM payments WHERE receipt_no = ?");
        $stmt->execute([$receipt]);
        if ((int)$stmt->fetch()['c'] === 0) return $receipt;
    }
    return 'RCPT-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
}

function ensure_column(PDO $pdo, string $table, string $column, string $sql): void {
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    foreach ($stmt->fetchAll() as $row) {
        if ((string)$row['name'] === $column) return;
    }
    $pdo->exec($sql);
}

function add_event(int $requestId, ?int $actorId, string $type, string $note, ?array $attachment = null): void {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO events
        (request_id, actor_id, type, note, attachment_name, attachment_original_name, attachment_mime, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $requestId,
        $actorId,
        $type,
        $note,
        is_array($attachment) ? ($attachment['name'] ?? null) : null,
        is_array($attachment) ? ($attachment['original_name'] ?? null) : null,
        is_array($attachment) ? ($attachment['mime'] ?? null) : null,
        now_iso(),
    ]);
}

function enforce_rate_limit(string $key, int $limit, int $windowSec, string $message = 'Too many requests. Try again later.'): void {
    $pdo = db();
    $now = time();
    $stmt = $pdo->prepare("SELECT hits, window_start FROM rate_limits WHERE rate_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    if (!$row || ($now - (int)$row['window_start']) >= $windowSec) {
        $pdo->prepare("DELETE FROM rate_limits WHERE rate_key = ?")->execute([$key]);
        $insert = $pdo->prepare("INSERT INTO rate_limits (rate_key, hits, window_start, updated_at) VALUES (?, 1, ?, ?)");
        $insert->execute([$key, $now, now_iso()]);
        return;
    }

    if ((int)$row['hits'] >= $limit) {
        http_response_code(429);
        header('Retry-After: ' . max(1, $windowSec - ($now - (int)$row['window_start'])));
        if (function_exists('render_layout') && function_exists('render_state_box')) {
            render_layout('Rate limited', render_state_box('Too many attempts', $message, [
                ['label' => 'Back to requests', 'href' => '?action=list_requests', 'primary' => true],
            ], 'warn'));
            exit;
        }
        echo $message;
        exit;
    }

    $inc = $pdo->prepare("UPDATE rate_limits SET hits = hits + 1, updated_at = ? WHERE rate_key = ?");
    $inc->execute([now_iso(), $key]);
}

function enforce_login_rate_limit(string $email): void {
    global $CFG;
    $normalized = strtolower(trim($email));
    enforce_rate_limit('login:' . client_ip() . ':' . hash('sha256', $normalized), (int)$CFG['rate_login_limit'], (int)$CFG['rate_login_window_sec'], 'Too many login attempts. Try again later.');
}

function enforce_critical_rate_limit(string $action, int $userId): void {
    global $CFG;
    enforce_rate_limit('critical:' . $action . ':' . $userId . ':' . client_ip(), (int)$CFG['rate_critical_limit'], (int)$CFG['rate_critical_window_sec']);
}
