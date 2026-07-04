<?php
declare(strict_types=1);

$root = dirname(__DIR__);
define('LITEGIG_ROOT', $root);

require_once LITEGIG_ROOT . '/app/bootstrap.php';
require_once LITEGIG_ROOT . '/app/services/backups.php';

if (in_array('--help', $argv, true)) {
    echo "Usage: php tools/production_audit.php [--strict]\n";
    echo "Checks launch-sensitive LiteGig configuration. --strict exits non-zero on warnings.\n";
    exit(0);
}

$strict = in_array('--strict', $argv, true);
$results = [];

function audit_add(string $status, string $label, string $detail = ''): void {
    global $results;
    $results[] = ['status' => $status, 'label' => $label, 'detail' => $detail];
}

function audit_check(bool $ok, string $label, string $failureDetail): void {
    audit_add($ok ? 'PASS' : 'FAIL', $label, $ok ? '' : $failureDetail);
}

function audit_warn(string $label, string $detail): void {
    audit_add('WARN', $label, $detail);
}

function audit_placeholder_secret(string $value): bool {
    $v = strtolower($value);
    return $v === ''
        || str_contains($v, 'change-me')
        || str_contains($v, 'replace-with')
        || str_contains($v, 'your_token')
        || str_contains($v, 'example');
}

function audit_path_inside_root(string $path): bool {
    $root = realpath(LITEGIG_ROOT);
    if ($root === false || $path === '') return false;
    $real = realpath($path);
    if ($real === false) $real = realpath(dirname($path));
    if ($real === false) return false;
    $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $real = rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return str_starts_with($real, $root);
}

function audit_has_deny_file(string $dir): bool {
    $deny = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($deny)) return false;
    $body = (string)file_get_contents($deny);
    return str_contains($body, 'Require all denied') || str_contains($body, 'Deny from all');
}

function audit_query_scalar(PDO $pdo, string $sql): int {
    try {
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return -1;
    }
}

global $CFG;

$cronToken = (string)($CFG['cron_token'] ?? '');
if (!empty($CFG['http_cron_enabled'])) {
    audit_check(
        strlen($cronToken) >= 32 && !audit_placeholder_secret($cronToken),
        'Cron token is production-strength',
        'Set LITEGIG_CRON_TOKEN to a long random value before enabling HTTP cron.'
    );
} else {
    audit_add('PASS', 'HTTP cron is disabled; CLI cron does not need a web token');
}
audit_check(!empty($CFG['security_headers']), 'Security headers are enabled', 'Keep LITEGIG_SECURITY_HEADERS=true in production.');
audit_check(empty($CFG['sample_data_enabled']), 'Sample-data loader is disabled', 'Keep LITEGIG_SAMPLE_DATA_ENABLED=false in production.');

foreach (['data_dir' => 'Data directory', 'backup_dir' => 'Backup directory', 'upload_dir' => 'Upload directory'] as $key => $label) {
    $dir = (string)($CFG[$key] ?? '');
    if ($dir === '' || !is_dir($dir)) {
        audit_add('FAIL', $label . ' exists', 'Create the directory and make it writable by PHP.');
        continue;
    }
    if (audit_path_inside_root($dir)) {
        if (audit_has_deny_file($dir)) {
            audit_warn($label . ' is inside the app root', 'Move it outside public web paths when possible; .htaccess denial fallback is present.');
        } else {
            audit_add('FAIL', $label . ' is protected from direct web access', 'Move it outside public web paths or add an .htaccess deny file.');
        }
    } else {
        audit_add('PASS', $label . ' is outside the app root');
    }
}

$dbPath = (string)($CFG['db_path'] ?? '');
if ($dbPath === '' || !is_file($dbPath)) {
    audit_add('FAIL', 'SQLite database exists', 'Initialize the app, run migrations, and register the first admin account.');
} else {
    audit_add('PASS', 'SQLite database exists');
    try {
        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        audit_check(audit_query_scalar($pdo, "SELECT COUNT(*) FROM users WHERE is_admin = 1 AND COALESCE(status, 'active') = 'active'") > 0, 'At least one active admin account exists', 'Register or restore an active admin account before launch.');
        audit_check(audit_query_scalar($pdo, "SELECT COUNT(*) FROM schema_migrations WHERE version = '2026-07-04-bootstrap'") > 0, 'Migration ledger is initialized', 'Run php tools/migrate.php up before launch.');
        audit_check(
            audit_query_scalar($pdo, "SELECT COUNT(*) FROM users WHERE email IN ('requester@example.test', 'runner@example.test') OR display_name IN ('Sample Requester', 'Sample Runner')") === 0,
            'Seeded sample accounts are absent',
            'Remove or rotate requester@example.test and runner@example.test before launch.'
        );
    } catch (Throwable $e) {
        audit_add('FAIL', 'SQLite database can be inspected', $e->getMessage());
    }
}

