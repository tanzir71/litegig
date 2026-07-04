<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litegig-i18n-' . bin2hex(random_bytes(6));
@mkdir($tmp . DIRECTORY_SEPARATOR . 'data', 0750, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'uploads', 0750, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'backups', 0750, true);

putenv('LITEGIG_DATA_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'data');
putenv('LITEGIG_DB_PATH=' . $tmp . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'litegig.db');
putenv('LITEGIG_UPLOAD_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'uploads');
putenv('LITEGIG_BACKUP_DIR=' . $tmp . DIRECTORY_SEPARATOR . 'backups');
putenv('LITEGIG_SECURITY_HEADERS=false');
putenv('LITEGIG_LOCALE=es_ES');

define('LITEGIG_ROOT', $root);

require_once LITEGIG_ROOT . '/app/bootstrap.php';
require_once LITEGIG_ROOT . '/app/http.php';
require_once LITEGIG_ROOT . '/app/models/requests.php';
require_once LITEGIG_ROOT . '/app/services/notifications.php';
require_once LITEGIG_ROOT . '/app/controllers/requests.php';

$checks = 0;

function check_i18n(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function rm_tree_i18n(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rm_tree_i18n($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

global $CFG;

check_i18n(app_language_code() === 'es', 'locale maps to supported language code');
check_i18n(t('nav.requests', 'Requests') === 'Solicitudes', 'nav label resolves from locale catalog');

$status = status_options();
check_i18n($status['new'] === 'Nuevo', 'status options are localized');
check_i18n($status['payment_confirmed'] === 'Pago confirmado', 'compound status option is localized');
check_i18n(str_contains(render_status_chip('confirmed'), 'Confirmado'), 'status chip fallback uses localized catalog keys');

$events = notification_event_options();
check_i18n($events['accepted'] === 'Aceptado', 'notification event options are localized');
check_i18n($events['delivery_otp'] === 'OTP de entrega', 'notification delivery OTP option is localized');

$steps = request_status_steps();
check_i18n($steps['payment_confirmed'] === 'Pagado', 'request state path labels are localized');
check_i18n(request_viewer_role_label(['id' => 7, 'is_admin' => 0], ['requester_id' => 7, 'runner_id' => 8]) === 'Solicitante', 'viewer role label is localized');

$taskType = ['fields' => [['key' => 'fragile', 'label' => 'Fragile', 'type' => 'boolean']]];
check_i18n(request_summary_value($taskType, ['fragile' => 1], 'fragile') === 'Si', 'boolean summary values are localized');

$CFG['locale'] = 'fr_FR';
check_i18n(app_language_code() === 'fr', 'unsupported locale keeps its language code');
check_i18n(t('status.new', 'New') === 'New', 'unsupported locale falls back to English');
check_i18n(t('missing.example', 'Hello {{name}}', ['name' => 'Ana']) === 'Hello Ana', 'fallback strings interpolate variables');

rm_tree_i18n($tmp);
echo "I18n tests passed ({$checks} checks).\n";
