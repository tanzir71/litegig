<?php
/*
README (Deployment – shared hosting, PHP 8+ / SQLite)
1) Upload this single file as `litegig.php` to your web root (e.g., `public_html/`).
2) Ensure the folder is writable by PHP so it can create `litegig.db` and `uploads/`.
3) Visit `https://your-domain.com/litegig.php` to auto-initialize the database.
4) Register the first user — it becomes admin automatically.
5) Admin: manage Task Types via “Task Types” (schema-driven templates).
6) Optional: Admin → “Load Sample Data” to populate demo users/requests.
7) Optional: toggle CSV PII export in the config block (`export_pii`).
8) Optional cron: run `php litegig.php action=cron_cleanup token=YOUR_TOKEN`.
9) Keep payments peer-to-peer; this app only records manual confirmations.
10) Back up `litegig.db` regularly (download via hosting file manager).
*/

/*
Customize here
- accentColor, fee percent, session timeout, upload directory
- toggle CSV PII export with `export_pii` (default: minimal PII)
- adjust default task types in `default_task_types()` below
*/

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . DIRECTORY_SEPARATOR . 'litegig_error.log');

$CFG = [
    'app_name' => 'LiteGig',
    'db_path' => __DIR__ . DIRECTORY_SEPARATOR . 'litegig.db',
    'upload_dir' => __DIR__ . DIRECTORY_SEPARATOR . 'uploads',
    'currency' => 'USD',
    'default_fee_percent' => 8.0,
    'accentColor' => '#1A73E8',
    'poll_ms' => 15000,
    'session_timeout_sec' => 60 * 60 * 24 * 7,
    'export_pii' => false,
    'cron_token' => '',
    'cleanup_stale_new_days' => 14,
];

date_default_timezone_set(@date_default_timezone_get() ?: 'UTC');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (!isset($_SESSION['__last_activity'])) {
    $_SESSION['__last_activity'] = time();
}
if (time() - (int)$_SESSION['__last_activity'] > (int)$CFG['session_timeout_sec']) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    session_start();
}
$_SESSION['__last_activity'] = time();

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function now_iso(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 200); }
function user_agent(): string { return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400); }
function redirect_to(string $url): void { header('Location: ' . $url); exit; }

function flash_set(string $type, string $msg): void {
    $_SESSION['__flash'][] = ['type' => $type, 'msg' => $msg];
}
function flash_get_all(): array {
    $msgs = $_SESSION['__flash'] ?? [];
    unset($_SESSION['__flash']);
    return $msgs;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf'];
}
function require_csrf(): void {
    $token = (string)($_POST['csrf'] ?? '');
    if (!$token || !hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) {
        http_response_code(400);
        echo 'Bad Request (CSRF)';
        exit;
    }
}

function fatal_setup(string $title, string $message): void {
    http_response_code(500);
    $t = h($title);
    $m = h($message);
    echo "<!doctype html><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">";
    echo "<title>{$t}</title><style>body{font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:0;padding:20px;max-width:760px}h1{font-size:18px}pre{white-space:pre-wrap;background:#fafafa;border:1px solid #e6e6e6;border-radius:12px;padding:12px}</style>";
    echo "<h1>{$t}</h1><pre>{$m}</pre>";
    exit;
}

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
        is_admin INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS task_types (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        fields_json TEXT NOT NULL,
        created_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        requester_id INTEGER NOT NULL,
        task_type_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        description TEXT NOT NULL,
        price_cents INTEGER NOT NULL DEFAULT 0,
        fee_cents INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL,
        runner_id INTEGER NULL,
        metadata TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (runner_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (task_type_id) REFERENCES task_types(id) ON DELETE RESTRICT
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_requests_status ON requests(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_requests_task_type ON requests(task_type_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_requests_created_at ON requests(created_at)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id INTEGER NOT NULL,
        actor_id INTEGER NULL,
        type TEXT NOT NULL,
        note TEXT NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
        FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
    )");
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

function add_event(int $requestId, ?int $actorId, string $type, string $note): void {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO events (request_id, actor_id, type, note, created_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$requestId, $actorId, $type, $note, now_iso()]);
}

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, email, display_name, is_admin, created_at FROM users WHERE id = ?");
    $stmt->execute([(int)$_SESSION['uid']]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        flash_set('error', 'Please log in.');
        redirect_to('?action=login');
    }
    return $u;
}

function require_admin(): array {
    $u = require_login();
    if ((int)$u['is_admin'] !== 1) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    return $u;
}

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
        'created_at' => (string)($row['created_at'] ?? ''),
    ];
}

function get_task_types(): array {
    $pdo = db();
    $rows = $pdo->query("SELECT * FROM task_types ORDER BY name ASC")->fetchAll();
    return array_map('normalize_task_type_row', $rows);
}

function get_task_type_by_id(int $id): ?array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM task_types WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? normalize_task_type_row($row) : null;
}

function sanitize_upload_filename(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'file';
    $name = trim($name, '._-');
    if ($name === '') $name = 'file';
    return substr($name, 0, 120);
}

function ensure_upload_dir(): void {
    global $CFG;
    if (!is_dir($CFG['upload_dir'])) {
        @mkdir($CFG['upload_dir'], 0755, true);
    }
}

function parse_price_to_cents(string $raw): ?int {
    $raw = trim($raw);
    if ($raw === '') return null;
    $raw = preg_replace('/[^0-9.,-]/', '', $raw) ?? '';
    if ($raw === '') return null;
    $raw = str_replace(',', '.', $raw);
    if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $raw)) return null;
    $f = (float)$raw;
    return (int)round($f * 100);
}

function format_cents(int $cents): string {
    $sign = $cents < 0 ? '-' : '';
    $v = abs($cents);
    $d = number_format($v / 100, 2, '.', '');
    return $sign . '$' . $d;
}

function render_task_type_badge(string $name): string {
    return '<span class="badge">' . h($name) . '</span>';
}

function field_label_map(array $taskType): array {
    $m = [];
    foreach ($taskType['fields'] as $f) {
        if (!is_array($f)) continue;
        $k = (string)($f['key'] ?? '');
        if ($k === '') continue;
        $m[$k] = (string)($f['label'] ?? $k);
    }
    return $m;
}

