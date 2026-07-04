<?php
declare(strict_types=1);

// --- Router ---
if (PHP_SAPI === 'cli' && !empty($argv)) {
    $cliParams = [];
    parse_str(implode('&', array_slice($argv, 1)), $cliParams);
    $_GET = array_merge($cliParams, $_GET);
}
db();
$action = input_string($_GET, 'action', 40) ?: 'list_requests';
switch ($action) {
    case 'register': action_register(); break;
    case 'login': action_login(); break;
    case 'logout': action_logout(); break;
    case 'profile': action_profile(); break;
    case 'update_notification_preferences': action_update_notification_preferences(); break;

    case 'list_task_types': action_list_task_types(); break;
    case 'create_task_type': action_create_task_type(); break;
    case 'edit_task_type': action_edit_task_type(); break;
    case 'delete_task_type': action_delete_task_type(); break;
    case 'restore_task_type': action_restore_task_type(); break;

    case 'create_request': action_create_request(); break;
    case 'list_requests': action_list_requests(); break;
    case 'open_pool': action_open_pool(); break;
    case 'runner_sheet': action_runner_sheet(); break;
    case 'payments': action_payments(); break;
    case 'save_view': action_save_view(); break;
    case 'delete_saved_view': action_delete_saved_view(); break;
    case 'get_request': action_get_request(); break;
    case 'track': action_track_request(); break;
    case 'download_attachment': action_download_attachment(); break;
    case 'download_event_attachment': action_download_event_attachment(); break;

    case 'edit_request': action_edit_request(); break;
    case 'accept_request': action_accept_request(); break;
    case 'mark_picked_up': action_mark_picked_up(); break;
    case 'confirm_payment': action_confirm_payment(); break;
    case 'start_gateway_payment': action_start_gateway_payment(); break;
    case 'payment_gateway_webhook': action_payment_gateway_webhook(); break;
    case 'generate_delivery_otp': action_generate_delivery_otp(); break;
    case 'mark_delivered': action_mark_delivered(); break;
    case 'cancel_request': action_cancel_request(); break;
    case 'decline_request': action_decline_request(); break;
    case 'dispute_request': action_dispute_request(); break;
    case 'reopen_request': action_reopen_request(); break;
    case 'post_event': action_post_event(); break;
    case 'leave_rating': action_leave_rating(); break;

    case 'load_sample_data': action_load_sample_data(); break;
    case 'admin_console': action_admin_console(); break;
    case 'create_admin_user': action_create_admin_user(); break;
    case 'update_user_role': action_update_user_role(); break;
    case 'reset_user_password': action_reset_user_password(); break;
    case 'update_app_settings': action_update_app_settings(); break;
    case 'update_notification_templates': action_update_notification_templates(); break;
    case 'reports': action_reports(); break;
    case 'health': action_health(); break;
    case 'export_csv': action_export_csv(); break;
    case 'cron_cleanup': action_cron_cleanup(); break;
    case 'cron_notifications': action_cron_notifications(); break;
    case 'cron_backup': action_cron_backup(); break;

    default:
        render_not_found('Page not found', 'That LiteGig action does not exist.');
}
