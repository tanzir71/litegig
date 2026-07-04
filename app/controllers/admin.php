<?php
declare(strict_types=1);

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
    $stmt = $pdo->prepare("SELECT id, active FROM task_types WHERE name = ?");
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    if ($row) {
        if ((int)($row['active'] ?? 1) !== 1) restore_task_type((int)$row['id']);
        return (int)$row['id'];
    }
    $ins = $pdo->prepare("INSERT INTO task_types (name, fields_json, created_at) VALUES (?, ?, ?)");
    $ins->execute([$name, json_encode($fields, JSON_UNESCAPED_SLASHES), now_iso()]);
    return (int)$pdo->lastInsertId();
}

function admin_count(string $sql, array $params = []): int {
    $pdo = db();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function render_admin_metric_grid(array $metrics): string {
    $html = '<div class="profile-grid">';
    foreach ($metrics as $metric) {
        $html .= '<div class="metric"><strong>' . h((string)$metric['value']) . '</strong><span>' . h((string)$metric['label']) . '</span></div>';
    }
    return $html . '</div>';
}

function action_admin_console(): void {
    global $CFG;
    $u = require_admin();
    $pdo = db();

    $queuedNotifications = admin_count("SELECT COUNT(*) FROM notifications WHERE status IN ('queued','retry')");
    $outstandingCents = (int)$pdo->query("SELECT COALESCE(SUM(r.price_cents), 0)
        FROM requests r
        LEFT JOIN payments p ON p.request_id = r.id AND p.status = 'confirmed'
        WHERE r.status IN ('accepted','picked_up','payment_confirmed','delivered') AND p.id IS NULL")->fetchColumn();
    $metrics = render_admin_metric_grid([
        ['value' => admin_count("SELECT COUNT(*) FROM users"), 'label' => 'Users'],
        ['value' => admin_count("SELECT COUNT(*) FROM requests WHERE status = 'new'"), 'label' => 'Open requests'],
        ['value' => admin_count("SELECT COUNT(*) FROM requests WHERE status IN ('accepted','picked_up','payment_confirmed','delivered')"), 'label' => 'Active jobs'],
        ['value' => admin_count("SELECT COUNT(*) FROM requests WHERE status = 'disputed'"), 'label' => 'Disputes'],
        ['value' => $queuedNotifications, 'label' => 'Queued notifications'],
        ['value' => format_cents($outstandingCents), 'label' => 'Outstanding payments'],
    ]);

    $users = $pdo->query("SELECT u.*,
        (SELECT COUNT(*) FROM requests WHERE requester_id = u.id) AS posted_count,
        (SELECT COUNT(*) FROM requests WHERE runner_id = u.id) AS runner_count
        FROM users u ORDER BY u.id DESC LIMIT 50")->fetchAll();
    $createAdminCard = '<div class="card"><div class="title">Create production admin</div>'
        . '<div class="sub" style="margin-top:8px">Use this before disabling seeded or temporary admin access. Leave password blank to generate a one-time temporary password.</div>'
        . '<form method="post" action="?action=create_admin_user" class="grid" style="margin-top:12px">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<div><label>Email</label><input name="email" inputmode="email" autocomplete="off" required></div>'
        . '<div><label>Name</label><input name="display_name" autocomplete="off" required></div>'
        . '<div><label>Phone</label><input name="phone" inputmode="tel" autocomplete="off"></div>'
        . '<div><label>Temporary password</label><input type="password" name="password" autocomplete="new-password" placeholder="Generate if blank"></div>'
        . '<div style="grid-column:1/-1"><label>Reason</label><input name="reason" maxlength="240" required placeholder="First production admin / access rotation"></div>'
        . '<button class="btn btn-primary btnblock" type="submit">Create admin</button>'
        . '</form></div>';
    $userItems = '';
    foreach ($users as $row) {
        $isAdmin = (int)$row['is_admin'] === 1;
        $status = normalize_user_status((string)($row['status'] ?? 'active'));
        $statusLabel = user_status_options()[$status];
        $statusOptions = '';
        foreach (user_status_options() as $value => $label) {
            $statusOptions .= '<option value="' . h($value) . '"' . ($status === $value ? ' selected' : '') . '>' . h($label) . '</option>';
        }
        $disabled = (int)$row['id'] === (int)$u['id'] ? ' disabled' : '';
        $selfNote = $disabled !== '' ? '<div class="help">Use another active admin account before changing your own role or status.</div>' : '';
        $checked = $isAdmin ? ' checked' : '';
        $userItems .= '<div class="item"><div class="itemtop"><div class="request-main">'
            . '<div class="itemtitle">' . h((string)$row['display_name']) . '</div>'
            . '<div class="itemmeta">' . h((string)$row['email']) . ' · Joined <span class="mono">' . h(format_app_datetime((string)$row['created_at'])) . '</span></div>'
            . '<div class="itemmeta">Posted <span class="mono">' . (int)$row['posted_count'] . '</span> · Runner jobs <span class="mono">' . (int)$row['runner_count'] . '</span> · ' . h($statusLabel) . '</div>'
            . '</div><div class="item-actions">'
            . '<form method="post" action="?action=update_user_role" class="stack">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<input type="hidden" name="id" value="' . (int)$row['id'] . '">'
            . '<label class="checkline"><input type="checkbox" name="is_admin" value="1"' . $checked . $disabled . '> Admin</label>'
            . '<div><label>Status</label><select name="status"' . $disabled . '>' . $statusOptions . '</select></div>'
            . '<div><label>Reason</label><input name="reason" maxlength="240" required placeholder="Access change reason"' . $disabled . '></div>'
            . $selfNote
            . '<button class="btn btnblock" type="submit"' . $disabled . '>Save user</button>'
            . '</form>'
            . '<form method="post" action="?action=reset_user_password" class="stack" style="margin-top:10px">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<input type="hidden" name="id" value="' . (int)$row['id'] . '">'
            . '<div><label>Reset password</label><input type="password" name="password" autocomplete="new-password" placeholder="Generate if blank"></div>'
            . '<div><label>Reason</label><input name="reason" maxlength="240" required placeholder="Password reset reason"></div>'
            . '<button class="btn btnblock" type="submit">Reset password</button>'
            . '</form></div></div></div>';
    }

    $fee = app_fee_percent();
    $settingsCard = '<div class="card"><div class="title">Config</div>'
        . '<div class="sub" style="margin-top:8px">Runtime settings stored in SQLite. Environment variables still control secrets and provider wiring.</div>'
        . '<form method="post" action="?action=update_app_settings" class="grid" style="margin-top:12px">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<div><label>Default fee percent</label><input name="default_fee_percent" inputmode="decimal" value="' . h((string)$fee) . '"><div class="help">Used for newly created or edited requests.</div></div>'
        . '<button class="btn btn-primary btnblock" type="submit">Save config</button>'
        . '</form></div>';

    $templateFields = '';
    foreach (notification_templates() as $key => $parts) {
        $templateFields .= '<div class="item"><div class="itemtitle">' . h(notification_event_key($key)) . ' · <span class="mono">' . h($key) . '</span></div>'
            . '<div class="grid" style="margin-top:10px">'
            . '<div><label>Email subject</label><input name="template[' . h($key) . '][subject]" value="' . h((string)($parts['subject'] ?? '')) . '"></div>'
            . '<div><label>SMS</label><textarea name="template[' . h($key) . '][sms]">' . h((string)($parts['sms'] ?? '')) . '</textarea></div>'
            . '</div><div style="margin-top:10px"><label>Email body</label><textarea name="template[' . h($key) . '][body]">' . h((string)($parts['body'] ?? '')) . '</textarea></div>'
            . '</div>';
    }
    $templateCard = '<div class="card"><div class="title">Notification templates</div>'
        . '<div class="sub" style="margin-top:8px">Available placeholders include <span class="mono">{{app_name}}</span>, <span class="mono">{{request_title}}</span>, <span class="mono">{{actor_name}}</span>, <span class="mono">{{request_link}}</span>, and <span class="mono">{{delivery_otp}}</span>.</div>'
        . '<form method="post" action="?action=update_notification_templates" class="stack" style="margin-top:12px">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . $templateFields
        . '<button class="btn btn-primary btnblock" type="submit">Save templates</button>'
        . '</form></div>';

    $auditRows = $pdo->query("SELECT a.*, u.display_name AS actor_name
        FROM audit_log a
        LEFT JOIN users u ON u.id = a.actor_id
        ORDER BY a.id DESC LIMIT 50")->fetchAll();
    $auditItems = '';
    foreach ($auditRows as $row) {
        $auditItems .= '<div class="item"><div class="itemtop"><div class="request-main">'
            . '<div class="itemtitle">' . h((string)$row['action']) . ' · <span class="mono">' . h((string)$row['target_type']) . '#' . h((string)($row['target_id'] ?? '')) . '</span></div>'
            . '<div class="itemmeta">' . h((string)($row['actor_name'] ?? 'System')) . ' · <span class="mono">' . h(format_app_datetime((string)$row['created_at'])) . '</span></div>'
            . '<div class="longtext" style="margin-top:8px">' . h((string)$row['meta_json']) . '</div>'
            . '</div></div></div>';
    }

    $migrations = $pdo->query("SELECT * FROM schema_migrations ORDER BY applied_at DESC")->fetchAll();
    $migrationItems = '';
    foreach ($migrations as $row) {
        $migrationItems .= '<div class="item"><div class="itemtitle mono">' . h((string)$row['version']) . '</div>'
            . '<div class="itemmeta">' . h((string)$row['description']) . ' · <span class="mono">' . h(format_app_datetime((string)$row['applied_at'])) . '</span></div></div>';
    }

    $sampleShortcut = !empty($CFG['sample_data_enabled'])
        ? '<a class="btn btnblock" href="?action=load_sample_data">Load sample data</a>'
        : '<span class="btn btnblock disabled" aria-disabled="true">Sample data disabled</span>';
    $links = '<div class="card"><div class="title">Admin shortcuts</div><div class="grid" style="margin-top:12px">'
        . '<a class="btn btnblock" href="?action=list_task_types">Task Types</a>'
        . '<a class="btn btnblock" href="?action=reports">Reports</a>'
        . '<a class="btn btnblock" href="?action=export_csv">Export</a>'
        . $sampleShortcut
        . '<a class="btn btnblock" href="?action=health">Health JSON</a>'
        . '</div></div>';

    $html = '<div class="card"><div class="title">Admin console</div><div class="sub" style="margin-top:8px">User roles, config, notification templates, audit review, and operational shortcuts.</div>' . $metrics . '</div>'
        . $links
        . $settingsCard
        . $createAdminCard
        . '<div class="card"><div class="title">Users and roles</div><div class="list" style="margin-top:10px">' . ($userItems ?: render_state_box('No users', 'Registered users will appear here.', [], 'empty')) . '</div></div>'
        . $templateCard
        . '<div class="card"><div class="title">Audit review</div><div class="list" style="margin-top:10px">' . ($auditItems ?: render_state_box('No audit events', 'Privileged actions will appear here.', [], 'empty')) . '</div></div>'
        . '<div class="card"><div class="title">Schema migrations</div><div class="list" style="margin-top:10px">' . ($migrationItems ?: render_state_box('No migration records', 'Schema records will appear after initialization.', [], 'empty')) . '</div></div>';

    render_layout('Admin Console', $html);
}

function action_update_user_role(): void {
    $u = require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') render_method_not_allowed();
    require_csrf();
    enforce_critical_rate_limit('update_user_role', (int)$u['id']);
    $id = input_int($_POST, 'id', 0, 1, 1000000000);
    $makeAdmin = !empty($_POST['is_admin']) ? 1 : 0;
    $status = normalize_user_status(input_string($_POST, 'status', 24));
    $reason = input_string($_POST, 'reason', 240);
    try {
        $result = update_user_access_idempotent((int)$u['id'], $id, $makeAdmin === 1, $status, $reason);
        flash_set('ok', !empty($result['changed']) ? 'User access saved.' : 'User access already matched that request.');
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to('?action=admin_console');
}

function action_create_admin_user(): void {
    $u = require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') render_method_not_allowed();
    require_csrf();
    enforce_critical_rate_limit('create_admin_user', (int)$u['id']);
    try {
        $result = create_production_admin_idempotent(
            (int)$u['id'],
            input_string($_POST, 'email', 254),
            input_string($_POST, 'display_name', 120),
            input_string($_POST, 'phone', 40),
            (string)($_POST['password'] ?? ''),
            input_string($_POST, 'reason', 240)
        );
        flash_set('ok', !empty($result['created']) ? 'Production admin created.' : (!empty($result['changed']) ? 'Production admin updated.' : 'Production admin already matched that request.'));
        if (!empty($result['generated_password'])) {
            flash_set('ok', 'Temporary password: ' . (string)$result['password']);
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to('?action=admin_console');
}

function action_reset_user_password(): void {
    $u = require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') render_method_not_allowed();
    require_csrf();
    enforce_critical_rate_limit('reset_user_password', (int)$u['id']);
    $id = input_int($_POST, 'id', 0, 1, 1000000000);
    try {
        $result = reset_user_password_with_audit(
            (int)$u['id'],
            $id,
            (string)($_POST['password'] ?? ''),
            input_string($_POST, 'reason', 240)
        );
        flash_set('ok', 'Password reset.');
        if (!empty($result['generated_password'])) {
            flash_set('ok', 'Temporary password: ' . (string)$result['password']);
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to('?action=admin_console');
}

function action_update_app_settings(): void {
    $u = require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') render_method_not_allowed();
    require_csrf();
    $fee = input_float($_POST, 'default_fee_percent', 8.0, 0.0, 50.0);
    setting_set('default_fee_percent', number_format($fee, 2, '.', ''));
    audit_log((int)$u['id'], 'update_app_settings', 'settings', null, ['default_fee_percent' => $fee]);
    flash_set('ok', 'Config saved.');
    redirect_to('?action=admin_console');
}

function action_update_notification_templates(): void {
    $u = require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') render_method_not_allowed();
    require_csrf();
    $source = $_POST['template'] ?? [];
    if (!is_array($source)) $source = [];
    $templates = [];
    foreach (notification_templates() as $key => $_parts) {
        $row = $source[$key] ?? [];
        if (!is_array($row)) $row = [];
        $templates[$key] = [
            'subject' => substr(trim((string)($row['subject'] ?? '')), 0, 300),
            'body' => substr(trim((string)($row['body'] ?? '')), 0, 1200),
            'sms' => substr(trim((string)($row['sms'] ?? '')), 0, 300),
        ];
    }
    setting_set('notification_templates_json', json_encode($templates, JSON_UNESCAPED_SLASHES));
    audit_log((int)$u['id'], 'update_notification_templates', 'settings', null);
    flash_set('ok', 'Notification templates saved.');
    redirect_to('?action=admin_console');
}

function action_load_sample_data(): void {
    global $CFG;
    require_admin();
    if (empty($CFG['sample_data_enabled'])) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            require_csrf();
            flash_set('error', 'Sample data loading is disabled by configuration.');
            redirect_to('?action=admin_console');
        }
        render_layout('Sample data disabled', render_state_box(
            'Sample data disabled',
            'Set LITEGIG_SAMPLE_DATA_ENABLED=true only in a private demo or staging environment.',
            [['label' => 'Back to admin', 'href' => '?action=admin_console', 'primary' => true]],
            'warning'
        ));
        return;
    }
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
                (requester_id, task_type_id, code, title, description, price_cents, fee_cents, status, runner_id, metadata, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'new', NULL, ?, ?, ?)");
            foreach ($samples as $s) {
                $tt = $s['task_type'];
                if (!$tt) continue;
                $priceCents = (int)($s['meta']['price_cents'] ?? 0);
                $feeCents = (int)round($priceCents * (app_fee_percent() / 100.0));
                $now = now_iso();
                $stmtReq->execute([$reqId, (int)$tt['id'], generate_request_code($pdo), $s['title'], $s['description'], $priceCents, $feeCents, json_encode($s['meta'], JSON_UNESCAPED_SLASHES), $now, $now]);
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
        . '<div class="sub" style="margin-top:8px">Inserts 2 demo users and 4 demo requests across multiple task types. Keep this disabled outside private demo or staging environments.</div>'
        . '<form method="post" action="?action=load_sample_data" style="margin-top:12px">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<button class="btn btn-primary btnblock" type="submit" onclick="return confirm(\'Insert sample users/requests?\')">Load Sample Data</button>'
        . '</form>'
        . '</div>';
    render_layout('Load Sample Data', $html);
}

function export_safe_cell(mixed $value): mixed {
    if (is_int($value) || is_float($value)) return $value;
    $s = (string)$value;
    return preg_match('/^[=+\-@]/', $s) === 1 ? "'" . $s : $s;
}

function request_export_data(array $u, string $scope, bool $includePii): array {
    $isAdmin = ((int)$u['is_admin'] === 1);
    if (!in_array($scope, ['all', 'mine'], true)) $scope = 'mine';
    if (!$isAdmin) $scope = 'mine';
    $includePii = $isAdmin && $includePii;

    $pdo = db();
    $sql = "SELECT r.id, r.requester_id, r.runner_id, tt.name AS task_type, r.status,
        r.price_cents, r.fee_cents, r.created_at, r.updated_at,
        CASE WHEN :include_pii1 = 1 THEN u1.email ELSE '' END AS requester_email,
        CASE WHEN :include_pii2 = 1 THEN u2.email ELSE '' END AS runner_email
        FROM requests r
        JOIN task_types tt ON tt.id = r.task_type_id
        JOIN users u1 ON u1.id = r.requester_id
        LEFT JOIN users u2 ON u2.id = r.runner_id
        WHERE (:scope_all = 1 OR r.requester_id = :uid1 OR r.runner_id = :uid2)
        ORDER BY r.created_at DESC LIMIT 5000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':include_pii1' => $includePii ? 1 : 0,
        ':include_pii2' => $includePii ? 1 : 0,
        ':scope_all' => $scope === 'all' ? 1 : 0,
        ':uid1' => (int)$u['id'],
        ':uid2' => (int)$u['id'],
    ]);

    $header = ['id', 'requester_id', 'runner_id', 'task_type', 'status', 'price_cents', 'fee_cents', 'created_at', 'updated_at'];
    if ($includePii) {
        $header[] = 'requester_email';
        $header[] = 'runner_email';
    }

    $rows = [];
    while ($row = $stmt->fetch()) {
        $line = [
            (int)$row['id'],
            (int)$row['requester_id'],
            $row['runner_id'] === null ? '' : (int)$row['runner_id'],
            export_safe_cell((string)$row['task_type']),
            export_safe_cell((string)$row['status']),
            (int)$row['price_cents'],
            (int)$row['fee_cents'],
            (string)$row['created_at'],
            (string)$row['updated_at'],
        ];
        if ($includePii) {
            $line[] = export_safe_cell((string)($row['requester_email'] ?? ''));
            $line[] = export_safe_cell((string)($row['runner_email'] ?? ''));
        }
        $rows[] = $line;
    }

    return ['header' => $header, 'rows' => $rows, 'scope' => $scope];
}

function render_excel_table(array $header, array $rows): string {
    $html = '<!doctype html><meta charset="utf-8"><table><thead><tr>';
    foreach ($header as $cell) {
        $html .= '<th>' . h((string)$cell) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>' . h((string)$cell) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table>';
}

function send_csv_download(string $filename, array $header, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fputcsv($out, $header);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
}

function send_excel_download(string $filename, array $header, array $rows): void {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    echo render_excel_table($header, $rows);
}

function action_export_csv(): void {
    global $CFG;
    $u = require_login();
    $isAdmin = ((int)$u['is_admin'] === 1);

    $download = input_string($_GET, 'download', 1) === '1';
    $format = input_string($_GET, 'format', 8) ?: 'csv';
    if (!in_array($format, ['csv', 'xls'], true)) $format = 'csv';
    $scope = input_string($_GET, 'scope', 10) ?: ($isAdmin ? 'all' : 'mine');
    if (!in_array($scope, ['all', 'mine'], true)) $scope = 'mine';
    if (!$isAdmin) $scope = 'mine';

    $piiRequested = input_string($_GET, 'pii', 1) === '1';
    $includePii = $isAdmin && $CFG['export_pii'] && $piiRequested;

    if (!$download) {
        $piiNote = $CFG['export_pii']
            ? 'PII export is ENABLED in config; admins can include emails by adding <code>&pii=1</code>.'
            : 'PII export is disabled by default. Toggle <code>export_pii</code> in the config block to allow admin email export.';
        $html = '<div class="card"><div class="title">Export history</div>'
            . '<div class="sub" style="margin-top:8px">Exports request history as CSV or Excel-compatible XLS. Default export excludes emails.</div>'
            . '<div class="help" style="margin-top:8px">' . $piiNote . '</div>'
            . '<div class="stack" style="margin-top:12px">';
        if ($isAdmin) {
            $html .= '<a class="btn btn-primary btnblock" href="?action=export_csv&download=1&format=csv&scope=all">Download all CSV (no PII)</a>'
                . '<a class="btn btnblock" href="?action=export_csv&download=1&format=xls&scope=all">Download all Excel (no PII)</a>'
                . ($CFG['export_pii'] ? '<a class="btn btnblock" href="?action=export_csv&download=1&format=csv&scope=all&pii=1">Download all CSV (include emails)</a><a class="btn btnblock" href="?action=export_csv&download=1&format=xls&scope=all&pii=1">Download all Excel (include emails)</a>' : '')
                . '<a class="btn btnblock" href="?action=export_csv&download=1&format=csv&scope=mine">Download mine CSV</a>'
                . '<a class="btn btnblock" href="?action=export_csv&download=1&format=xls&scope=mine">Download mine Excel</a>';
        } else {
            $html .= '<a class="btn btn-primary btnblock" href="?action=export_csv&download=1&format=csv&scope=mine">Download my history CSV</a>'
                . '<a class="btn btnblock" href="?action=export_csv&download=1&format=xls&scope=mine">Download my history Excel</a>';
        }
        $html .= '</div></div>';
        render_layout('Export history', $html);
        return;
    }

    $data = request_export_data($u, $scope, $includePii);
    audit_log((int)$u['id'], 'export_requests_' . $format, 'system', null, ['scope' => (string)$data['scope'], 'include_pii' => $includePii]);
    if ($format === 'xls') {
        send_excel_download('litegig_export.xls', $data['header'], $data['rows']);
    } else {
        send_csv_download('litegig_export.csv', $data['header'], $data['rows']);
    }
    exit;
}

function report_date_bounds(): array {
    $from = input_string($_GET, 'from', 10);
    $to = input_string($_GET, 'to', 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = today_local_date(-30);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = today_local_date();
    if (strcmp($from, $to) > 0) [$from, $to] = [$to, $from];
    $fromIso = local_date_to_utc_iso($from);
    $toIso = local_date_to_utc_iso($to, 1);
    return [$from, $to, $fromIso, $toIso];
}

function report_where_sql(string $fromIso, string $toIso): array {
    return ['r.created_at >= :from_iso AND r.created_at < :to_iso', [':from_iso' => $fromIso, ':to_iso' => $toIso]];
}

function report_rows(string $groupBy, string $labelExpr, string $fromIso, string $toIso, int $limit = 50): array {
    $pdo = db();
    [$whereSql, $params] = report_where_sql($fromIso, $toIso);
    $limit = max(1, min(200, $limit));
    $sql = "SELECT {$labelExpr} AS label, COUNT(*) AS total, COALESCE(SUM(r.price_cents), 0) AS amount_cents, COALESCE(SUM(r.fee_cents), 0) AS fee_cents
        FROM requests r
        JOIN task_types tt ON tt.id = r.task_type_id
        LEFT JOIN users runner ON runner.id = r.runner_id
        WHERE {$whereSql}
        GROUP BY {$groupBy}
        ORDER BY total DESC, label ASC
        LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function action_reports(): void {
    require_admin();
    [$from, $to, $fromIso, $toIso] = report_date_bounds();
    $download = input_string($_GET, 'download', 10);
    $pdo = db();
    [$whereSql, $params] = report_where_sql($fromIso, $toIso);

    $summaryStmt = $pdo->prepare("SELECT COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END), 0) AS completed,
        COALESCE(SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled,
        COALESCE(SUM(CASE WHEN r.status = 'disputed' THEN 1 ELSE 0 END), 0) AS disputed,
        COALESCE(SUM(r.price_cents), 0) AS gross_cents,
        COALESCE(SUM(r.fee_cents), 0) AS fee_cents
        FROM requests r WHERE {$whereSql}");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch() ?: [];

    $ratingStmt = $pdo->prepare("SELECT COUNT(*) AS rating_count, AVG(score) AS rating_avg FROM ratings WHERE created_at >= :from_iso AND created_at < :to_iso");
    $ratingStmt->execute($params);
    $rating = $ratingStmt->fetch() ?: [];

    $paymentStmt = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN p.status = 'confirmed' THEN p.amount_cents ELSE 0 END), 0) AS settled_cents,
        COALESCE(SUM(CASE WHEN p.status = 'confirmed' THEN p.fee_cents ELSE 0 END), 0) AS settled_fee_cents
        FROM payments p
        JOIN requests r ON r.id = p.request_id
        WHERE {$whereSql}");
    $paymentStmt->execute($params);
    $payments = $paymentStmt->fetch() ?: [];

    $statusRows = report_rows('r.status', 'r.status', $fromIso, $toIso);
    $typeRows = report_rows('tt.name', 'tt.name', $fromIso, $toIso);
    $runnerRows = report_rows('COALESCE(runner.display_name, \'Unassigned\')', 'COALESCE(runner.display_name, \'Unassigned\')', $fromIso, $toIso);

    if (in_array($download, ['csv', 'xls'], true)) {
        audit_log((int)(current_user()['id'] ?? 0), 'export_reports_' . $download, 'system', null, ['from' => $from, 'to' => $to]);
        header('X-Content-Type-Options: nosniff');
        if ($download === 'xls') {
            $exportRows = [];
            foreach (['status' => $statusRows, 'task_type' => $typeRows, 'runner' => $runnerRows] as $section => $sectionRows) {
                foreach ($sectionRows as $row) {
                    $exportRows[] = [$section, export_safe_cell((string)$row['label']), (int)$row['total'], (int)$row['amount_cents'], (int)$row['fee_cents']];
                }
            }
            send_excel_download('litegig_reports.xls', ['section', 'label', 'total', 'amount_cents', 'fee_cents'], $exportRows);
            exit;
        }
        $exportRows = [];
        foreach (['status' => $statusRows, 'task_type' => $typeRows, 'runner' => $runnerRows] as $section => $sectionRows) {
            foreach ($sectionRows as $row) {
                $exportRows[] = [$section, export_safe_cell((string)$row['label']), (int)$row['total'], (int)$row['amount_cents'], (int)$row['fee_cents']];
            }
        }
        send_csv_download('litegig_reports.csv', ['section', 'label', 'total', 'amount_cents', 'fee_cents'], $exportRows);
        exit;
    }

    $total = (int)($summary['total'] ?? 0);
    $completed = (int)($summary['completed'] ?? 0);
    $exceptionCount = (int)($summary['cancelled'] ?? 0) + (int)($summary['disputed'] ?? 0);
    $completionRate = $total > 0 ? number_format(($completed / $total) * 100, 1) . '%' : '0.0%';
    $firstAttemptRate = $total > 0 ? number_format((max(0, $total - $exceptionCount) / $total) * 100, 1) . '%' : '0.0%';
    $avgRating = ((int)($rating['rating_count'] ?? 0) > 0) ? number_format((float)$rating['rating_avg'], 2) . '/5' : 'No ratings';

    $metrics = render_admin_metric_grid([
        ['value' => $total, 'label' => 'Requests'],
        ['value' => $completionRate, 'label' => 'Completion rate'],
        ['value' => $firstAttemptRate, 'label' => 'First-attempt rate'],
        ['value' => $avgRating, 'label' => 'Average rating'],
        ['value' => format_cents((int)($payments['settled_cents'] ?? 0)), 'label' => 'Settled earnings'],
        ['value' => format_cents((int)($payments['settled_fee_cents'] ?? 0)), 'label' => 'Settled fees'],
    ]);

    $renderRows = function (array $rows): string {
        $items = '';
        foreach ($rows as $row) {
            $items .= '<div class="item"><div class="itemtop"><div class="request-main">'
                . '<div class="itemtitle">' . h((string)$row['label']) . '</div>'
                . '<div class="itemmeta">Count <span class="mono">' . (int)$row['total'] . '</span> · Gross <span class="mono">' . h(format_cents((int)$row['amount_cents'])) . '</span> · Fees <span class="mono">' . h(format_cents((int)$row['fee_cents'])) . '</span></div>'
                . '</div></div></div>';
        }
        return $items ?: render_state_box('No rows', 'No records match this reporting period.', [], 'empty');
    };

    $html = '<div class="card"><div class="row"><div><div class="title">Reports</div>'
        . '<div class="sub">Operational reporting for volume, completion, ratings, earnings, fees, and visible trends.</div></div>'
        . '<div class="state-actions" style="margin-top:0"><a class="btn" href="?action=reports&from=' . h(rawurlencode($from)) . '&to=' . h(rawurlencode($to)) . '&download=csv">Download CSV</a><a class="btn" href="?action=reports&from=' . h(rawurlencode($from)) . '&to=' . h(rawurlencode($to)) . '&download=xls">Download Excel</a></div></div>'
        . '<form method="get" action="" class="grid" style="margin-top:12px">'
        . '<input type="hidden" name="action" value="reports">'
        . '<div><label>From</label><input type="date" name="from" value="' . h($from) . '"></div>'
        . '<div><label>To</label><input type="date" name="to" value="' . h($to) . '"></div>'
        . '<button class="btn btn-primary btnblock" type="submit">Apply</button>'
        . '</form>' . $metrics . '</div>'
        . '<div class="card"><div class="title">By status</div><div class="list" style="margin-top:10px">' . $renderRows($statusRows) . '</div></div>'
        . '<div class="card"><div class="title">By task type</div><div class="list" style="margin-top:10px">' . $renderRows($typeRows) . '</div></div>'
        . '<div class="card"><div class="title">By runner</div><div class="list" style="margin-top:10px">' . $renderRows($runnerRows) . '</div></div>';
    render_layout('Reports', $html);
}

function health_check_row(string $status, string $detail = ''): array {
    return ['status' => $status, 'detail' => $detail];
}

function health_snapshot(): array {
    global $CFG;
    $ok = true;
    $ready = true;
    $data = [
        'ok' => true,
        'ready' => true,
        'time' => now_iso(),
        'app' => 'LiteGig',
        'db' => 'unknown',
        'checks' => [],
        'queued_notifications' => 0,
        'failed_notifications' => 0,
        'latest_backup' => null,
        'latest_backup_verified' => null,
    ];
    try {
        $pdo = db();
        $integrity = (string)$pdo->query("PRAGMA integrity_check")->fetchColumn();
        $migrationCount = (int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = '2026-07-04-bootstrap'")->fetchColumn();
        $activeAdmins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1 AND COALESCE(status, 'active') = 'active'")->fetchColumn();
        $failedNotifications = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE status = 'failed'")->fetchColumn();
        $queuedNotifications = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE status IN ('queued','retry')")->fetchColumn();
        $latestBackup = latest_litegig_backup_file((string)$CFG['backup_dir']);

        $data['db'] = 'ok';
        $data['users'] = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $data['requests'] = (int)$pdo->query("SELECT COUNT(*) FROM requests")->fetchColumn();
        $data['queued_notifications'] = $queuedNotifications;
        $data['failed_notifications'] = $failedNotifications;
        $data['checks']['db_integrity'] = health_check_row(strtolower($integrity) === 'ok' ? 'pass' : 'fail', $integrity);
        $data['checks']['migration_ledger'] = health_check_row($migrationCount > 0 ? 'pass' : 'fail');
        $data['checks']['active_admin'] = health_check_row($activeAdmins > 0 ? 'pass' : 'warn');
        $data['checks']['notifications'] = health_check_row($failedNotifications === 0 ? 'pass' : 'warn', $failedNotifications > 0 ? (string)$failedNotifications . ' failed notification(s)' : '');
        if ($latestBackup !== null) {
            $backupVerification = verify_sqlite_backup_file($latestBackup);
            $data['latest_backup'] = basename($latestBackup);
            $data['latest_backup_verified'] = (bool)$backupVerification['ok'];
            $data['checks']['backup'] = health_check_row((bool)$backupVerification['ok'] ? 'pass' : 'warn', (string)$backupVerification['error']);
        } else {
            $data['checks']['backup'] = health_check_row('warn', 'No LiteGig backup file found.');
        }

        foreach ($data['checks'] as $check) {
            if (($check['status'] ?? '') !== 'pass') $ready = false;
        }
    } catch (Throwable $e) {
        $ok = false;
        $ready = false;
        $data['ok'] = false;
        $data['ready'] = false;
        $data['db'] = 'error';
        $data['checks']['db'] = health_check_row('fail', $e->getMessage());
    }

    $data['ready'] = $ready;
    return ['http_status' => $ok ? 200 : 500, 'data' => $data];
}

function action_health(): void {
    $snapshot = health_snapshot();
    http_response_code((int)$snapshot['http_status']);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($snapshot['data'], JSON_UNESCAPED_SLASHES);
    exit;
}

function action_cron_backup(): void {
    require_cron_authorized('Backup');
    $result = create_sqlite_backup();
    audit_log(null, 'cron_backup', 'system', null, [
        'ok' => (bool)$result['ok'],
        'verified' => (bool)$result['verified'],
        'file' => (string)$result['file'],
        'error' => (string)$result['error'],
    ]);
    echo $result['ok']
        ? "OK backup=" . (string)$result['file'] . " verified=1\n"
        : "ERROR " . ((string)$result['error'] !== '' ? (string)$result['error'] : 'backup_failed') . "\n";
    exit;
}
