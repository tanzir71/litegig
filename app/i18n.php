<?php
declare(strict_types=1);

function app_language_code(): string {
    $locale = strtolower(app_locale_code());
    $lang = preg_split('/[_-]/', $locale)[0] ?? 'en';
    return preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'en';
}

function i18n_catalog(): array {
    return [
        'en' => [
            'common.yes' => 'Yes',
            'common.no' => 'No',
            'nav.requests' => 'Requests',
            'nav.open_pool' => 'Open Pool',
            'nav.job_sheet' => 'Job Sheet',
            'nav.payments' => 'Payments',
            'nav.create' => 'Create',
            'nav.profile' => 'Profile',
            'nav.admin' => 'Admin',
            'nav.reports' => 'Reports',
            'nav.task_types' => 'Task Types',
            'nav.export' => 'Export',
            'nav.load_sample' => 'Load Sample',
            'nav.logout' => 'Logout',
            'nav.login' => 'Login',
            'nav.register' => 'Register',
            'ratings.none' => 'No ratings yet',
            'status.new' => 'New',
            'status.accepted' => 'Accepted',
            'status.picked_up' => 'Picked up',
            'status.payment_confirmed' => 'Payment confirmed',
            'status.delivered' => 'Delivered',
            'status.completed' => 'Completed',
            'status.cancelled' => 'Cancelled',
            'status.disputed' => 'Disputed',
            'status.expired' => 'Expired',
            'status.all' => 'All',
            'status.confirmed' => 'Confirmed',
            'status.outstanding' => 'Outstanding',
            'due.overdue' => 'Overdue',
            'due.soon' => 'Due soon',
            'due.scheduled' => 'Scheduled',
            'notification.event.accepted' => 'Accepted',
            'notification.event.picked_up' => 'Pickup',
            'notification.event.delivered' => 'Delivery',
            'notification.event.payment' => 'Payment',
            'notification.event.comment' => 'Comments',
            'notification.event.delivery_otp' => 'Delivery OTP',
            'role.requester' => 'Requester',
            'role.runner' => 'Runner',
            'role.admin' => 'Admin',
            'role.open_pool' => 'Open pool',
            'request.step.new' => 'Posted',
            'request.step.accepted' => 'Accepted',
            'request.step.picked_up' => 'Picked up',
            'request.step.payment_confirmed' => 'Paid',
            'request.step.delivered' => 'Delivered',
            'request.step.completed' => 'Complete',
            'request.step.exception' => 'Exception',
            'schedule.pickup_window_start' => 'Pickup start',
            'schedule.pickup_window_end' => 'Pickup end',
            'schedule.delivery_window_start' => 'Delivery start',
            'schedule.delivery_window_end' => 'Delivery end',
            'schedule.sla_due_at' => 'Due by',
            'exception.cancel.no_longer_needed' => 'No longer needed',
            'exception.cancel.wrong_details' => 'Wrong request details',
            'exception.cancel.timing_changed' => 'Timing changed',
            'exception.cancel.other' => 'Other',
            'exception.decline.schedule_conflict' => 'Schedule conflict',
            'exception.decline.too_far' => 'Too far',
            'exception.decline.cannot_complete' => 'Cannot complete',
            'exception.decline.other' => 'Other',
            'exception.dispute.item_issue' => 'Item issue',
            'exception.dispute.payment_issue' => 'Payment issue',
            'exception.dispute.delivery_issue' => 'Delivery issue',
            'exception.dispute.safety_or_policy' => 'Safety or policy concern',
            'exception.dispute.other' => 'Other',
            'exception.reopen.work_still_needed' => 'Work still needed',
            'exception.reopen.issue_resolved' => 'Issue resolved',
            'exception.reopen.opened_in_error' => 'Closed in error',
            'exception.reopen.other' => 'Other',
        ],
        'es' => [
            'common.yes' => 'Si',
            'common.no' => 'No',
            'nav.requests' => 'Solicitudes',
            'nav.open_pool' => 'Abiertas',
            'nav.job_sheet' => 'Trabajos',
            'nav.payments' => 'Pagos',
            'nav.create' => 'Crear',
            'nav.profile' => 'Perfil',
            'nav.admin' => 'Admin',
            'nav.reports' => 'Reportes',
            'nav.task_types' => 'Tipos',
            'nav.export' => 'Exportar',
            'nav.load_sample' => 'Demo',
            'nav.logout' => 'Salir',
            'nav.login' => 'Entrar',
            'nav.register' => 'Registro',
            'ratings.none' => 'Sin calificaciones',
            'status.new' => 'Nuevo',
            'status.accepted' => 'Aceptado',
            'status.picked_up' => 'Recogido',
            'status.payment_confirmed' => 'Pago confirmado',
            'status.delivered' => 'Entregado',
            'status.completed' => 'Completado',
            'status.cancelled' => 'Cancelado',
            'status.disputed' => 'En disputa',
            'status.expired' => 'Expirado',
            'status.all' => 'Todos',
            'status.confirmed' => 'Confirmado',
            'status.outstanding' => 'Pendiente',
            'due.overdue' => 'Vencido',
            'due.soon' => 'Vence pronto',
            'due.scheduled' => 'Programado',
            'notification.event.accepted' => 'Aceptado',
            'notification.event.picked_up' => 'Recogida',
            'notification.event.delivered' => 'Entrega',
            'notification.event.payment' => 'Pago',
            'notification.event.comment' => 'Comentarios',
            'notification.event.delivery_otp' => 'OTP de entrega',
            'role.requester' => 'Solicitante',
            'role.runner' => 'Runner',
            'role.admin' => 'Admin',
            'role.open_pool' => 'Abiertas',
            'request.step.new' => 'Publicado',
            'request.step.accepted' => 'Aceptado',
            'request.step.picked_up' => 'Recogido',
            'request.step.payment_confirmed' => 'Pagado',
            'request.step.delivered' => 'Entregado',
            'request.step.completed' => 'Completo',
            'request.step.exception' => 'Excepcion',
        ],
    ];
}

function t(string $key, ?string $default = null, array $vars = []): string {
    static $catalog = null;
    if ($catalog === null) $catalog = i18n_catalog();
    $lang = app_language_code();
    $value = $catalog[$lang][$key] ?? $catalog['en'][$key] ?? $default ?? $key;
    foreach ($vars as $name => $replacement) {
        $value = str_replace('{{' . $name . '}}', (string)$replacement, $value);
    }
    return $value;
}

function t_options(string $prefix, array $fallbacks): array {
    $out = [];
    foreach ($fallbacks as $key => $fallback) {
        $out[$key] = t($prefix . '.' . $key, (string)$fallback);
    }
    return $out;
}