$latestBackup = latest_litegig_backup_file((string)$CFG['backup_dir']);
if ($latestBackup !== null) {
    $verification = verify_sqlite_backup_file($latestBackup);
    audit_check(
        (bool)$verification['ok'],
        'Latest SQLite backup is verified',
        'Newest backup failed verification: ' . ((string)$verification['error'] !== '' ? (string)$verification['error'] : basename($latestBackup))
    );
} else {
    audit_warn('No SQLite backup file found', 'Run action=cron_backup or php tests/backups.php and verify restore before launch.');
}

if (!empty($CFG['email_enabled'])) {
    $from = (string)($CFG['email_from'] ?? '');
    audit_check($from !== '' && !str_ends_with($from, '@example.com'), 'Email sender is configured', 'Set LITEGIG_EMAIL_FROM to a real sender address.');
} elseif (!empty($CFG['allow_log_only_notifications'])) {
    audit_add('PASS', 'Email log-only mode is accepted by feature flag');
} else {
    audit_warn('Email notifications are log-only', 'Set LITEGIG_EMAIL_ENABLED=true after host mail delivery is configured.');
}

if (!empty($CFG['sms_enabled'])) {
    $driver = (string)($CFG['sms_driver'] ?? '');
    $url = (string)($CFG['sms_webhook_url'] ?? '');
    $secret = (string)($CFG['sms_webhook_secret'] ?? '');
    audit_check($driver === 'webhook' && preg_match('/^https?:\/\//i', $url) === 1 && strlen($secret) >= 16, 'SMS webhook adapter is configured', 'Set LITEGIG_SMS_DRIVER=webhook, LITEGIG_SMS_WEBHOOK_URL, and LITEGIG_SMS_WEBHOOK_SECRET.');
} elseif (!empty($CFG['allow_log_only_notifications'])) {
    audit_add('PASS', 'SMS log-only mode is accepted by feature flag');
} else {
    audit_warn('SMS notifications are disabled', 'Delivery OTPs are still generated in-app; configure SMS webhook delivery before relying on off-device OTP delivery.');
}

if (!empty($CFG['payment_gateway_enabled'])) {
    $url = (string)($CFG['payment_gateway_checkout_url'] ?? '');
    $secret = (string)($CFG['payment_gateway_webhook_secret'] ?? '');
    audit_check(preg_match('/^https?:\/\//i', $url) === 1 && strlen($secret) >= 16, 'Payment gateway adapter is configured', 'Set checkout URL and webhook secret, or keep manual payments disabled-by-default.');
} else {
    audit_add('PASS', 'Manual payment mode is active');
}

$errorUrl = (string)($CFG['error_webhook_url'] ?? '');
$errorSecret = (string)($CFG['error_webhook_secret'] ?? '');
if ($errorUrl !== '') {
    audit_check(preg_match('/^https?:\/\//i', $errorUrl) === 1 && strlen($errorSecret) >= 16, 'Error webhook is signed', 'Set LITEGIG_ERROR_WEBHOOK_SECRET for signed error tracking events.');
} elseif (!empty($CFG['allow_local_error_logs'])) {
    audit_add('PASS', 'Local JSON error logs are accepted by feature flag');
} else {
    audit_warn('Error webhook is not configured', 'Structured JSON logs remain local; wire LITEGIG_ERROR_WEBHOOK_URL before real users if you need off-host alerting.');
}

audit_check(is_file(LITEGIG_ROOT . '/monitoring/uptime.example.yml'), 'Uptime monitor example is present', 'Deploy an uptime monitor against litegig.php?action=health.');

$failures = 0;
$warnings = 0;
echo "LiteGig production audit\n";
echo "========================\n";
foreach ($results as $result) {
    $status = (string)$result['status'];
    if ($status === 'FAIL') $failures++;
    if ($status === 'WARN') $warnings++;
    echo '[' . $status . '] ' . (string)$result['label'] . "\n";
    if ((string)$result['detail'] !== '') {
        echo '       ' . (string)$result['detail'] . "\n";
    }
}
echo "Summary: {$failures} failure(s), {$warnings} warning(s).\n";

if ($failures > 0 || ($strict && $warnings > 0)) {
    exit(1);
}
exit(0);