function coerce_metadata_from_post(array $taskType, array $post, array $files, array &$errors, array $prevMeta = []): array {
    global $CFG;
    $meta = [];

    foreach ($taskType['fields'] as $field) {
        if (!is_array($field)) continue;
        $key = (string)($field['key'] ?? '');
        $type = (string)($field['type'] ?? 'text');
        $required = (bool)($field['required'] ?? false);
        if ($key === '') continue;

        $value = null;

        if ($type === 'geo') {
            $addr = trim((string)($post[$key . '_address'] ?? ''));
            $latRaw = trim((string)($post[$key . '_lat'] ?? ''));
            $lngRaw = trim((string)($post[$key . '_lng'] ?? ''));
            $lat = ($latRaw === '' || !is_numeric($latRaw)) ? null : (float)$latRaw;
            $lng = ($lngRaw === '' || !is_numeric($lngRaw)) ? null : (float)$lngRaw;
            if ($required && $addr === '') $errors[$key] = 'Required.';
            $value = ['address' => $addr, 'lat' => $lat, 'lng' => $lng];
        } elseif ($type === 'attachment') {
            $existing = $prevMeta[$key] ?? null;
            $value = is_string($existing) ? $existing : null;

            if (!empty($files[$key]) && is_array($files[$key]) && ($files[$key]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if (($files[$key]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    $errors[$key] = 'Upload failed.';
                } else {
                    ensure_upload_dir();
                    $orig = (string)($files[$key]['name'] ?? 'file');
                    $safe = sanitize_upload_filename($orig);
                    $ext = '';
                    $dot = strrpos($safe, '.');
                    if ($dot !== false) $ext = substr($safe, $dot);
                    $final = 'att_' . bin2hex(random_bytes(8)) . $ext;
                    $dest = $CFG['upload_dir'] . DIRECTORY_SEPARATOR . $final;
                    if (!move_uploaded_file((string)$files[$key]['tmp_name'], $dest)) {
                        $errors[$key] = 'Could not save file.';
                    } else {
                        $value = $final;
                    }
                }
            } elseif ($required && (!$value || !is_string($value))) {
                $errors[$key] = 'Required.';
            }
        } else {
            $raw = $post[$key] ?? '';

            if ($type === 'boolean') {
                $value = (!empty($raw) && ($raw === '1' || $raw === 'on' || $raw === 'true')) ? 1 : 0;
            } elseif ($type === 'number') {
                $raw = trim((string)$raw);
                if ($raw === '') {
                    $value = null;
                    if ($required) $errors[$key] = 'Required.';
                } elseif (!is_numeric($raw)) {
                    $errors[$key] = 'Must be a number.';
                    $value = $raw;
                } else {
                    $n = (float)$raw;
                    $value = (floor($n) == $n) ? (int)$n : $n;
                }
            } elseif ($type === 'price') {
                $raw = trim((string)$raw);
                if ($raw === '') {
                    $value = null;
                    if ($required) $errors[$key] = 'Required.';
                } else {
                    $c = parse_price_to_cents($raw);
                    if ($c === null) {
                        $errors[$key] = 'Invalid price.';
                        $value = $raw;
                    } else {
                        $value = $c;
                    }
                }
            } elseif (in_array($type, ['datetime', 'date', 'time'], true)) {
                $raw = trim((string)$raw);
                if ($raw === '') {
                    $value = '';
                    if ($required) $errors[$key] = 'Required.';
                } else {
                    $value = $raw;
                }
            } elseif ($type === 'select') {
                $raw = (string)$raw;
                $value = $raw;
                if ($required && trim($raw) === '') $errors[$key] = 'Required.';
                $opts = $field['options'] ?? [];
                if (is_array($opts) && $raw !== '') {
                    $allowed = [];
                    foreach ($opts as $o) {
                        if (!is_array($o)) continue;
                        $allowed[] = (string)($o['value'] ?? '');
                    }
                    if (!in_array($raw, $allowed, true)) $errors[$key] = 'Invalid option.';
                }
            } else {
                $raw = trim((string)$raw);
                $value = $raw;
                if ($required && $raw === '') $errors[$key] = 'Required.';
            }
        }

        $meta[$key] = $value;
    }

    return $meta;
}

function request_primary_price_cents(array $taskType, array $meta): int {
    foreach ($taskType['fields'] as $f) {
        if (!is_array($f)) continue;
        if (($f['type'] ?? '') !== 'price') continue;
        $k = (string)($f['key'] ?? '');
        if ($k === 'price_cents' && isset($meta[$k]) && is_int($meta[$k])) return $meta[$k];
    }
    foreach ($taskType['fields'] as $f) {
        if (!is_array($f)) continue;
        if (($f['type'] ?? '') !== 'price') continue;
        $k = (string)($f['key'] ?? '');
        if ($k && isset($meta[$k]) && is_int($meta[$k])) return $meta[$k];
    }
    return 0;
}

function request_summary_value(array $taskType, array $meta, string $key): string {
    $fieldByKey = [];
    foreach ($taskType['fields'] as $f) {
        if (!is_array($f)) continue;
        $k = (string)($f['key'] ?? '');
        if ($k) $fieldByKey[$k] = $f;
    }
    $field = $fieldByKey[$key] ?? null;
    $v = $meta[$key] ?? null;
    if (!$field) {
        if ($v === null) return '';
        if (is_array($v)) return json_encode($v, JSON_UNESCAPED_SLASHES);
        return (string)$v;
    }
    $type = (string)($field['type'] ?? 'text');
    if ($type === 'geo') {
        if (is_array($v)) return (string)($v['address'] ?? '');
        return '';
    }
    if ($type === 'price') {
        return is_int($v) ? format_cents($v) : '';
    }
    if ($type === 'boolean') {
        return ((int)$v === 1) ? 'Yes' : 'No';
    }
    if ($type === 'select') {
        $opts = $field['options'] ?? [];
        if (is_array($opts)) {
            foreach ($opts as $o) {
                if (!is_array($o)) continue;
                if ((string)($o['value'] ?? '') === (string)$v) return (string)($o['label'] ?? $v);
            }
        }
        return (string)$v;
    }
    if (is_array($v)) return json_encode($v, JSON_UNESCAPED_SLASHES);
    return (string)$v;
}

function infer_summary_keys(array $taskType): array {
    if (!empty($taskType['summary_fields'])) {
        return array_values(array_filter(array_map('strval', $taskType['summary_fields'])));
    }
    $candidates = [];
    foreach ($taskType['fields'] as $f) {
        if (!is_array($f)) continue;
        $k = (string)($f['key'] ?? '');
        $t = (string)($f['type'] ?? '');
        if ($k === '') continue;
        if (in_array($k, ['pickup', 'dropoff', 'delivery', 'store', 'target', 'area', 'preferred_area', 'num_copies', 'price_cents'], true)) {
            $candidates[] = $k;
            continue;
        }
        if ($t === 'price' && $k === 'price_cents') $candidates[] = $k;
        if ($t === 'geo' && count($candidates) < 2) $candidates[] = $k;
    }
    return array_slice(array_values(array_unique($candidates)), 0, 4);
}

function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

function request_first_geo(array $taskType, array $meta): ?array {
    foreach ($taskType['fields'] as $f) {
        if (!is_array($f)) continue;
        if (($f['type'] ?? '') !== 'geo') continue;
        $k = (string)($f['key'] ?? '');
        $v = $meta[$k] ?? null;
        if (is_array($v)) {
            $lat = $v['lat'] ?? null;
            $lng = $v['lng'] ?? null;
            if ((is_float($lat) || is_int($lat)) && (is_float($lng) || is_int($lng))) {
                return ['lat' => (float)$lat, 'lng' => (float)$lng, 'address' => (string)($v['address'] ?? '')];
            }
        }
    }
    return null;
}

function render_layout(string $title, string $content): void {
    global $CFG;
    $u = current_user();
    $flashes = flash_get_all();

    $accent = (string)$CFG['accentColor'];
    $appName = (string)$CFG['app_name'];
    $isAdmin = $u && ((int)$u['is_admin'] === 1);

    $nav = '';
    if ($u) {
        $nav .= '<a class="navlink" href="?action=list_requests">Requests</a>';
        $nav .= '<a class="navlink" href="?action=create_request">Create</a>';
        if ($isAdmin) {
            $nav .= '<a class="navlink" href="?action=list_task_types">Task Types</a>';
            $nav .= '<a class="navlink" href="?action=export_csv">Export</a>';
            $nav .= '<a class="navlink" href="?action=load_sample_data">Load Sample</a>';
        }
        $nav .= '<a class="navlink" href="?action=logout">Logout</a>';
    } else {
        $nav .= '<a class="navlink" href="?action=login">Login</a>';
        $nav .= '<a class="navlink" href="?action=register">Register</a>';
    }

    $meta = '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    $inter = '<link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">';

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">' . $meta . '<title>' . h($title) . ' · ' . h($appName) . '</title>' . $inter;
    echo '<style>';
    echo ':root{--accent:' . h($accent) . ';--bg:#fff;--fg:#111;--muted:#666;--line:#e6e6e6;--card:#fafafa;--danger:#b00020;--ok:#0b6b34;}'
        . 'html,body{margin:0;padding:0;background:var(--bg);color:var(--fg);font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;}'
        . 'a{color:var(--accent);text-decoration:none;}'
        . '.wrap{max-width:820px;margin:0 auto;padding:14px 12px 40px;}'
        . '.top{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 0 14px;border-bottom:1px solid var(--line);}'
        . '.brand{font-weight:800;letter-spacing:-.02em;font-size:18px;}'
        . '.nav{display:flex;flex-wrap:wrap;gap:10px;justify-content:flex-end;}'
        . '.navlink{display:inline-block;padding:8px 10px;border:1px solid var(--line);border-radius:10px;color:var(--fg);font-weight:600;font-size:13px;}'
        . '.navlink:active{transform:translateY(1px);}'
        . '.card{border:1px solid var(--line);background:var(--card);border-radius:14px;padding:14px;margin:12px 0;}'
        . '.row{display:flex;gap:10px;align-items:flex-start;justify-content:space-between;}'
        . '.stack{display:flex;flex-direction:column;gap:8px;}'
        . '.title{font-size:18px;font-weight:800;letter-spacing:-.02em;}'
        . '.sub{color:var(--muted);font-size:13px;}'
        . '.badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border:1px solid var(--line);border-radius:999px;font-weight:700;font-size:12px;background:#fff;}'
        . '.price{font-weight:900;font-size:16px;}'
        . '.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:#fff;color:var(--fg);font-weight:800;font-size:14px;cursor:pointer;}'
        . '.btn-primary{background:var(--accent);border-color:var(--accent);color:#fff;}'
        . '.btn-danger{background:var(--danger);border-color:var(--danger);color:#fff;}'
        . '.btn:disabled{opacity:.6;cursor:not-allowed;}'
        . '.btnblock{width:100%;}'
        . '.grid{display:grid;grid-template-columns:1fr;gap:10px;}'
        . 'label{display:block;font-weight:700;font-size:13px;margin:0 0 6px;}'
        . 'input,select,textarea{width:100%;box-sizing:border-box;border:1px solid var(--line);border-radius:12px;padding:12px 12px;font-size:15px;background:#fff;}'
        . 'textarea{min-height:92px;resize:vertical;}'
        . '.help{color:var(--muted);font-size:12px;margin-top:6px;}'
        . '.err{color:var(--danger);font-size:12px;margin-top:6px;}'
        . '.ok{color:var(--ok);font-size:12px;margin-top:6px;}'
        . '.list{display:flex;flex-direction:column;gap:10px;}'
        . '.item{border:1px solid var(--line);border-radius:14px;padding:12px;background:#fff;}'
        . '.itemtop{display:flex;gap:10px;align-items:flex-start;justify-content:space-between;}'
        . '.itemtitle{font-weight:900;font-size:15px;margin:0 0 2px;}'
        . '.itemmeta{color:var(--muted);font-size:12px;line-height:1.35;}'
        . '.pill{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid var(--line);font-size:12px;font-weight:800;background:var(--card);}'
        . '.table{width:100%;border-collapse:collapse;}'
        . '.table td{padding:10px 0;border-bottom:1px solid var(--line);vertical-align:top;}'
        . '.table td:first-child{color:var(--muted);width:38%;padding-right:12px;}'
        . '.foot{margin-top:14px;color:var(--muted);font-size:12px;line-height:1.45;}'
        . '.flash{border-radius:12px;padding:10px 12px;margin:10px 0;border:1px solid var(--line);background:#fff;}'
        . '.flash.error{border-color:rgba(176,0,32,.25);background:rgba(176,0,32,.05);}'
        . '.flash.ok{border-color:rgba(11,107,52,.25);background:rgba(11,107,52,.06);}'
        . '.split{display:grid;grid-template-columns:1fr;gap:10px;}'
        . '@media(min-width:720px){.grid{grid-template-columns:1fr 1fr;}.split{grid-template-columns:1fr 1fr;}}'
        . '</style>';
    echo '</head><body><div class="wrap">';
    echo '<div class="top"><div class="brand"><a href="?">' . h($appName) . '</a></div><div class="nav">' . $nav . '</div></div>';

    foreach ($flashes as $f) {
        $cls = ($f['type'] === 'error') ? 'error' : 'ok';
        echo '<div class="flash ' . $cls . '">' . h($f['msg']) . '</div>';
    }

    echo $content;
    echo '<div class="foot">Payments are peer-to-peer. LiteGig only records manual confirmations; it does not process or store payment details.</div>';
    echo '</div></body></html>';
}

function render_auth_gate(): void {
    $html = '<div class="card"><div class="title">LiteGig</div>'
        . '<div class="sub">Minimal, schema-driven, on-demand gigs. Create tasks, accept gigs, confirm pickup/payment/delivery, and rate each other.</div>'
        . '<div style="margin-top:12px" class="grid">'
        . '<a class="btn btn-primary btnblock" href="?action=register">Create account</a>'
        . '<a class="btn btnblock" href="?action=login">Login</a>'
        . '</div></div>';
    render_layout('Welcome', $html);
}

function user_by_email(string $email): ?array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE lower(email) = lower(?)");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function count_users(): int {
    return (int)db()->query("SELECT COUNT(*) AS c FROM users")->fetch()['c'];
}

function render_register_form(string $email, string $name, array $errors): string {
    $e = fn(string $k) => isset($errors[$k]) ? '<div class="err">' . h($errors[$k]) . '</div>' : '';
    return '<div class="card"><div class="title">Create account</div>'
        . '<form method="post" action="?action=register" class="stack" style="margin-top:10px">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<div><label>Email</label><input inputmode="email" name="email" value="' . h($email) . '" autocomplete="email" required>' . $e('email') . '</div>'
        . '<div><label>Name</label><input name="display_name" value="' . h($name) . '" autocomplete="name" required>' . $e('display_name') . '</div>'
        . '<div><label>Password</label><input type="password" name="password" autocomplete="new-password" required>'
        . '<div class="help">8+ characters. Stored as a password hash.</div>' . $e('password') . '</div>'
        . '<button class="btn btn-primary btnblock" type="submit">Register</button>'
        . '<a class="btn btnblock" href="?action=login">I already have an account</a>'
        . '</form></div>';
}

function action_register(): void {
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $email = trim((string)($_POST['email'] ?? ''));
        $name = trim((string)($_POST['display_name'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email.';
        if ($name === '') $errors['display_name'] = 'Enter your name.';
        if (strlen($pass) < 8) $errors['password'] = 'Use 8+ characters.';
        if (!$errors && user_by_email($email)) $errors['email'] = 'Email already registered.';

        if (!$errors) {
            $isFirst = count_users() === 0;
            $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, display_name, is_admin, created_at) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $name, $isFirst ? 1 : 0, now_iso()]);
            $uid = (int)$pdo->lastInsertId();
            $_SESSION['uid'] = $uid;
            audit_log($uid, 'register', 'user', $uid, ['is_admin' => $isFirst]);
            flash_set('ok', $isFirst ? 'Account created. You are admin (first user).' : 'Account created.');
            redirect_to('?action=list_requests');
        }

        render_layout('Register', render_register_form($email, $name, $errors));
        return;
    }
    render_layout('Register', render_register_form('', '', []));
}

