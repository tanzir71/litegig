<?php
declare(strict_types=1);

function load_env_file(string $path): array {
    if (!is_file($path)) return [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];
    $env = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if ($key === '') continue;
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        $env[$key] = $value;
    }
    return $env;
}

function env_value(array $env, string $key, ?string $default = null): ?string {
    $value = getenv($key);
    if ($value !== false) return (string)$value;
    return array_key_exists($key, $env) ? (string)$env[$key] : $default;
}

function env_int(array $env, string $key, int $default): int {
    $value = env_value($env, $key, null);
    if ($value === null || !preg_match('/^-?\d+$/', $value)) return $default;
    return (int)$value;
}

function env_bool(array $env, string $key, bool $default): bool {
    $value = env_value($env, $key, null);
    if ($value === null) return $default;
    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function app_path(string $path): string {
    if ($path === '') return $path;
    $isAbsolute = preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/|\\\\\\\\)/', $path) === 1;
    return $isAbsolute ? $path : LITEGIG_ROOT . DIRECTORY_SEPARATOR . $path;
}

$ENV = load_env_file(LITEGIG_ROOT . DIRECTORY_SEPARATOR . '.env');
$DATA_DIR = app_path((string)env_value($ENV, 'LITEGIG_DATA_DIR', 'litegig_data'));

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$CFG = [
    'app_name' => 'LiteGig',
    'data_dir' => $DATA_DIR,
    'db_path' => app_path((string)env_value($ENV, 'LITEGIG_DB_PATH', $DATA_DIR . DIRECTORY_SEPARATOR . 'litegig.db')),
    'upload_dir' => app_path((string)env_value($ENV, 'LITEGIG_UPLOAD_DIR', dirname(LITEGIG_ROOT) . DIRECTORY_SEPARATOR . 'litegig_uploads')),
    'backup_dir' => app_path((string)env_value($ENV, 'LITEGIG_BACKUP_DIR', $DATA_DIR . DIRECTORY_SEPARATOR . 'backups')),
    'log_path' => app_path((string)env_value($ENV, 'LITEGIG_LOG_PATH', $DATA_DIR . DIRECTORY_SEPARATOR . 'litegig_error.log')),
    'currency' => env_value($ENV, 'LITEGIG_CURRENCY', 'USD'),
    'locale' => env_value($ENV, 'LITEGIG_LOCALE', 'en_US'),
    'timezone' => env_value($ENV, 'LITEGIG_TIMEZONE', 'UTC'),
    'default_fee_percent' => 8.0,
    'accentColor' => '#0f7a52',
    'poll_ms' => 15000,
    'session_timeout_sec' => env_int($ENV, 'LITEGIG_SESSION_TIMEOUT_SEC', 60 * 60 * 2),
    'session_absolute_sec' => env_int($ENV, 'LITEGIG_SESSION_ABSOLUTE_SEC', 60 * 60 * 24),
    'export_pii' => env_bool($ENV, 'LITEGIG_EXPORT_PII', false),
    'http_cron_enabled' => env_bool($ENV, 'LITEGIG_HTTP_CRON_ENABLED', false),
    'cron_token' => env_value($ENV, 'LITEGIG_CRON_TOKEN', ''),
    'cleanup_stale_new_days' => 14,
    'email_enabled' => env_bool($ENV, 'LITEGIG_EMAIL_ENABLED', false),
    'email_from' => env_value($ENV, 'LITEGIG_EMAIL_FROM', ''),
    'email_reply_to' => env_value($ENV, 'LITEGIG_EMAIL_REPLY_TO', ''),
    'sms_enabled' => env_bool($ENV, 'LITEGIG_SMS_ENABLED', false),
    'sms_driver' => env_value($ENV, 'LITEGIG_SMS_DRIVER', 'log'),
    'sms_webhook_url' => env_value($ENV, 'LITEGIG_SMS_WEBHOOK_URL', ''),
    'sms_webhook_secret' => env_value($ENV, 'LITEGIG_SMS_WEBHOOK_SECRET', ''),
    'allow_log_only_notifications' => env_bool($ENV, 'LITEGIG_ALLOW_LOG_ONLY_NOTIFICATIONS', true),
    'payment_gateway_enabled' => env_bool($ENV, 'LITEGIG_PAYMENT_GATEWAY_ENABLED', false),
    'payment_gateway_checkout_url' => env_value($ENV, 'LITEGIG_PAYMENT_GATEWAY_CHECKOUT_URL', ''),
    'payment_gateway_webhook_secret' => env_value($ENV, 'LITEGIG_PAYMENT_GATEWAY_WEBHOOK_SECRET', ''),
    'error_webhook_url' => env_value($ENV, 'LITEGIG_ERROR_WEBHOOK_URL', ''),
    'error_webhook_secret' => env_value($ENV, 'LITEGIG_ERROR_WEBHOOK_SECRET', ''),
    'allow_local_error_logs' => env_bool($ENV, 'LITEGIG_ALLOW_LOCAL_ERROR_LOGS', true),
    'sample_data_enabled' => env_bool($ENV, 'LITEGIG_SAMPLE_DATA_ENABLED', false),
    'notification_retry_limit' => env_int($ENV, 'LITEGIG_NOTIFICATION_RETRY_LIMIT', 3),
    'max_upload_bytes' => env_int($ENV, 'LITEGIG_MAX_UPLOAD_BYTES', 5 * 1024 * 1024),
    'allowed_upload_ext' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv'],
    'allowed_upload_mime' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/csv', 'application/csv'],
    'blocked_upload_ext' => ['php', 'phtml', 'phar', 'cgi', 'pl', 'asp', 'aspx', 'jsp', 'html', 'htm', 'js', 'svg', 'sh', 'bat', 'cmd', 'exe', 'dll', 'so'],
    'rate_login_limit' => env_int($ENV, 'LITEGIG_RATE_LOGIN_LIMIT', 5),
    'rate_login_window_sec' => env_int($ENV, 'LITEGIG_RATE_LOGIN_WINDOW_SEC', 15 * 60),
    'rate_critical_limit' => env_int($ENV, 'LITEGIG_RATE_CRITICAL_LIMIT', 60),
    'rate_critical_window_sec' => env_int($ENV, 'LITEGIG_RATE_CRITICAL_WINDOW_SEC', 60 * 60),
    'security_headers' => env_bool($ENV, 'LITEGIG_SECURITY_HEADERS', true),
];

