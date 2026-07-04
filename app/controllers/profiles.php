<?php
declare(strict_types=1);

function action_profile(): void {
    $viewer = require_login();
    $id = input_int($_GET, 'id', (int)$viewer['id'], 1, 1000000000);
    $profile = user_by_id($id);
    if (!$profile) {
        render_not_found('Profile not found', 'That operator profile is not available.');
    }

    $rating = user_rating_summary($id);
    $stats = user_profile_stats($id);
    $recent = user_recent_ratings($id);
    $isSelf = (int)$viewer['id'] === $id;
    $canSeeEmail = $isSelf || (int)$viewer['is_admin'] === 1;

    $role = ((int)$profile['is_admin'] === 1) ? 'Admin operator' : 'Operator';
    $emailLine = $canSeeEmail
        ? '<div class="sub" style="margin-top:4px">Email: ' . h((string)$profile['email']) . '</div>'
        : '';
    $phoneLine = ($canSeeEmail && trim((string)($profile['phone'] ?? '')) !== '')
        ? '<div class="sub" style="margin-top:4px">Phone: <span class="mono">' . h((string)$profile['phone']) . '</span></div>'
        : '';

    $html = '<div class="card">'
        . '<div class="row"><div class="profile-head">'
        . '<span class="avatar" aria-hidden="true">' . h(user_initials((string)$profile['display_name'])) . '</span>'
        . '<div class="request-main">'
        . '<div class="title">' . h((string)$profile['display_name']) . '</div>'
        . '<div class="sub">' . h($role) . ' · Joined <span class="mono">' . h(format_app_datetime((string)$profile['created_at'])) . '</span></div>'
        . $emailLine
        . $phoneLine
        . '<div class="sub" style="margin-top:8px">' . h(rating_text($rating['avg'], $rating['count'])) . '</div>'
        . '</div></div><div><a class="btn" href="?action=open_pool">Open Pool</a></div></div>'
        . '<div class="profile-grid">'
        . '<div class="metric"><strong>' . (int)$stats['posted'] . '</strong><span>Requests posted</span></div>'
        . '<div class="metric"><strong>' . (int)$stats['accepted'] . '</strong><span>Jobs accepted</span></div>'
        . '<div class="metric"><strong>' . (int)$stats['completed_as_runner'] . '</strong><span>Completed as runner</span></div>'
        . '<div class="metric"><strong>' . (int)$stats['completed_as_requester'] . '</strong><span>Completed as requester</span></div>'
        . '</div></div>';

    $ratingItems = '';
    foreach ($recent as $row) {
        $ratingItems .= '<div class="item">'
            . '<div class="itemtitle"><span class="mono">' . (int)$row['score'] . '/5</span> for ' . h((string)$row['request_title']) . '</div>'
            . '<div class="itemmeta">From ' . h((string)$row['rater_name']) . ' · <span class="mono">' . h(format_app_datetime((string)$row['created_at'])) . '</span></div>'
            . ((string)$row['note'] !== '' ? '<div class="longtext" style="margin-top:8px">' . h((string)$row['note']) . '</div>' : '')
            . '</div>';
    }

    $html .= '<div class="card"><div class="title">Recent ratings</div><div class="list" style="margin-top:10px">'
        . ($ratingItems ?: '<div class="empty"><div class="empty-title">No ratings yet</div><div class="empty-body">Ratings will appear here after completed requests.</div></div>')
        . '</div></div>';

    if ($isSelf) {
        $emailChecked = (int)($profile['notify_email_enabled'] ?? 1) === 1 ? ' checked' : '';
        $smsChecked = (int)($profile['notify_sms_enabled'] ?? 0) === 1 ? ' checked' : '';
        $eventPrefs = notification_event_preferences($profile);
        $eventChecks = '';
        foreach (notification_event_options() as $key => $label) {
            $checked = !empty($eventPrefs[$key]) ? ' checked' : '';
            $eventChecks .= '<label class="checkline"><input type="checkbox" name="notify_events[]" value="' . h($key) . '"' . $checked . '> ' . h($label) . '</label>';
        }
        $html .= '<div class="card"><div class="title">Notification preferences</div>'
            . '<div class="sub" style="margin-top:8px">Choose channels and events for email/SMS alerts. SMS also carries delivery OTPs when configured.</div>'
            . '<form method="post" action="?action=update_notification_preferences" style="margin-top:12px" class="stack">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<div><label>Phone</label><input inputmode="tel" name="phone" value="' . h((string)($profile['phone'] ?? '')) . '" autocomplete="tel" placeholder="+15551234567"><div class="help">Required for SMS notifications.</div></div>'
            . '<label class="checkline"><input type="checkbox" name="notify_email_enabled" value="1"' . $emailChecked . '> Email notifications</label>'
            . '<label class="checkline"><input type="checkbox" name="notify_sms_enabled" value="1"' . $smsChecked . '> SMS notifications</label>'
            . '<div><label>Events</label><div class="grid">' . $eventChecks . '</div></div>'
            . '<button class="btn btn-primary btnblock" type="submit">Save preferences</button>'
            . '</form></div>';
    }

    render_layout('Profile', $html);
}

function action_update_notification_preferences(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render_method_not_allowed();
    }
    require_csrf();

    $phone = input_string($_POST, 'phone', 40);
    if ($phone !== '' && !preg_match('/^\+?[0-9][0-9 .()\-]{6,32}$/', $phone)) {
        flash_set('error', 'Enter a valid phone number before enabling SMS.');
        redirect_to('?action=profile&id=' . (int)$u['id']);
    }
    $emailEnabled = !empty($_POST['notify_email_enabled']) ? 1 : 0;
    $smsEnabled = !empty($_POST['notify_sms_enabled']) ? 1 : 0;
    if ($smsEnabled === 1 && $phone === '') {
        flash_set('error', 'Add a phone number before enabling SMS notifications.');
        redirect_to('?action=profile&id=' . (int)$u['id']);
    }
    $selected = $_POST['notify_events'] ?? [];
    if (!is_array($selected)) $selected = [];
    $allowed = notification_event_options();
    $eventPrefs = [];
    foreach ($allowed as $key => $_label) {
        $eventPrefs[$key] = in_array($key, array_map('strval', $selected), true);
    }
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE users SET phone = ?, notify_email_enabled = ?, notify_sms_enabled = ?, notify_events_json = ? WHERE id = ?");
    $stmt->execute([$phone !== '' ? $phone : null, $emailEnabled, $smsEnabled, json_encode($eventPrefs, JSON_UNESCAPED_SLASHES), (int)$u['id']]);
    audit_log((int)$u['id'], 'update_notification_preferences', 'user', (int)$u['id'], [
        'email_enabled' => $emailEnabled === 1,
        'sms_enabled' => $smsEnabled === 1,
        'events' => $eventPrefs,
    ]);
    flash_set('ok', 'Notification preferences saved.');
    redirect_to('?action=profile&id=' . (int)$u['id']);
}