function render_login_form(string $email, array $errors): string {
    $e = fn(string $k) => isset($errors[$k]) ? '<div class="err">' . h($errors[$k]) . '</div>' : '';
    return '<div class="card"><div class="title">Login</div>'
        . '<form method="post" action="?action=login" class="stack" style="margin-top:10px">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<div><label>Email</label><input inputmode="email" name="email" value="' . h($email) . '" autocomplete="email" required></div>'
        . '<div><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div>'
        . $e('form')
        . '<button class="btn btn-primary btnblock" type="submit">Login</button>'
        . '<a class="btn btnblock" href="?action=register">Create account</a>'
        . '</form></div>';
}

function action_login(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $email = trim((string)($_POST['email'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $u = user_by_email($email);
        if ($u && password_verify($pass, (string)$u['password_hash'])) {
            $_SESSION['uid'] = (int)$u['id'];
            audit_log((int)$u['id'], 'login', 'user', (int)$u['id']);
            flash_set('ok', 'Logged in.');
            redirect_to('?action=list_requests');
        }
        audit_log($u ? (int)$u['id'] : null, 'login_failed', 'user', $u ? (int)$u['id'] : null, ['email' => $email]);
        render_layout('Login', render_login_form($email, ['form' => 'Invalid email or password.']));
        return;
    }
    render_layout('Login', render_login_form('', []));
}

function action_logout(): void {
    $u = current_user();
    if ($u) audit_log((int)$u['id'], 'logout', 'user', (int)$u['id']);
    $_SESSION = [];
    session_destroy();
    session_start();
    flash_set('ok', 'Logged out.');
    redirect_to('?');
}

function action_list_task_types(): void {
    require_admin();
    $types = get_task_types();
    $rows = '';
    foreach ($types as $t) {
        $rows .= '<div class="item">'
            . '<div class="itemtop">'
            . '<div><div class="itemtitle">' . h($t['name']) . '</div>'
            . '<div class="itemmeta">id ' . (int)$t['id'] . ' · created ' . h($t['created_at']) . '</div></div>'
            . '<div class="stack" style="min-width:140px">'
            . '<a class="btn" href="?action=edit_task_type&id=' . (int)$t['id'] . '">Edit</a>'
            . '</div>'
            . '</div>'
            . '</div>';
    }
    $html = '<div class="card"><div class="row"><div>'
        . '<div class="title">Task Types</div>'
        . '<div class="sub">Define schemas that drive request forms and detail views.</div>'
        . '</div><div><a class="btn btn-primary" href="?action=create_task_type">New</a></div></div></div>'
        . '<div class="list">' . ($rows ?: '<div class="item">No task types yet.</div>') . '</div>';
    render_layout('Task Types', $html);
}

function validate_task_type_json(string $json, array &$out): ?string {
    $decoded = json_decode($json, true);
    if ($decoded === null) return 'Invalid JSON.';
    $fields = $decoded;
    $summary = [];
    if (is_array($decoded)) {
        $isAssoc = array_keys($decoded) !== range(0, count($decoded) - 1);
        if ($isAssoc) {
            $fields = $decoded['fields'] ?? null;
            $summary = $decoded['summary_fields'] ?? [];
        }
    }
    if (!is_array($fields)) return 'Expected an array of fields, or an object with {fields:[...], summary_fields:[...]}.';
    foreach ($fields as $f) {
        if (!is_array($f)) return 'Every field must be an object.';
        if (empty($f['key']) || !is_string($f['key'])) return 'Every field needs a string `key`.';
        if (empty($f['label']) || !is_string($f['label'])) return 'Every field needs a string `label`.';
        $type = (string)($f['type'] ?? '');
        $allowed = ['text', 'textarea', 'number', 'price', 'boolean', 'date', 'time', 'datetime', 'geo', 'select', 'attachment', 'note', 'readonly'];
        if (!in_array($type, $allowed, true)) return 'Invalid type: ' . $type;
        if ($type === 'select') {
            $opts = $f['options'] ?? null;
            if (!is_array($opts) || count($opts) === 0) return 'Select fields require `options`.';
        }
    }
    if (!is_array($summary)) $summary = [];
    $out = $decoded;
    return null;
}

function render_task_type_form(string $mode, array $values, array $errors): string {
    $e = fn(string $k) => isset($errors[$k]) ? '<div class="err">' . h($errors[$k]) . '</div>' : '';
    $idPart = ($mode === 'edit') ? ('&id=' . (int)$values['id']) : '';
    $title = ($mode === 'edit') ? 'Edit task type' : 'New task type';
    $name = (string)($values['name'] ?? '');
    $fieldsJson = (string)($values['fields_json'] ?? "[]");
    $html = '<div class="card"><div class="title">' . h($title) . '</div>'
        . '<div class="sub">Fields JSON supports: text, textarea, number, price, boolean, date/time/datetime, geo, select, attachment, note/readonly.</div>'
        . '<form method="post" action="?action=' . ($mode === 'edit' ? 'edit_task_type' : 'create_task_type') . $idPart . '" class="stack" style="margin-top:10px">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<div><label>Name</label><input name="name" value="' . h($name) . '" required>' . $e('name') . '</div>'
        . '<div><label>Fields JSON</label><textarea name="fields_json" id="fields_json" spellcheck="false" required>' . h($fieldsJson) . '</textarea>'
        . '<div class="help">Store either an array of fields, or an object: {"fields":[...],"summary_fields":["key1","key2"]}.</div>'
        . $e('fields_json')
        . '</div>'
        . '<div class="split">'
        . '<button class="btn btn-primary btnblock" type="submit">Save</button>'
        . '<a class="btn btnblock" href="?action=list_task_types">Back</a>'
        . '</div>'
        . '</form>'
        . '<div style="margin-top:12px" class="split">'
        . '<button class="btn btnblock" type="button" onclick="previewTaskType()">Preview form</button>'
        . (($mode === 'edit') ? '<form method="post" action="?action=delete_task_type&id=' . (int)$values['id'] . '" onsubmit="return confirm(\'Delete this task type?\')">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<button class="btn btn-danger btnblock" type="submit">Delete</button></form>' : '<div></div>')
        . '</div>'
        . '</div>';

    $html .= '<div class="card" id="preview_card" style="display:none"><div class="title">Preview</div><div id="preview_fields" style="margin-top:10px"></div></div>';
    $html .= '<script>'
        . 'function el(tag, attrs, html){var e=document.createElement(tag);if(attrs){Object.keys(attrs).forEach(k=>e.setAttribute(k,attrs[k]));}if(html!==undefined)e.innerHTML=html;return e;}'
        . 'function renderField(field){var key=field.key||"";var type=field.type||"text";var label=field.label||key;var req=!!field.required;var wrap=el("div",null);wrap.style.marginBottom="12px";wrap.appendChild(el("label",null,label+(req?" *":"")));var input=null;'
        . 'if(type==="textarea"){input=el("textarea",{name:key});}'
        . 'else if(type==="select"){input=el("select",{name:key});(field.options||[]).forEach(o=>{var opt=el("option",{value:o.value||""},(o.label||o.value||""));input.appendChild(opt);});}'
        . 'else if(type==="boolean"){var c=el("div");var cb=el("input",{type:"checkbox",name:key,value:"1"});cb.style.width="auto";cb.style.marginRight="10px";c.appendChild(cb);c.appendChild(el("span",null,"Yes"));wrap.appendChild(c);return wrap;}'
        . 'else if(type==="price"){input=el("input",{type:"text",name:key,placeholder:(field.placeholder||"e.g., 12.34")});}'
        . 'else if(type==="number"){input=el("input",{type:"number",name:key,step:"any",placeholder:(field.placeholder||"")});}'
        . 'else if(type==="date"){input=el("input",{type:"date",name:key});}'
        . 'else if(type==="time"){input=el("input",{type:"time",name:key});}'
        . 'else if(type==="datetime"){input=el("input",{type:"datetime-local",name:key});}'
        . 'else if(type==="geo"){var a=el("input",{type:"text",name:key+"_address",placeholder:(field.placeholder||"Address")});wrap.appendChild(a);wrap.appendChild(el("div",{class:"help"},"Geo fields store {address, lat, lng}. Location button appears on request forms."));return wrap;}'
        . 'else if(type==="attachment"){input=el("input",{type:"file",name:key});}'
        . 'else {input=el("input",{type:"text",name:key,placeholder:(field.placeholder||"")});}'
        . 'if(req && input) input.required=true; if(input) wrap.appendChild(input); return wrap;}'
        . 'function previewTaskType(){var txt=document.getElementById("fields_json").value;var card=document.getElementById("preview_card");var out=document.getElementById("preview_fields");out.innerHTML="";'
        . 'var obj=null;try{obj=JSON.parse(txt);}catch(e){card.style.display="block";out.innerHTML="<div class=\\"err\\">Invalid JSON.</div>";return;}'
        . 'var fields=obj; if(obj && !Array.isArray(obj) && typeof obj==="object"){fields=obj.fields||[];}'
        . 'if(!Array.isArray(fields)){card.style.display="block";out.innerHTML="<div class=\\"err\\">Expected array of fields.</div>";return;}'
        . 'fields.forEach(f=>{if(f && typeof f==="object") out.appendChild(renderField(f));});'
        . 'card.style.display="block";window.scrollTo({top:card.offsetTop-10,behavior:"smooth"});}'
        . '</script>';

    return $html;
}

function action_create_task_type(): void {
    require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $name = trim((string)($_POST['name'] ?? ''));
        $fieldsJson = trim((string)($_POST['fields_json'] ?? ''));
        $errors = [];
        if ($name === '') $errors['name'] = 'Required.';
        if ($fieldsJson === '') $errors['fields_json'] = 'Required.';
        $tmp = [];
        if (!$errors) {
            $err = validate_task_type_json($fieldsJson, $tmp);
            if ($err) $errors['fields_json'] = $err;
        }
        if (!$errors) {
            $pdo = db();
            $stmt = $pdo->prepare("INSERT INTO task_types (name, fields_json, created_at) VALUES (?, ?, ?)");
            try {
                $stmt->execute([$name, $fieldsJson, now_iso()]);
            } catch (Throwable $e) {
                $errors['name'] = 'Name must be unique.';
            }
            if (!$errors) {
                audit_log((int)$_SESSION['uid'], 'create_task_type', 'task_type', (int)$pdo->lastInsertId(), ['name' => $name]);
                flash_set('ok', 'Task type created.');
                redirect_to('?action=list_task_types');
            }
        }
        render_layout('New Task Type', render_task_type_form('create', ['name' => $name, 'fields_json' => $fieldsJson], $errors));
        return;
    }

    $example = [
        ["key" => "pickup_address", "label" => "Pickup address", "type" => "text", "required" => true],
        ["key" => "dropoff_address", "label" => "Dropoff address", "type" => "text", "required" => false],
        ["key" => "price_cents", "label" => "Price (USD)", "type" => "price", "required" => true],
        ["key" => "num_copies", "label" => "Number of flyers", "type" => "number", "required" => false],
    ];
    $prefill = json_encode(['summary_fields' => ['pickup_address', 'dropoff_address', 'price_cents'], 'fields' => $example], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    render_layout('New Task Type', render_task_type_form('create', ['name' => '', 'fields_json' => $prefill], []));
}

function action_edit_task_type(): void {
    require_admin();
    $id = (int)($_GET['id'] ?? 0);
    $tt = get_task_type_by_id($id);
    if (!$tt) {
        http_response_code(404);
        render_layout('Not found', '<div class="card">Task type not found.</div>');
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $name = trim((string)($_POST['name'] ?? ''));
        $fieldsJson = trim((string)($_POST['fields_json'] ?? ''));
        $errors = [];
        if ($name === '') $errors['name'] = 'Required.';
        if ($fieldsJson === '') $errors['fields_json'] = 'Required.';
        $tmp = [];
        if (!$errors) {
            $err = validate_task_type_json($fieldsJson, $tmp);
            if ($err) $errors['fields_json'] = $err;
        }
        if (!$errors) {
            $pdo = db();
            $stmt = $pdo->prepare("UPDATE task_types SET name = ?, fields_json = ? WHERE id = ?");
            try {
                $stmt->execute([$name, $fieldsJson, $id]);
            } catch (Throwable $e) {
                $errors['name'] = 'Name must be unique.';
            }
            if (!$errors) {
                audit_log((int)$_SESSION['uid'], 'edit_task_type', 'task_type', $id, ['name' => $name]);
                flash_set('ok', 'Task type updated.');
                redirect_to('?action=list_task_types');
            }
        }
        render_layout('Edit Task Type', render_task_type_form('edit', ['id' => $id, 'name' => $name, 'fields_json' => $fieldsJson], $errors));
        return;
    }
    render_layout('Edit Task Type', render_task_type_form('edit', ['id' => $id, 'name' => $tt['name'], 'fields_json' => $tt['fields_json']], []));
}

function action_delete_task_type(): void {
    require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'Method not allowed';
        exit;
    }
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    $pdo = db();
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM requests WHERE task_type_id = ?");
    $stmt->execute([$id]);
    $inUse = (int)$stmt->fetch()['c'];
    if ($inUse > 0) {
        flash_set('error', 'Cannot delete: task type is used by existing requests.');
        redirect_to('?action=edit_task_type&id=' . $id);
    }
    $del = $pdo->prepare("DELETE FROM task_types WHERE id = ?");
    $del->execute([$id]);
    audit_log((int)$_SESSION['uid'], 'delete_task_type', 'task_type', $id);
    flash_set('ok', 'Task type deleted.');
    redirect_to('?action=list_task_types');
}

function render_create_request_form(array $types, ?array $tt, array $values, array $meta, array $errors): string {
    $e = fn(string $k) => isset($errors[$k]) ? '<div class="err">' . h($errors[$k]) . '</div>' : '';
    $typeOptions = '';
    foreach ($types as $t) {
        $sel = ((int)$values['task_type_id'] === (int)$t['id']) ? ' selected' : '';
        $typeOptions .= '<option value="' . (int)$t['id'] . '"' . $sel . '>' . h($t['name']) . '</option>';
    }
    $templates = [];
    foreach ($types as $t) {
        $templates[(string)$t['id']] = ['name' => $t['name'], 'fields' => $t['fields'], 'summary_fields' => $t['summary_fields']];
    }

    $html = '<div class="card"><div class="title">Create request</div>'
        . '<div class="sub">Choose a task type — its schema renders the dynamic fields below.</div>'
        . '<form method="post" action="?action=create_request" enctype="multipart/form-data" class="stack" style="margin-top:10px" id="createForm">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<div><label>Task type</label><select name="task_type_id" id="task_type_id" required>' . $typeOptions . '</select>' . $e('task_type_id') . '</div>'
        . '<div><label>Title</label><input name="title" value="' . h((string)$values['title']) . '" placeholder="Quick summary" required>' . $e('title') . '</div>'
        . '<div><label>Description</label><textarea name="description" placeholder="Details for the runner" required>' . h((string)$values['description']) . '</textarea>' . $e('description') . '</div>'
        . '<div id="dynamic_fields"></div>'
        . '<button class="btn btn-primary btnblock" type="submit">Post request</button>'
        . '</form></div>';

    $html .= '<script>'
        . 'const TASK_TYPES=' . json_encode($templates, JSON_UNESCAPED_SLASHES) . ';'
        . 'const PREV_META=' . json_encode($meta, JSON_UNESCAPED_SLASHES) . ';'
        . 'const ERRORS=' . json_encode($errors, JSON_UNESCAPED_SLASHES) . ';'
        . 'function el(t, attrs, html){const e=document.createElement(t);if(attrs){Object.keys(attrs).forEach(k=>{if(attrs[k]!==null&&attrs[k]!==undefined)e.setAttribute(k,attrs[k]);});}if(html!==undefined)e.innerHTML=html;return e;}'
        . 'function errorFor(key){return ERRORS && ERRORS[key] ? `<div class="err">${String(ERRORS[key])}</div>` : ``;}'
        . 'function help(text){return text?`<div class="help">${text}</div>`:``;}'
        . 'function renderGeo(field, value){const key=field.key;const wrap=el("div",null);wrap.style.marginTop="12px";wrap.appendChild(el("label",null,(field.label||key)+(field.required?" *":"")));
             const a=el("input",{type:"text",name:key+"_address",placeholder:field.placeholder||"Address"});a.value=(value&&value.address)||"";if(field.required)a.required=true;wrap.appendChild(a);
             const lat=el("input",{type:"hidden",name:key+"_lat"});lat.value=(value&&value.lat!=null)?String(value.lat):"";wrap.appendChild(lat);
             const lng=el("input",{type:"hidden",name:key+"_lng"});lng.value=(value&&value.lng!=null)?String(value.lng):"";wrap.appendChild(lng);
             const b=el("button",{type:"button",class:"btn",style:"margin-top:8px"},"Use my location");
             b.onclick=()=>{if(!navigator.geolocation){alert("Geolocation not available.");return;}b.disabled=true;navigator.geolocation.getCurrentPosition((pos)=>{lat.value=String(pos.coords.latitude);lng.value=String(pos.coords.longitude);
                if(!a.value){a.value=`${pos.coords.latitude.toFixed(6)}, ${pos.coords.longitude.toFixed(6)}`;}
                b.disabled=false;},(err)=>{alert("Location error: "+err.message);b.disabled=false;},{enableHighAccuracy:true,timeout:8000,maximumAge:15000});};
             wrap.appendChild(b);wrap.insertAdjacentHTML("beforeend",help("Stored as {address, lat, lng}."));wrap.insertAdjacentHTML("beforeend",errorFor(key));return wrap;}'
        . 'function renderField(field, value){const key=field.key||"";const type=field.type||"text";const wrap=el("div",null);wrap.style.marginTop="12px";
             if(type==="geo") return renderGeo(field, value);
             wrap.appendChild(el("label",null,(field.label||key)+(field.required?" *":"")));
             let input=null;
             if(type==="textarea"){input=el("textarea",{name:key,placeholder:field.placeholder||""});input.value=(value??"");}
             else if(type==="select"){input=el("select",{name:key});(field.options||[]).forEach(o=>{const opt=el("option",{value:o.value||""},(o.label||o.value||""));input.appendChild(opt);});input.value=(value??"");}
             else if(type==="boolean"){const c=el("div",null);const cb=el("input",{type:"checkbox",name:key,value:"1"});cb.style.width="auto";cb.style.marginRight="10px";cb.checked=String(value)==="1"||value===1||value===true; c.appendChild(cb);c.appendChild(el("span",null,"Yes"));wrap.appendChild(c);wrap.insertAdjacentHTML("beforeend",errorFor(key));return wrap;}
             else if(type==="price"){input=el("input",{type:"text",name:key,placeholder:field.placeholder||"e.g., 12.34",inputmode:"decimal"}); if(typeof value==="number") input.value=(value/100).toFixed(2); else input.value=(value??"");}
             else if(type==="number"){input=el("input",{type:"number",name:key,step:"any",placeholder:field.placeholder||""}); input.value=(value??"");}
             else if(type==="date"){input=el("input",{type:"date",name:key}); input.value=(value??"");}
             else if(type==="time"){input=el("input",{type:"time",name:key}); input.value=(value??"");}
             else if(type==="datetime"){input=el("input",{type:"datetime-local",name:key}); input.value=(value??"");}
             else if(type==="attachment"){input=el("input",{type:"file",name:key}); if(value){wrap.insertAdjacentHTML("beforeend",`<div class="help">Current file: ${String(value)}</div>`);} }
             else if(type==="note"){input=el("textarea",{name:key,placeholder:field.placeholder||""}); input.value=(value??"");}
             else if(type==="readonly"){input=el("input",{type:"text",name:key,readonly:"readonly"}); input.value=(value??"");}
             else {input=el("input",{type:"text",name:key,placeholder:field.placeholder||""}); input.value=(value??"");}
             if(field.required && input && type!=="attachment") input.required=true;
             if(input) wrap.appendChild(input);
             wrap.insertAdjacentHTML("beforeend",errorFor(key));
             return wrap;}'
        . 'function normalizeTemplate(t){if(!t) return {fields:[],summary_fields:[]};return {fields:t.fields||[],summary_fields:t.summary_fields||[]};}'
        . 'function renderDynamic(){const sel=document.getElementById("task_type_id");const id=sel.value;const t=normalizeTemplate(TASK_TYPES[id]);const root=document.getElementById("dynamic_fields");root.innerHTML="";t.fields.forEach(f=>{if(!f||typeof f!=="object") return;const v=PREV_META[f.key];root.appendChild(renderField(f,v));});}'
        . 'document.getElementById("task_type_id").addEventListener("change",()=>{renderDynamic();});renderDynamic();'
        . 'document.getElementById("createForm").addEventListener("submit",(e)=>{const id=document.getElementById("task_type_id").value;const t=normalizeTemplate(TASK_TYPES[id]);const missing=[];t.fields.forEach(f=>{if(!f.required) return;if(f.type==="boolean") return;if(f.type==="geo"){const a=document.querySelector(`[name="${f.key}_address"]`);if(a && !a.value.trim()) missing.push(f.label||f.key);}else if(f.type==="attachment"){const inp=document.querySelector(`[name="${f.key}"]`);if(inp && !inp.value) missing.push(f.label||f.key);}else{const inp=document.querySelector(`[name="${f.key}"]`);if(inp && !String(inp.value||"").trim()) missing.push(f.label||f.key);}});if(missing.length){e.preventDefault();alert("Please fill required fields: " + missing.join(", "));}});'
        . '</script>';

    return $html;
}

