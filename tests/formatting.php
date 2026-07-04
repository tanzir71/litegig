<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litegig-formatting-' . bin2hex(random_bytes(6));
@mkdir($tmp . DIRECTORY_SEPARATOR . 'data', 0750, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'uploads', 0750, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'backups', 0750, true);

putenv('LITEGIG_DATA_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'data');
putenv('LITEGIG_DB_PATH=' . $tmp . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'litegig.db');
putenv('LITEGIG_UPLOAD_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'uploads');
putenv('LITEGIG_BACKUP_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'backups');
putenv('LITEGIG_SECURITY_HEADERS=false');
putenv('LITEGIG_LOCALE=en_US');
putenv('LITEGIG_TIMEZONE=America/New_York');
putenv('LITEGIG_CURRENCY=USD');

define('LITEGIG_ROOT', $root);

require_once LITEGIG_ROOT . '/app/bootstrap.php';
require_once LITEGIG_ROOT . '/app/models/requests.php';

$checks = 0;

function check_formatting(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function rm_tree_formatting(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rm_tree_formatting($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

check_formatting(format_app_datetime('2026-07-04T12:30:00Z') === '2026-07-04 08:30 EDT', 'UTC timestamps convert to the configured timezone');
check_formatting(format_app_datetime('2026-01-04T12:30:00Z') === '2026-01-04 07:30 EST', 'UTC timestamps handle standard time');
check_formatting(format_app_datetime('2026-07-04T12:30') === '2026-07-04 12:30 EDT', 'datetime-local values remain local to the configured timezone');
check_formatting(format_app_date('2026-07-04T12:30:00Z') === '2026-07-04', 'date display uses the configured timezone');
check_formatting(local_date_to_utc_iso('2026-07-04') === '2026-07-04T04:00:00Z', 'local report start dates convert to UTC');
check_formatting(local_date_to_utc_iso('2026-07-04', 1) === '2026-07-05T04:00:00Z', 'local report end dates convert to UTC as an exclusive bound');

$usd = format_cents(12345);
check_formatting(str_contains($usd, '123.45') && (str_contains($usd, '$') || str_contains($usd, 'USD')), 'currency formatting includes USD marker and amount');

rm_tree_formatting($tmp);
echo "Formatting tests passed ({$checks} checks).\n";