function ensure_private_runtime_dir(string $dir): void {
    if ($dir === '') return;
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    if (!is_dir($dir)) return;
    $deny = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($deny)) {
        @file_put_contents($deny, "Require all denied\nDeny from all\n");
    }
    $index = $dir . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($index)) {
        @file_put_contents($index, '');
    }
}
ensure_private_runtime_dir((string)$CFG['data_dir']);
ensure_private_runtime_dir((string)$CFG['backup_dir']);
ensure_private_runtime_dir((string)$CFG['upload_dir']);
ini_set('error_log', (string)$CFG['log_path']);

$configuredTimezone = (string)$CFG['timezone'] ?: 'UTC';
if (!@date_default_timezone_set($configuredTimezone)) {
    $CFG['timezone'] = 'UTC';
    date_default_timezone_set('UTC');
}

function app_log(string $message, array $context = []): void {
    $payload = [
        'at' => now_iso(),
        'level' => (string)($context['level'] ?? 'info'),
        'message' => $message,
        'context' => $context,
    ];
    error_log(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: ('[LiteGig] ' . $message));
}

function report_error_event(string $message, array $context = []): void {
    global $CFG;
    app_log($message, array_merge(['level' => 'error'], $context));
    $url = (string)($CFG['error_webhook_url'] ?? '');
    if ($url === '' || !preg_match('/^https?:\/\//i', $url)) return;

    $body = json_encode([
        'app' => (string)$CFG['app_name'],
        'at' => now_iso(),
        'message' => $message,
        'context' => $context,
    ], JSON_UNESCAPED_SLASHES);
    if ($body === false) return;

    $headers = ['Content-Type: application/json'];
    if ((string)$CFG['error_webhook_secret'] !== '') {
        $headers[] = 'X-LiteGig-Signature: sha256=' . hash_hmac('sha256', $body, (string)$CFG['error_webhook_secret']);
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 4,
            'ignore_errors' => true,
        ],
    ]);
    @file_get_contents($url, false, $ctx);
}

function send_security_headers(): void {
    global $CFG;
    if (PHP_SAPI === 'cli' || headers_sent() || !$CFG['security_headers']) return;
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data:; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; font-src 'self'; connect-src 'self'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}
send_security_headers();