function action_create_request(): void {
    global $CFG;
    $u = require_login();
    $types = get_task_types();

    $typeId = (int)($_GET['task_type_id'] ?? ($_POST['task_type_id'] ?? 0));
    $tt = $typeId ? get_task_type_by_id($typeId) : null;
    if (!$tt && count($types) > 0) $tt = get_task_type_by_id((int)$types[0]['id']);

    $values = [
        'task_type_id' => $tt ? (int)$tt['id'] : 0,
        'title' => (string)($_POST['title'] ?? ''),
        'description' => (string)($_POST['description'] ?? ''),
    ];
    $errors = [];
    $meta = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $typeId = (int)($_POST['task_type_id'] ?? 0);
        $tt = $typeId ? get_task_type_by_id($typeId) : null;
        if (!$tt) {
            $errors['task_type_id'] = 'Choose a task type.';
        }
        $values['task_type_id'] = $typeId;
        $values['title'] = trim((string)($_POST['title'] ?? ''));
        $values['description'] = trim((string)($_POST['description'] ?? ''));
        if ($values['title'] === '') $errors['title'] = 'Required.';
        if ($values['description'] === '') $errors['description'] = 'Required.';

        if ($tt) {
            $meta = coerce_metadata_from_post($tt, $_POST, $_FILES, $errors, []);
        }

        if (!$errors && $tt) {
            $priceCents = request_primary_price_cents($tt, $meta);
            $feeCents = (int)round($priceCents * ((float)$CFG['default_fee_percent'] / 100.0));
            $pdo = db();
            $stmt = $pdo->prepare("INSERT INTO requests
                (requester_id, task_type_id, title, description, price_cents, fee_cents, status, runner_id, metadata, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'new', NULL, ?, ?, ?)");
            $now = now_iso();
            $stmt->execute([
                (int)$u['id'],
                (int)$tt['id'],
                $values['title'],
                $values['description'],
                $priceCents,
                $feeCents,
                json_encode($meta, JSON_UNESCAPED_SLASHES),
                $now,
                $now,
            ]);
            $rid = (int)$pdo->lastInsertId();
            add_event($rid, (int)$u['id'], 'created', 'Request created');
            audit_log((int)$u['id'], 'create_request', 'request', $rid, ['task_type' => $tt['name']]);
            flash_set('ok', 'Request created.');
            redirect_to('?action=get_request&id=' . $rid);
        }
    } else {
        if ($tt) {
            foreach ($tt['fields'] as $f) {
                if (!is_array($f)) continue;
                $k = (string)($f['key'] ?? '');
                if ($k === '') continue;
                $meta[$k] = null;
            }
        }
    }

    render_layout('Create Request', render_create_request_form($types, $tt, $values, $meta, $errors));
}

