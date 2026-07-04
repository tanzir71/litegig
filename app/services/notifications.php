<?php
declare(strict_types=1);

function notification_templates(): array {
    $defaults = [
        'request_accepted' => [
            'subject' => '{{app_name}}: {{request_title}} accepted',
            'body' => "Hi {{recipient_name}},\n\n{{actor_name}} accepted \"{{request_title}}\".\n\nStatus: {{request_status}}\nOpen: {{request_link}}\n",
            'sms' => '{{app_name}}: {{actor_name}} accepted {{request_title}}. {{request_link}}',
        ],
        'request_picked_up' => [
            'subject' => '{{app_name}}: {{request_title}} picked up',
            'body' => "Hi {{recipient_name}},\n\n{{actor_name}} marked \"{{request_title}}\" as picked up.\n\nStatus: {{request_status}}\nOpen: {{request_link}}\n",
            'sms' => '{{app_name}}: {{request_title}} was picked up. {{request_link}}',
        ],
        'request_delivered' => [
            'subject' => '{{app_name}}: {{request_title}} delivered',
            'body' => "Hi {{recipient_name}},\n\n{{actor_name}} marked \"{{request_title}}\" as delivered. Review the request and confirm delivery when ready.\n\nOpen: {{request_link}}\n",
            'sms' => '{{app_name}}: {{request_title}} was marked delivered. Confirm in LiteGig. {{request_link}}',
        ],
        'payment_confirmed' => [
            'subject' => '{{app_name}}: payment recorded for {{request_title}}',
            'body' => "Hi {{recipient_name}},\n\n{{actor_name}} recorded manual payment for \"{{request_title}}\".\n\nOpen: {{request_link}}\n",
            'sms' => '{{app_name}}: payment was recorded for {{request_title}}. {{request_link}}',
        ],
        'request_comment' => [
            'subject' => '{{app_name}}: new comment on {{request_title}}',
            'body' => "Hi {{recipient_name}},\n\n{{actor_name}} added an update on \"{{request_title}}\":\n\n{{note}}\n\nOpen: {{request_link}}\n",
            'sms' => '{{app_name}}: new update on {{request_title}}. {{request_link}}',
        ],
        'delivery_otp' => [
            'subject' => '{{app_name}}: delivery OTP for {{request_title}}',
            'body' => "Hi {{recipient_name}},\n\nUse delivery OTP {{delivery_otp}} for \"{{request_title}}\". Share it with the runner only at handoff.\n\nOpen: {{request_link}}\n",
            'sms' => '{{app_name}} OTP for {{request_title}}: {{delivery_otp}}. Share only at handoff.',
        ],
    ];
    if (function_exists('setting_get')) {
        try {
            $raw = setting_get('notification_templates_json', '');
            $overrides = $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($overrides)) {
                foreach ($overrides as $key => $parts) {
                    if (!isset($defaults[$key]) || !is_array($parts)) continue;
                    foreach (['subject', 'body', 'sms'] as $part) {
                        if (isset($parts[$part]) && is_string($parts[$part]) && trim($parts[$part]) !== '') {
                            $defaults[$key][$part] = substr($parts[$part], 0, 1200);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            app_log('Notification template override failed', ['error' => $e->getMessage()]);
        }
    }
    return $defaults;
}

function notification_event_options(): array {
    return t_options('notification.event', [
        'accepted' => 'Accepted',
        'picked_up' => 'Pickup',
        'delivered' => 'Delivery',
        'payment' => 'Payment',
        'comment' => 'Comments',
        'delivery_otp' => 'Delivery OTP',
    ]);
}

function notification_event_key(string $template): string {
    return [
        'request_accepted' => 'accepted',
        'request_picked_up' => 'picked_up',
        'request_delivered' => 'delivered',
        'payment_confirmed' => 'payment',
        'request_comment' => 'comment',
        'delivery_otp' => 'delivery_otp',
    ][$template] ?? $template;
}

function notification_event_preferences(array $user): array {
    $defaults = array_fill_keys(array_keys(notification_event_options()), true);
    $raw = json_decode((string)($user['notify_events_json'] ?? '{}'), true);
    if (!is_array($raw)) return $defaults;
    foreach ($defaults as $key => $_) {
        if (array_key_exists($key, $raw)) {
            $defaults[$key] = (bool)$raw[$key];
        }
    }
    return $defaults;
}

function notification_channel_enabled_for_user(array $user, string $channel, string $template): bool {
    if ((string)($user['status'] ?? 'active') !== 'active') return false;
    $events = notification_event_preferences($user);
    $eventKey = notification_event_key($template);
    if (array_key_exists($eventKey, $events) && !$events[$eventKey]) return false;
    if ($channel === 'email') return (int)($user['notify_email_enabled'] ?? 1) === 1;
    if ($channel === 'sms') return (int)($user['notify_sms_enabled'] ?? 0) === 1 && trim((string)($user['phone'] ?? '')) !== '';
    return false;
}

function notification_request_link(int $requestId): string {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') return 'litegig.php?action=get_request&id=' . $requestId;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scheme = $https ? 'https' : 'http';
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/litegig.php');
    return $scheme . '://' . $host . $script . '?action=get_request&id=' . $requestId;
}

function notification_payload(array $r, array $actor, string $note = ''): array {
    global $CFG;
    $statusLabels = status_options();
    $status = (string)$r['status'];
    return [
        'app_name' => (string)$CFG['app_name'],
        'request_id' => (int)$r['id'],
        'request_title' => (string)$r['title'],
        'request_status' => $statusLabels[$status] ?? ucwords(str_replace('_', ' ', $status)),
        'actor_name' => (string)($actor['display_name'] ?? 'LiteGig'),
        'note' => $note !== '' ? $note : 'Attachment or status update added.',
        'request_link' => notification_request_link((int)$r['id']),
    ];
}

function notification_recipient_ids(string $template, array $r, array $actor): array {
    $actorId = (int)($actor['id'] ?? 0);
    $requesterId = (int)$r['requester_id'];
    $runnerId = $r['runner_id'] === null ? 0 : (int)$r['runner_id'];

    if ($template === 'delivery_otp') {
        return [$requesterId];
    }
    if (in_array($template, ['request_accepted', 'request_picked_up', 'request_delivered'], true)) {
        return $requesterId !== $actorId ? [$requesterId] : [];
    }
    if ($template === 'payment_confirmed') {
        return $runnerId > 0 && $runnerId !== $actorId ? [$runnerId] : [];
    }
    if ($template === 'request_comment') {
        return array_values(array_filter(array_unique([$requesterId, $runnerId]), fn($id) => $id > 0 && $id !== $actorId));
    }
    return [];
}

function queue_notification_channel(int $userId, string $channel, string $template, array $payload): void {
    $user = user_by_id($userId);
    if (!$user) return;

    $payload['recipient_name'] = (string)$user['display_name'];
    $enabled = notification_channel_enabled_for_user($user, $channel, $template);
    $status = $enabled ? 'queued' : 'skipped';
    $lastError = $enabled ? '' : ucfirst($channel) . ' notifications disabled, missing contact info, or event preference off.';
    $now = now_iso();

    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO notifications
        (user_id, channel, template, payload_json, status, retries, last_error, sent_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 0, ?, NULL, ?, ?)");
    $stmt->execute([
        $userId,
        $channel,
        $template,
        json_encode($payload, JSON_UNESCAPED_SLASHES),
        $status,
        $lastError,
        $now,
        $now,
    ]);
}

function queue_request_notifications(string $template, array $r, array $actor, string $note = '', array $extraPayload = []): void {
    try {
        $payload = array_merge(notification_payload($r, $actor, $note), $extraPayload);
        foreach (notification_recipient_ids($template, $r, $actor) as $userId) {
            queue_notification_channel((int)$userId, 'email', $template, $payload);
            queue_notification_channel((int)$userId, 'sms', $template, $payload);
        }
    } catch (Throwable $e) {
        app_log('Notification queue failed', ['template' => $template, 'error' => $e->getMessage()]);
    }
}

function render_notification_text(string $template, array $payload, string $part): string {
    $templates = notification_templates();
    $text = (string)($templates[$template][$part] ?? '');
    return preg_replace_callback('/\{\{([A-Za-z0-9_]+)\}\}/', function (array $m) use ($payload): string {
        return (string)($payload[$m[1]] ?? '');
    }, $text) ?? $text;
}

function send_notification_email(array $row): array {
    global $CFG;
    if (!$CFG['email_enabled'] || (string)$CFG['email_from'] === '') {
        return ['ok' => true, 'logged_only' => true, 'error' => 'Email disabled or missing LITEGIG_EMAIL_FROM; notification logged only.'];
    }
    if (!function_exists('mail')) {
        return ['ok' => false, 'logged_only' => false, 'error' => 'PHP mail() is unavailable.'];
    }

    $payload = json_decode((string)$row['payload_json'], true);
    if (!is_array($payload)) $payload = [];
    $subject = render_notification_text((string)$row['template'], $payload, 'subject');
    $body = render_notification_text((string)$row['template'], $payload, 'body');
    $headers = [
        'From: ' . (string)$CFG['email_from'],
        'Content-Type: text/plain; charset=UTF-8',
    ];
    if ((string)$CFG['email_reply_to'] !== '') {
        $headers[] = 'Reply-To: ' . (string)$CFG['email_reply_to'];
    }
    $ok = @mail((string)$row['email'], $subject, $body, implode("\r\n", $headers));
    return ['ok' => $ok, 'logged_only' => false, 'error' => $ok ? '' : 'mail() returned false.'];
}

function send_notification_sms(array $row): array {
    global $CFG;
    $payload = json_decode((string)$row['payload_json'], true);
    if (!is_array($payload)) $payload = [];
    $message = render_notification_text((string)$row['template'], $payload, 'sms');
    $phone = trim((string)($row['phone'] ?? ''));
    if ($phone === '') {
        return ['ok' => false, 'logged_only' => false, 'error' => 'User has no phone number.'];
    }
    if (!$CFG['sms_enabled']) {
        return ['ok' => true, 'logged_only' => true, 'error' => 'SMS disabled; notification logged only.'];
    }
    if ((string)$CFG['sms_driver'] !== 'webhook' || (string)$CFG['sms_webhook_url'] === '') {
        return ['ok' => true, 'logged_only' => true, 'error' => 'SMS driver is log-only; notification logged only.'];
    }

    $body = json_encode([
        'to' => $phone,
        'message' => $message,
        'template' => (string)$row['template'],
        'notification_id' => (int)$row['id'],
    ], JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return ['ok' => false, 'logged_only' => false, 'error' => 'Could not encode SMS payload.'];
    }
    $headers = ["Content-Type: application/json"];
    if ((string)$CFG['sms_webhook_secret'] !== '') {
        $headers[] = 'X-LiteGig-Signature: sha256=' . hash_hmac('sha256', $body, (string)$CFG['sms_webhook_secret']);
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);
    $result = @file_get_contents((string)$CFG['sms_webhook_url'], false, $context);
    $ok = $result !== false;
    return ['ok' => $ok, 'logged_only' => false, 'error' => $ok ? '' : 'SMS webhook request failed.'];
}

function process_queued_notifications(int $limit = 50): array {
    global $CFG;
    $pdo = db();
    $limit = max(1, min(200, $limit));
    $retryLimit = max(1, (int)$CFG['notification_retry_limit']);
    $rows = $pdo->query("SELECT n.*, u.email, u.phone, u.display_name, u.status, u.notify_email_enabled, u.notify_sms_enabled, u.notify_events_json
        FROM notifications n
        JOIN users u ON u.id = n.user_id
        WHERE n.channel IN ('email','sms')
          AND n.status IN ('queued', 'retry')
          AND n.retries < " . $retryLimit . "
        ORDER BY n.id ASC
        LIMIT " . $limit)->fetchAll();

    $summary = ['processed' => 0, 'sent' => 0, 'logged' => 0, 'skipped' => 0, 'retry' => 0, 'failed' => 0];
    $upd = $pdo->prepare("UPDATE notifications SET status=?, retries=?, last_error=?, sent_at=?, updated_at=? WHERE id=?");

    foreach ($rows as $row) {
        $summary['processed']++;
        $now = now_iso();
        if (!notification_channel_enabled_for_user($row, (string)$row['channel'], (string)$row['template'])) {
            $upd->execute(['skipped', (int)$row['retries'], ucfirst((string)$row['channel']) . ' notifications disabled, missing contact info, or event preference off.', null, $now, (int)$row['id']]);
            $summary['skipped']++;
            continue;
        }

        $result = (string)$row['channel'] === 'sms'
            ? send_notification_sms($row)
            : send_notification_email($row);
        if ($result['ok'] && $result['logged_only']) {
            $upd->execute(['logged', (int)$row['retries'], (string)$result['error'], $now, $now, (int)$row['id']]);
            $summary['logged']++;
            continue;
        }
        if ($result['ok']) {
            $upd->execute(['sent', (int)$row['retries'], '', $now, $now, (int)$row['id']]);
            $summary['sent']++;
            continue;
        }

        $retries = (int)$row['retries'] + 1;
        $status = $retries >= $retryLimit ? 'failed' : 'retry';
        $upd->execute([$status, $retries, (string)$result['error'], null, $now, (int)$row['id']]);
        $summary[$status]++;
    }

    return $summary;
}