set_exception_handler(function (Throwable $e): void {
    report_error_event('Unhandled exception', [
        'type' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    http_response_code(500);
    if (PHP_SAPI === 'cli') {
        echo "ERROR\n";
    } else {
        echo 'Internal Server Error';
    }
    exit;
});

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function reset_session_state(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
    session_start();
}

if (!isset($_SESSION['__created_at'])) {
    $_SESSION['__created_at'] = time();
}
if (!isset($_SESSION['__last_activity'])) {
    $_SESSION['__last_activity'] = time();
}
$inactiveTooLong = time() - (int)$_SESSION['__last_activity'] > (int)$CFG['session_timeout_sec'];
$absoluteTooLong = time() - (int)$_SESSION['__created_at'] > (int)$CFG['session_absolute_sec'];
if ($inactiveTooLong || $absoluteTooLong) {
    reset_session_state();
    $_SESSION['__created_at'] = time();
}
$_SESSION['__last_activity'] = time();

function htmlEscape(mixed $value): string {
    if ($value === null) return '';
    if (!is_scalar($value)) {
        $value = json_encode($value, JSON_UNESCAPED_SLASHES);
    }
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function h(mixed $s): string { return htmlEscape($s); }
function json_for_html_script(mixed $value): string {
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: 'null';
}
function now_iso(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
function app_locale_code(): string {
    global $CFG;
    $locale = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($CFG['locale'] ?? 'en_US')) ?: 'en_US';
    return $locale;
}
function app_timezone(): DateTimeZone {
    global $CFG;
    static $cache = [];
    $name = (string)($CFG['timezone'] ?? 'UTC');
    if ($name === '') $name = 'UTC';
    if (!isset($cache[$name])) {
        try {
            $cache[$name] = new DateTimeZone($name);
        } catch (Throwable $e) {
            $cache[$name] = new DateTimeZone('UTC');
        }
    }
    return $cache[$name];
}
function parse_app_datetime(string $value): ?DateTimeImmutable {
    $value = trim($value);
    if ($value === '') return null;
    try {
        if (preg_match('/Z$/', $value) === 1) {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(app_timezone());
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/', $value) === 1) {
            return new DateTimeImmutable($value, app_timezone());
        }
        return (new DateTimeImmutable($value))->setTimezone(app_timezone());
    } catch (Throwable $e) {
        return null;
    }
}
function format_app_datetime(string $value): string {
    $dt = parse_app_datetime($value);
    return $dt ? $dt->format('Y-m-d H:i T') : $value;
}
function format_app_date(string $value): string {
    $dt = parse_app_datetime($value);
    return $dt ? $dt->format('Y-m-d') : $value;
}
function local_date_to_utc_iso(string $date, int $addDays = 0): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return now_iso();
    $dt = new DateTimeImmutable($date . ' 00:00:00', app_timezone());
    if ($addDays !== 0) $dt = $dt->modify(($addDays > 0 ? '+' : '') . $addDays . ' day');
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}
function today_local_date(int $offsetDays = 0): string {
    $dt = new DateTimeImmutable('now', app_timezone());
    if ($offsetDays !== 0) $dt = $dt->modify(($offsetDays > 0 ? '+' : '') . $offsetDays . ' day');
    return $dt->format('Y-m-d');
}
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
        if (function_exists('render_layout') && function_exists('render_state_box')) {
            render_layout('Bad request', render_state_box('Session check failed', 'Refresh the page and try the action again.', [
                ['label' => 'Back to requests', 'href' => '?action=list_requests', 'primary' => true],
            ], 'error'));
            exit;
        }
        echo 'Bad Request (CSRF)';
        exit;
    }
}

function fatal_setup(string $title, string $message): void {
    http_response_code(500);
    $t = h($title);
    $m = h($message);
    echo "<!doctype html><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">";
    echo "<title>{$t}</title><style>*{box-sizing:border-box}html,body{margin:0;padding:0;overflow-x:hidden}body{font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif;padding:20px;max-width:760px}h1{font-size:18px}pre{max-width:100%;white-space:pre-wrap;overflow-wrap:anywhere;background:#fafafa;border:1px solid #e6e6e6;border-radius:0;padding:12px}</style>";
    echo "<h1>{$t}</h1><pre>{$m}</pre>";
    exit;
}

require_once LITEGIG_ROOT . '/app/i18n.php';