function fetch_requests_for_list(?string $status, ?int $taskTypeId): array {
    $pdo = db();
    $sql = "SELECT r.*, tt.name AS task_type_name, tt.fields_json AS task_type_fields_json,
        u1.display_name AS requester_name, u2.display_name AS runner_name
        FROM requests r
        JOIN task_types tt ON tt.id = r.task_type_id
        JOIN users u1 ON u1.id = r.requester_id
        LEFT JOIN users u2 ON u2.id = r.runner_id";
    $conds = [];
    $params = [];
    if ($status && $status !== 'all') {
        $conds[] = 'r.status = ?';
        $params[] = $status;
    }
    if ($taskTypeId) {
        $conds[] = 'r.task_type_id = ?';
        $params[] = $taskTypeId;
    }
    if ($conds) $sql .= ' WHERE ' . implode(' AND ', $conds);
    $sql .= ' ORDER BY r.created_at DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function action_list_requests(): void {
    global $CFG;
    $u = current_user();
    if (!$u) {
        render_auth_gate();
        return;
    }

    $status = (string)($_GET['status'] ?? 'new');
    $taskTypeId = (int)($_GET['task_type_id'] ?? 0);
    $nearby = (string)($_GET['nearby'] ?? '');
    $myLat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null;
    $myLng = isset($_GET['lng']) && $_GET['lng'] !== '' ? (float)$_GET['lng'] : null;
    $radiusKm = isset($_GET['km']) ? max(1.0, (float)$_GET['km']) : 10.0;

    $types = get_task_types();
    $typeOptions = '<option value="0">All task types</option>';
    foreach ($types as $t) {
        $sel = ($taskTypeId === (int)$t['id']) ? ' selected' : '';
        $typeOptions .= '<option value="' . (int)$t['id'] . '"' . $sel . '>' . h($t['name']) . '</option>';
    }

    $rows = fetch_requests_for_list($status, $taskTypeId);
    $items = '';

    foreach ($rows as $r) {
        $tt = normalize_task_type_row([
            'id' => $r['task_type_id'],
            'name' => $r['task_type_name'],
            'fields_json' => $r['task_type_fields_json'],
            'created_at' => '',
        ]);
        $meta = json_decode((string)$r['metadata'], true);
        if (!is_array($meta)) $meta = [];

        if ($nearby === '1' && $myLat !== null && $myLng !== null) {
            $geo = request_first_geo($tt, $meta);
            if (!$geo) continue;
            $d = haversine_km($myLat, $myLng, (float)$geo['lat'], (float)$geo['lng']);
            if ($d > $radiusKm) continue;
        }

        $summaryKeys = infer_summary_keys($tt);
        $summaryBits = [];
        foreach ($summaryKeys as $k) {
            $v = request_summary_value($tt, $meta, $k);
            if ($v !== '') $summaryBits[] = $v;
        }
        $summaryLine = $summaryBits ? implode(' · ', array_slice($summaryBits, 0, 3)) : '';

        $price = format_cents((int)$r['price_cents']);
        $items .= '<div class="item">'
            . '<div class="itemtop">'
            . '<div style="min-width:0">'
            . '<div class="itemtitle"><a href="?action=get_request&id=' . (int)$r['id'] . '">' . h((string)$r['title']) . '</a></div>'
            . '<div class="itemmeta">' . render_task_type_badge((string)$r['task_type_name']) . ' <span class="pill">' . h((string)$r['status']) . '</span></div>'
            . ($summaryLine ? '<div class="itemmeta" style="margin-top:6px">' . h($summaryLine) . '</div>' : '')
            . '<div class="itemmeta" style="margin-top:6px">Created ' . h((string)$r['created_at']) . '</div>'
            . '</div>'
            . '<div class="stack" style="align-items:flex-end;min-width:120px">'
            . '<div class="price">' . h($price) . '</div>'
            . '<a class="btn" href="?action=get_request&id=' . (int)$r['id'] . '">View</a>'
            . '</div>'
            . '</div>'
            . '</div>';
    }

    $statusOptions = [
        'new' => 'New',
        'accepted' => 'Accepted',
        'picked_up' => 'Picked up',
        'payment_confirmed' => 'Payment confirmed',
        'delivered' => 'Delivered',
        'completed' => 'Completed',
        'all' => 'All',
    ];
    $statusSel = '';
    foreach ($statusOptions as $k => $label) {
        $sel = ($status === $k) ? ' selected' : '';
        $statusSel .= '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
    }

    $html = '<div class="card"><div class="row"><div>'
        . '<div class="title">Requests</div>'
        . '<div class="sub">List rows show only essentials: title, task type, key fields, price, created.</div>'
        . '</div><div><a class="btn btn-primary" href="?action=create_request">Create</a></div></div>'
        . '<form method="get" action="" class="grid" style="margin-top:12px">'
        . '<input type="hidden" name="action" value="list_requests">'
        . '<div><label>Status</label><select name="status">' . $statusSel . '</select></div>'
        . '<div><label>Task type</label><select name="task_type_id">' . $typeOptions . '</select></div>'
        . '<div><label>Nearby</label>'
        . '<select name="nearby" id="nearby"><option value="">Off</option><option value="1"' . ($nearby === '1' ? ' selected' : '') . '>Within distance</option></select>'
        . '<div class="help">Uses the first geo field in the schema (if present). No tracking.</div></div>'
        . '<div><label>Distance (km)</label><input name="km" inputmode="decimal" value="' . h((string)$radiusKm) . '"></div>'
        . '<input type="hidden" name="lat" id="lat" value="' . h(isset($_GET['lat']) ? (string)$_GET['lat'] : '') . '">' 
        . '<input type="hidden" name="lng" id="lng" value="' . h(isset($_GET['lng']) ? (string)$_GET['lng'] : '') . '">' 
        . '<button class="btn btnblock" type="submit">Filter</button>'
        . '</form>'
        . '<div style="margin-top:10px"><label style="display:flex;gap:10px;align-items:center;font-weight:700"><input id="autorefresh" type="checkbox" style="width:auto"> Auto-refresh (15s)</label></div>'
        . '</div>'
        . '<div class="list">' . ($items ?: '<div class="item">No requests found.</div>') . '</div>';

    $html .= '<script>'
        . 'const POLL_MS=' . (int)$CFG['poll_ms'] . ';'
        . 'const ar=document.getElementById("autorefresh"); ar.checked=(localStorage.getItem("lg_autorefresh")==="1");'
        . 'ar.addEventListener("change",()=>{localStorage.setItem("lg_autorefresh", ar.checked?"1":"0");});'
        . 'if(ar.checked){setInterval(()=>{location.reload();}, POLL_MS);}'
        . 'const nearby=document.getElementById("nearby");'
        . 'function ensureLoc(){if(nearby.value!=="1") return; if(document.getElementById("lat").value && document.getElementById("lng").value) return;'
        . 'if(!navigator.geolocation) return; navigator.geolocation.getCurrentPosition((pos)=>{document.getElementById("lat").value=String(pos.coords.latitude);document.getElementById("lng").value=String(pos.coords.longitude);},()=>{}, {enableHighAccuracy:true,timeout:7000,maximumAge:20000});}'
        . 'nearby.addEventListener("change",ensureLoc); ensureLoc();'
        . '</script>';

    render_layout('Requests', $html);
}

function fetch_request_full(int $id): ?array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT r.*, tt.name AS task_type_name, tt.fields_json AS task_type_fields_json,
        u1.display_name AS requester_name, u1.email AS requester_email,
        u2.display_name AS runner_name, u2.email AS runner_email
        FROM requests r
        JOIN task_types tt ON tt.id = r.task_type_id
        JOIN users u1 ON u1.id = r.requester_id
        LEFT JOIN users u2 ON u2.id = r.runner_id
        WHERE r.id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function action_get_request(): void {
    global $CFG;
    $u = require_login();
    $id = (int)($_GET['id'] ?? 0);
    $r = fetch_request_full($id);
    if (!$r) {
        http_response_code(404);
        render_layout('Not found', '<div class="card">Request not found.</div>');
        return;
    }
    $tt = normalize_task_type_row([
        'id' => $r['task_type_id'],
        'name' => $r['task_type_name'],
        'fields_json' => $r['task_type_fields_json'],
        'created_at' => '',
    ]);
    $meta = json_decode((string)$r['metadata'], true);
    if (!is_array($meta)) $meta = [];
    $labels = field_label_map($tt);

    $summaryKeys = infer_summary_keys($tt);
    $summaryBits = [];
    foreach ($summaryKeys as $k) {
        $v = request_summary_value($tt, $meta, $k);
        if ($v !== '') $summaryBits[] = $v;
    }

    $price = format_cents((int)$r['price_cents']);
    $fee = format_cents((int)$r['fee_cents']);

    $card = '<div class="card">'
        . '<div class="row"><div style="min-width:0">'
        . '<div class="title">' . h((string)$r['title']) . '</div>'
        . '<div class="sub">' . render_task_type_badge((string)$r['task_type_name']) . ' <span class="pill">' . h((string)$r['status']) . '</span></div>'
        . '</div><div style="text-align:right">'
        . '<div class="price">' . h($price) . '</div>'
        . '<div class="sub">Platform fee: ' . h($fee) . '</div>'
        . '</div></div>'
        . ($summaryBits ? '<div class="sub" style="margin-top:10px">' . h(implode(' · ', $summaryBits)) . '</div>' : '')
        . '<div class="sub" style="margin-top:8px">Requester: ' . h((string)$r['requester_name']) . ($r['runner_name'] ? ' · Runner: ' . h((string)$r['runner_name']) : '') . '</div>'
        . '</div>';

    $desc = '<div class="card"><div class="title">Description</div><div style="margin-top:10px;white-space:pre-wrap">' . h((string)$r['description']) . '</div></div>';

    $metaRows = '';
    foreach ($tt['fields'] as $f) {
        if (!is_array($f)) continue;
        $k = (string)($f['key'] ?? '');
        if ($k === '') continue;
        $label = (string)($f['label'] ?? ($labels[$k] ?? $k));
        $type = (string)($f['type'] ?? 'text');
        $v = $meta[$k] ?? null;
        $render = '';
        if ($type === 'geo') {
            if (is_array($v)) {
                $addr = (string)($v['address'] ?? '');
                $lat = $v['lat'] ?? null;
                $lng = $v['lng'] ?? null;
                $render = h($addr);
                if ($lat !== null && $lng !== null) $render .= '<div class="help">' . h((string)$lat) . ', ' . h((string)$lng) . '</div>';
            }
        } elseif ($type === 'price') {
            $render = is_int($v) ? h(format_cents($v)) : '';
        } elseif ($type === 'boolean') {
            $render = ((int)$v === 1) ? 'Yes' : 'No';
        } elseif ($type === 'attachment') {
            if (is_string($v) && $v !== '') {
                $fn = basename($v);
                $url = 'uploads/' . rawurlencode($fn);
                $render = '<a href="' . h($url) . '" target="_blank" rel="noopener">' . h($fn) . '</a>';
            }
        } elseif ($type === 'select') {
            $render = h(request_summary_value($tt, $meta, $k));
        } else {
            $render = h(is_string($v) ? $v : (is_numeric($v) ? (string)$v : (is_null($v) ? '' : json_encode($v, JSON_UNESCAPED_SLASHES))));
            $render = '<div style="white-space:pre-wrap">' . $render . '</div>';
        }
        $metaRows .= '<tr><td>' . h($label) . '</td><td>' . ($render ?: '<span class="sub">—</span>') . '</td></tr>';
    }
    $metaTable = '<div class="card"><div class="title">Details</div><table class="table" style="margin-top:10px">' . $metaRows . '</table></div>';

    $actions = render_request_actions($u, $r);
    $eventLog = render_request_events($id);
    $rating = render_request_rating_block($u, $r);

    render_layout('Request', $card . $actions . $desc . $metaTable . $eventLog . $rating);
}

function render_request_actions(array $u, array $r): string {
    $uid = (int)$u['id'];
    $isRequester = $uid === (int)$r['requester_id'];
    $isRunner = $r['runner_id'] !== null && $uid === (int)$r['runner_id'];
    $status = (string)$r['status'];
    $buttons = '';

    if ($status === 'new' && !$isRequester) {
        $buttons .= '<form method="post" action="?action=accept_request&id=' . (int)$r['id'] . '">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<button class="btn btn-primary btnblock" type="submit">Accept</button>'
            . '</form>';
    }
    if ($status === 'accepted' && $isRunner) {
        $buttons .= '<form method="post" action="?action=mark_picked_up&id=' . (int)$r['id'] . '">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<button class="btn btn-primary btnblock" type="submit">Mark picked up</button>'
            . '</form>';
    }
    if (in_array($status, ['accepted', 'picked_up'], true) && $isRequester) {
        $buttons .= '<form method="post" action="?action=confirm_payment&id=' . (int)$r['id'] . '">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<button class="btn btn-primary btnblock" type="submit">Confirm payment (manual)</button>'
            . '</form>';
    }
    if (in_array($status, ['picked_up', 'payment_confirmed'], true) && $isRunner) {
        $buttons .= '<form method="post" action="?action=mark_delivered&id=' . (int)$r['id'] . '">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<button class="btn btn-primary btnblock" type="submit">Mark delivered</button>'
            . '</form>';
    }
    if ($status === 'delivered' && $isRequester) {
        $buttons .= '<form method="post" action="?action=mark_delivered&id=' . (int)$r['id'] . '">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<button class="btn btn-primary btnblock" type="submit">Confirm delivery</button>'
            . '</form>';
    }

    $buttons .= '<form method="post" action="?action=post_event&id=' . (int)$r['id'] . '" class="stack" style="margin-top:10px">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<label>Post an update</label>'
        . '<textarea name="note" placeholder="Short note (no sensitive payment details)"></textarea>'
        . '<button class="btn btnblock" type="submit">Post</button>'
        . '</form>';

    return '<div class="card"><div class="title">Actions</div><div class="stack" style="margin-top:10px">' . $buttons . '</div></div>';
}

function render_request_events(int $requestId): string {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT e.*, u.display_name AS actor_name FROM events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.request_id = ? ORDER BY e.id ASC");
    $stmt->execute([$requestId]);
    $rows = $stmt->fetchAll();
    $items = '';
    foreach ($rows as $e) {
        $items .= '<div class="item">'
            . '<div class="itemtop">'
            . '<div style="min-width:0">'
            . '<div class="itemtitle">' . h((string)$e['type']) . '</div>'
            . '<div class="itemmeta">' . h((string)($e['actor_name'] ?? 'System')) . ' · ' . h((string)$e['created_at']) . '</div>'
            . '<div style="margin-top:8px;white-space:pre-wrap">' . h((string)$e['note']) . '</div>'
            . '</div>'
            . '</div>'
            . '</div>';
    }
    return '<div class="card"><div class="title">Event log</div><div class="list" style="margin-top:10px">' . ($items ?: '<div class="item">No events yet.</div>') . '</div></div>';
}

function render_request_rating_block(array $u, array $r): string {
    $uid = (int)$u['id'];
    $isRequester = $uid === (int)$r['requester_id'];
    $isRunner = $r['runner_id'] !== null && $uid === (int)$r['runner_id'];
    $status = (string)$r['status'];
    if (!in_array($status, ['delivered', 'completed'], true)) {
        return '<div class="card"><div class="title">Ratings</div><div class="sub" style="margin-top:8px">Ratings unlock after delivery.</div></div>';
    }
    if (!$isRequester && !$isRunner) {
        return '<div class="card"><div class="title">Ratings</div><div class="sub" style="margin-top:8px">Only participants can rate.</div></div>';
    }

    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM ratings WHERE request_id = ?");
    $stmt->execute([(int)$r['id']]);
    $ratings = $stmt->fetchAll();
    $byRater = [];
    foreach ($ratings as $x) $byRater[(int)$x['rater_id']] = $x;

    $targetId = $isRequester ? (int)$r['runner_id'] : (int)$r['requester_id'];
    $targetLabel = $isRequester ? 'Rate runner' : 'Rate requester';
    $already = $byRater[$uid] ?? null;

    $existing = '';
    if ($ratings) {
        $existing .= '<div class="list" style="margin-top:10px">';
        $stmt2 = $pdo->prepare("SELECT r.*, u1.display_name AS rater_name, u2.display_name AS ratee_name
            FROM ratings r
            JOIN users u1 ON u1.id = r.rater_id
            JOIN users u2 ON u2.id = r.ratee_id
            WHERE r.request_id = ? ORDER BY r.id ASC");
        $stmt2->execute([(int)$r['id']]);
        foreach ($stmt2->fetchAll() as $rr) {
            $existing .= '<div class="item">'
                . '<div class="itemtitle">' . h((string)$rr['rater_name']) . ' → ' . h((string)$rr['ratee_name']) . ' · ' . (int)$rr['score'] . '/5</div>'
                . '<div class="itemmeta">' . h((string)$rr['created_at']) . '</div>'
                . '<div style="margin-top:8px;white-space:pre-wrap">' . h((string)$rr['note']) . '</div>'
                . '</div>';
        }
        $existing .= '</div>';
    }

    $form = '';
    if ($already) {
        $form = '<div class="sub" style="margin-top:10px">You already rated this request.</div>';
    } elseif (!$targetId) {
        $form = '<div class="sub" style="margin-top:10px">Cannot rate yet (no counterpart).</div>';
    } else {
        $form = '<form method="post" action="?action=leave_rating&id=' . (int)$r['id'] . '" class="stack" style="margin-top:10px">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<label>' . h($targetLabel) . '</label>'
            . '<select name="score" required>'
            . '<option value="">Select…</option>'
            . '<option value="5">5 - Excellent</option>'
            . '<option value="4">4 - Good</option>'
            . '<option value="3">3 - OK</option>'
            . '<option value="2">2 - Poor</option>'
            . '<option value="1">1 - Bad</option>'
            . '</select>'
            . '<textarea name="note" placeholder="Short note"></textarea>'
            . '<button class="btn btn-primary btnblock" type="submit">Submit rating</button>'
            . '</form>';
    }

    return '<div class="card"><div class="title">Ratings</div>' . $existing . $form . '</div>';
}

function action_post_event(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'Method not allowed';
        exit;
    }
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    $r = fetch_request_full($id);
    if (!$r) {
        flash_set('error', 'Request not found.');
        redirect_to('?action=list_requests');
    }
    $note = trim((string)($_POST['note'] ?? ''));
    if ($note === '') {
        flash_set('error', 'Note cannot be empty.');
        redirect_to('?action=get_request&id=' . $id);
    }
    if (strlen($note) > 1000) $note = substr($note, 0, 1000);
    add_event($id, (int)$u['id'], 'comment', $note);
    audit_log((int)$u['id'], 'post_event', 'request', $id);
    flash_set('ok', 'Posted.');
    redirect_to('?action=get_request&id=' . $id);
}

function action_accept_request(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'Method not allowed';
        exit;
    }
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    $pdo = db();

    // Race protection: only one runner can accept. Transaction + conditional UPDATE.
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE requests SET status='accepted', runner_id=?, updated_at=? WHERE id=? AND status='new' AND requester_id <> ?");
        $stmt->execute([(int)$u['id'], now_iso(), $id, (int)$u['id']]);
        if ($stmt->rowCount() < 1) {
            $pdo->rollBack();
            flash_set('error', 'Could not accept (already accepted or not eligible).');
            redirect_to('?action=get_request&id=' . $id);
        }
        add_event($id, (int)$u['id'], 'accepted', 'Runner accepted the request');
        audit_log((int)$u['id'], 'accept_request', 'request', $id);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('error', 'Accept failed.');
        redirect_to('?action=get_request&id=' . $id);
    }
    flash_set('ok', 'Accepted.');
    redirect_to('?action=get_request&id=' . $id);
}

function action_mark_picked_up(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'Method not allowed';
        exit;
    }
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE requests SET status='picked_up', updated_at=? WHERE id=? AND status='accepted' AND runner_id=?");
    $stmt->execute([now_iso(), $id, (int)$u['id']]);
    if ($stmt->rowCount() < 1) {
        flash_set('error', 'Cannot mark picked up.');
        redirect_to('?action=get_request&id=' . $id);
    }
    add_event($id, (int)$u['id'], 'picked_up', 'Runner marked picked up');
    audit_log((int)$u['id'], 'mark_picked_up', 'request', $id);
    flash_set('ok', 'Marked picked up.');
    redirect_to('?action=get_request&id=' . $id);
}

function action_confirm_payment(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'Method not allowed';
        exit;
    }
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE requests SET status='payment_confirmed', updated_at=? WHERE id=? AND status IN ('accepted','picked_up') AND requester_id=?");
    $stmt->execute([now_iso(), $id, (int)$u['id']]);
    if ($stmt->rowCount() < 1) {
        flash_set('error', 'Cannot confirm payment.');
        redirect_to('?action=get_request&id=' . $id);
    }
    add_event($id, (int)$u['id'], 'payment_confirmed', 'Requester confirmed payment (manual)');
    audit_log((int)$u['id'], 'confirm_payment', 'request', $id);
    flash_set('ok', 'Payment confirmed (recorded).');
    redirect_to('?action=get_request&id=' . $id);
}

function action_mark_delivered(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'Method not allowed';
        exit;
    }
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    $pdo = db();

    // Single endpoint for both sides:
    // - Runner: picked_up/payment_confirmed -> delivered
    // - Requester: delivered -> completed
    $stmtRunner = $pdo->prepare("UPDATE requests SET status='delivered', updated_at=? WHERE id=? AND status IN ('picked_up','payment_confirmed') AND runner_id=?");
    $stmtRunner->execute([now_iso(), $id, (int)$u['id']]);
    if ($stmtRunner->rowCount() > 0) {
        add_event($id, (int)$u['id'], 'delivered', 'Runner marked delivered');
        audit_log((int)$u['id'], 'mark_delivered', 'request', $id);
        flash_set('ok', 'Marked delivered.');
        redirect_to('?action=get_request&id=' . $id);
    }

    $stmtReq = $pdo->prepare("UPDATE requests SET status='completed', updated_at=? WHERE id=? AND status='delivered' AND requester_id=?");
    $stmtReq->execute([now_iso(), $id, (int)$u['id']]);
    if ($stmtReq->rowCount() > 0) {
        add_event($id, (int)$u['id'], 'delivery_confirmed', 'Requester confirmed delivery');
        audit_log((int)$u['id'], 'confirm_delivery', 'request', $id);
        flash_set('ok', 'Delivery confirmed.');
        redirect_to('?action=get_request&id=' . $id);
    }

    flash_set('error', 'Cannot mark delivered / confirm delivery.');
    redirect_to('?action=get_request&id=' . $id);
}

function action_leave_rating(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'Method not allowed';
        exit;
    }
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    $r = fetch_request_full($id);
    if (!$r) {
        flash_set('error', 'Request not found.');
        redirect_to('?action=list_requests');
    }
    $status = (string)$r['status'];
    if (!in_array($status, ['delivered', 'completed'], true)) {
        flash_set('error', 'Ratings unlock after delivery.');
        redirect_to('?action=get_request&id=' . $id);
    }

    $uid = (int)$u['id'];
    $isRequester = $uid === (int)$r['requester_id'];
    $isRunner = $r['runner_id'] !== null && $uid === (int)$r['runner_id'];
    if (!$isRequester && !$isRunner) {
        flash_set('error', 'Only participants can rate.');
        redirect_to('?action=get_request&id=' . $id);
    }
    $rateeId = $isRequester ? (int)$r['runner_id'] : (int)$r['requester_id'];
    if (!$rateeId) {
        flash_set('error', 'Cannot rate: no counterpart.');
        redirect_to('?action=get_request&id=' . $id);
    }

    $score = (int)($_POST['score'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));
    if ($score < 1 || $score > 5) {
        flash_set('error', 'Select a score.');
        redirect_to('?action=get_request&id=' . $id);
    }
    if (strlen($note) > 800) $note = substr($note, 0, 800);

    $pdo = db();
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM ratings WHERE request_id=? AND rater_id=?");
    $stmt->execute([$id, $uid]);
    if ((int)$stmt->fetch()['c'] > 0) {
        flash_set('error', 'You already rated this request.');
        redirect_to('?action=get_request&id=' . $id);
    }
    $ins = $pdo->prepare("INSERT INTO ratings (request_id, rater_id, ratee_id, score, note, created_at) VALUES (?, ?, ?, ?, ?, ?)");
    $ins->execute([$id, $uid, $rateeId, $score, $note, now_iso()]);
    add_event($id, $uid, 'rated', 'A rating was submitted');
    audit_log($uid, 'leave_rating', 'request', $id, ['score' => $score]);

    $stmt2 = $pdo->prepare("SELECT COUNT(DISTINCT rater_id) AS c FROM ratings WHERE request_id=?");
    $stmt2->execute([$id]);
    $c = (int)$stmt2->fetch()['c'];
    if ($c >= 2 && (string)$r['status'] !== 'completed') {
        $upd = $pdo->prepare("UPDATE requests SET status='completed', updated_at=? WHERE id=?");
        $upd->execute([now_iso(), $id]);
        add_event($id, null, 'completed', 'Request completed (both sides rated)');
    }

    flash_set('ok', 'Rating submitted.');
    redirect_to('?action=get_request&id=' . $id);
}

function random_password(int $len = 10): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function ensure_task_type_exists(string $name, array $fields): int {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM task_types WHERE name = ?");
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    if ($row) return (int)$row['id'];
    $ins = $pdo->prepare("INSERT INTO task_types (name, fields_json, created_at) VALUES (?, ?, ?)");
    $ins->execute([$name, json_encode($fields, JSON_UNESCAPED_SLASHES), now_iso()]);
    return (int)$pdo->lastInsertId();
}

function action_load_sample_data(): void {
    require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $pdo = db();

        $reqEmail = 'requester@example.test';
        $runEmail = 'runner@example.test';
        $reqPass = random_password();
        $runPass = random_password();

        $pdo->beginTransaction();
        try {
            $req = user_by_email($reqEmail);
            if (!$req) {
                $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, display_name, is_admin, created_at) VALUES (?, ?, ?, 0, ?)");
                $stmt->execute([$reqEmail, password_hash($reqPass, PASSWORD_DEFAULT), 'Sample Requester', now_iso()]);
                $reqId = (int)$pdo->lastInsertId();
            } else {
                $reqId = (int)$req['id'];
                $reqPass = '(existing user)';
            }

            $run = user_by_email($runEmail);
            if (!$run) {
                $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, display_name, is_admin, created_at) VALUES (?, ?, ?, 0, ?)");
                $stmt->execute([$runEmail, password_hash($runPass, PASSWORD_DEFAULT), 'Sample Runner', now_iso()]);
                $runId = (int)$pdo->lastInsertId();
            } else {
                $runId = (int)$run['id'];
                $runPass = '(existing user)';
            }

            $errandTypeId = ensure_task_type_exists('Errand', [
                'summary_fields' => ['target', 'price_cents'],
                'fields' => [
                    ['key' => 'target', 'label' => 'Target', 'type' => 'geo', 'required' => true, 'placeholder' => 'Address / area'],
                    ['key' => 'instructions', 'label' => 'Instructions', 'type' => 'textarea', 'required' => true],
                    ['key' => 'price_cents', 'label' => 'Price (USD)', 'type' => 'price', 'required' => true],
                ],
            ]);

            $types = get_task_types();
            $byName = [];
            foreach ($types as $t) $byName[$t['name']] = $t;

            $samples = [];
            if (!empty($byName['Delivery'])) {
                $samples[] = [
                    'task_type' => $byName['Delivery'],
                    'title' => 'Deliver a small package',
                    'description' => 'Small box. Handle carefully.',
                    'meta' => [
                        'pickup' => ['address' => '1 Market St', 'lat' => 37.7946, 'lng' => -122.3950],
                        'dropoff' => ['address' => '500 Howard St', 'lat' => 37.7889, 'lng' => -122.3969],
                        'price_cents' => 1500,
                        'note' => 'Ring the bell at reception',
                    ],
                ];
            }
            if (!empty($byName['Buy-and-Bring'])) {
                $samples[] = [
                    'task_type' => $byName['Buy-and-Bring'],
                    'title' => 'Buy snacks and bring',
                    'description' => 'Need chips + soda. Budget optional.',
                    'meta' => [
                        'store' => ['address' => 'Corner store', 'lat' => 37.7810, 'lng' => -122.4110],
                        'items' => "2x chips (any)\n2x soda (cola)",
                        'budget_cents' => 2500,
                        'delivery' => ['address' => 'Apartment lobby', 'lat' => 37.7790, 'lng' => -122.4140],
                        'price_cents' => 2000,
                        'note' => 'Text when arriving',
                    ],
                ];
            }
            if (!empty($byName['Flyer Distribution'])) {
                $samples[] = [
                    'task_type' => $byName['Flyer Distribution'],
                    'title' => 'Distribute flyers this weekend',
                    'description' => 'Please focus on busy sidewalks and storefronts.',
                    'meta' => [
                        'preferred_area' => 'downtown',
                        'area_notes' => 'Avoid inside private buildings',
                        'num_copies' => 500,
                        'time_start' => '',
                        'time_end' => '',
                        'pickup' => ['address' => 'Print shop', 'lat' => 37.7850, 'lng' => -122.4070],
                        'price_cents' => 4500,
                        'note' => 'Upload 3 photos as proof (optional)',
                    ],
                ];
            }
            $samples[] = [
                'task_type' => get_task_type_by_id($errandTypeId),
                'title' => 'Quick errand: drop off documents',
                'description' => 'Drop documents at front desk.',
                'meta' => [
                    'target' => ['address' => 'City Hall', 'lat' => 37.7793, 'lng' => -122.4192],
                    'instructions' => 'Ask for permits desk',
                    'price_cents' => 1800,
                ],
            ];

            $stmtReq = $pdo->prepare("INSERT INTO requests
                (requester_id, task_type_id, title, description, price_cents, fee_cents, status, runner_id, metadata, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'new', NULL, ?, ?, ?)");
            foreach ($samples as $s) {
                $tt = $s['task_type'];
                if (!$tt) continue;
                $priceCents = (int)($s['meta']['price_cents'] ?? 0);
                $feeCents = (int)round($priceCents * ((float)$GLOBALS['CFG']['default_fee_percent'] / 100.0));
                $now = now_iso();
                $stmtReq->execute([$reqId, (int)$tt['id'], $s['title'], $s['description'], $priceCents, $feeCents, json_encode($s['meta'], JSON_UNESCAPED_SLASHES), $now, $now]);
                $rid = (int)$pdo->lastInsertId();
                add_event($rid, $reqId, 'created', 'Request created (sample)');
            }

            audit_log((int)$_SESSION['uid'], 'load_sample_data', 'system', null, ['requester' => $reqEmail, 'runner' => $runEmail]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash_set('error', 'Sample load failed.');
            redirect_to('?action=load_sample_data');
        }

        flash_set('ok', 'Sample data inserted.');
        flash_set('ok', 'Sample Requester: ' . $reqEmail . ' / ' . $reqPass);
        flash_set('ok', 'Sample Runner: ' . $runEmail . ' / ' . $runPass);
        redirect_to('?action=list_requests');
    }

    $html = '<div class="card"><div class="title">Load sample data</div>'
        . '<div class="sub" style="margin-top:8px">Inserts 2 demo users and 4 demo requests across multiple task types. Emails end with <code>.test</code>.</div>'
        . '<form method="post" action="?action=load_sample_data" style="margin-top:12px">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<button class="btn btn-primary btnblock" type="submit" onclick="return confirm(\'Insert sample users/requests?\')">Load Sample Data</button>'
        . '</form>'
        . '</div>';
    render_layout('Load Sample Data', $html);
}

function action_export_csv(): void {
    global $CFG;
    $u = require_login();
    $isAdmin = ((int)$u['is_admin'] === 1);

    $download = (string)($_GET['download'] ?? '') === '1';
    $scope = (string)($_GET['scope'] ?? ($isAdmin ? 'all' : 'mine'));
    if (!$isAdmin) $scope = 'mine';

    $piiRequested = (string)($_GET['pii'] ?? '') === '1';
    $includePii = $isAdmin && $CFG['export_pii'] && $piiRequested;

    if (!$download) {
        $piiNote = $CFG['export_pii']
            ? 'PII export is ENABLED in config; admins can include emails by adding <code>&pii=1</code>.'
            : 'PII export is disabled by default. Toggle <code>export_pii</code> in the config block to allow admin email export.';
        $html = '<div class="card"><div class="title">Export CSV</div>'
            . '<div class="sub" style="margin-top:8px">Exports request history as CSV. Default export excludes emails.</div>'
            . '<div class="help" style="margin-top:8px">' . $piiNote . '</div>'
            . '<div class="stack" style="margin-top:12px">';
        if ($isAdmin) {
            $html .= '<a class="btn btn-primary btnblock" href="?action=export_csv&download=1&scope=all">Download all (no PII)</a>'
                . ($CFG['export_pii'] ? '<a class="btn btnblock" href="?action=export_csv&download=1&scope=all&pii=1">Download all (include emails)</a>' : '')
                . '<a class="btn btnblock" href="?action=export_csv&download=1&scope=mine">Download mine</a>';
        } else {
            $html .= '<a class="btn btn-primary btnblock" href="?action=export_csv&download=1&scope=mine">Download my history</a>';
        }
        $html .= '</div></div>';
        render_layout('Export CSV', $html);
        return;
    }

    $pdo = db();
    $cols = [
        'r.id',
        'r.requester_id',
        'r.runner_id',
        'tt.name AS task_type',
        'r.status',
        'r.price_cents',
        'r.fee_cents',
        'r.created_at',
        'r.updated_at',
    ];
    if ($includePii) {
        $cols[] = 'u1.email AS requester_email';
        $cols[] = 'u2.email AS runner_email';
    }

    $sql = 'SELECT ' . implode(', ', $cols) . ' FROM requests r'
        . ' JOIN task_types tt ON tt.id = r.task_type_id'
        . ' JOIN users u1 ON u1.id = r.requester_id'
        . ' LEFT JOIN users u2 ON u2.id = r.runner_id';
    $params = [];
    if ($scope === 'mine') {
        $sql .= ' WHERE (r.requester_id = ? OR r.runner_id = ?)';
        $params[] = (int)$u['id'];
        $params[] = (int)$u['id'];
    }
    $sql .= ' ORDER BY r.created_at DESC LIMIT 5000';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    audit_log((int)$u['id'], 'export_csv', 'system', null, ['scope' => $scope, 'include_pii' => $includePii]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="litegig_export.csv"');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    $header = ['id', 'requester_id', 'runner_id', 'task_type', 'status', 'price_cents', 'fee_cents', 'created_at', 'updated_at'];
    if ($includePii) {
        $header[] = 'requester_email';
        $header[] = 'runner_email';
    }
    fputcsv($out, $header);
    while ($row = $stmt->fetch()) {
        $line = [
            (int)$row['id'],
            (int)$row['requester_id'],
            $row['runner_id'] === null ? '' : (int)$row['runner_id'],
            (string)$row['task_type'],
            (string)$row['status'],
            (int)$row['price_cents'],
            (int)$row['fee_cents'],
            (string)$row['created_at'],
            (string)$row['updated_at'],
        ];
        if ($includePii) {
            $line[] = (string)($row['requester_email'] ?? '');
            $line[] = (string)($row['runner_email'] ?? '');
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

function action_cron_cleanup(): void {
    global $CFG;

    $token = (string)($_GET['token'] ?? '');
    $authorized = false;
    if (PHP_SAPI === 'cli') {
        $authorized = true;
    } elseif ($CFG['cron_token'] !== '' && hash_equals((string)$CFG['cron_token'], $token)) {
        $authorized = true;
    }
    if (!$authorized) {
        http_response_code(403);
        echo "Forbidden";
        exit;
    }

    $pdo = db();
    $days = (int)$CFG['cleanup_stale_new_days'];
    $cutoffTs = time() - (60 * 60 * 24 * $days);
    $cutoffIso = gmdate('Y-m-d\TH:i:s\Z', $cutoffTs);

    // Shared-host friendly: process in small chunks.
    $pdo->beginTransaction();
    try {
        $sel = $pdo->prepare("SELECT id FROM requests WHERE status='new' AND created_at < ? ORDER BY created_at ASC LIMIT 200");
        $sel->execute([$cutoffIso]);
        $ids = array_map(fn($r) => (int)$r['id'], $sel->fetchAll());
        $upd = $pdo->prepare("UPDATE requests SET status='expired', updated_at=? WHERE id=? AND status='new'");
        $now = now_iso();
        $changed = 0;
        foreach ($ids as $id) {
            $upd->execute([$now, $id]);
            if ($upd->rowCount() > 0) {
                add_event($id, null, 'expired', 'Auto-expired stale request');
                $changed++;
            }
        }
        audit_log(null, 'cron_cleanup', 'system', null, ['expired' => $changed, 'cutoff' => $cutoffIso]);
        $pdo->commit();
        echo "OK expired={$changed} cutoff={$cutoffIso}\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo "ERROR\n";
    }
    exit;
}

// --- Router ---
db();
$action = (string)($_GET['action'] ?? 'list_requests');
switch ($action) {
    case 'register': action_register(); break;
    case 'login': action_login(); break;
    case 'logout': action_logout(); break;

    case 'list_task_types': action_list_task_types(); break;
    case 'create_task_type': action_create_task_type(); break;
    case 'edit_task_type': action_edit_task_type(); break;
    case 'delete_task_type': action_delete_task_type(); break;

    case 'create_request': action_create_request(); break;
    case 'list_requests': action_list_requests(); break;
    case 'get_request': action_get_request(); break;

    case 'accept_request': action_accept_request(); break;
    case 'mark_picked_up': action_mark_picked_up(); break;
    case 'confirm_payment': action_confirm_payment(); break;
    case 'mark_delivered': action_mark_delivered(); break;
    case 'post_event': action_post_event(); break;
    case 'leave_rating': action_leave_rating(); break;

    case 'load_sample_data': action_load_sample_data(); break;
    case 'export_csv': action_export_csv(); break;
    case 'cron_cleanup': action_cron_cleanup(); break;

    default:
        http_response_code(404);
        render_layout('Not found', '<div class="card">Unknown action.</div>');
        break;
}

/*
Summary
1) Add a task type: Admin → Task Types → New; define `fields_json` with fields and optional `summary_fields`.
2) Metadata drives forms: create-request renders fields from the chosen task type schema; server coerces types into `requests.metadata` JSON.
3) Detail view uses the same schema to label and display metadata, including geo and attachments.
4) List rows are compact: title, task-type badge, a few schema-driven summary fields, price, created date.
5) Acceptance race protection: `accept_request` runs a transactional conditional UPDATE (`status='new'`), so only one runner can win.
6) Payments stay peer-to-peer; LiteGig only records manual confirmations (payment + delivery).
*/








