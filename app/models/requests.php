<?php
declare(strict_types=1);

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
    global $CFG;
    $sign = $cents < 0 ? '-' : '';
    $v = abs($cents);
    $d = number_format($v / 100, 2, '.', '');
    $currency = strtoupper((string)($CFG['currency'] ?? 'USD'));
    if (class_exists('NumberFormatter')) {
        $formatter = new NumberFormatter(app_locale_code(), NumberFormatter::CURRENCY);
        $formatted = $formatter->formatCurrency($cents / 100, $currency);
        if (is_string($formatted) && $formatted !== '') return $formatted;
    }
    return $currency === 'USD' ? $sign . '$' . $d : $sign . $d . ' ' . $currency;
}

function input_datetime_local(array $source, string $key): string {
    $value = input_string($source, $key, 32);
    if ($value === '') return '';
    return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) ? $value : '';
}

function validate_time_window(string $start, string $end, string $label, array &$errors, string $key): void {
    if ($start !== '' && $end !== '' && strcmp($start, $end) > 0) {
        $errors[$key] = $label . ' end must be after the start.';
    }
}

function request_schedule_values(array $r): array {
    return [
        'pickup_window_start' => (string)($r['pickup_window_start'] ?? ''),
        'pickup_window_end' => (string)($r['pickup_window_end'] ?? ''),
        'delivery_window_start' => (string)($r['delivery_window_start'] ?? ''),
        'delivery_window_end' => (string)($r['delivery_window_end'] ?? ''),
        'sla_due_at' => (string)($r['sla_due_at'] ?? ''),
    ];
}

function request_transition_rules(): array {
    return [
        'accept' => [
            'from' => ['new'],
            'to' => 'accepted',
            'event' => 'accepted',
        ],
        'mark_picked_up' => [
            'from' => ['accepted'],
            'to' => 'picked_up',
            'event' => 'picked_up',
        ],
        'confirm_payment' => [
            'from' => ['accepted', 'picked_up'],
            'to' => 'payment_confirmed',
            'event' => 'payment_confirmed',
        ],
        'gateway_payment_confirmed' => [
            'from' => ['accepted', 'picked_up'],
            'to' => 'payment_confirmed',
            'event' => 'payment_confirmed',
        ],
        'mark_delivered' => [
            'from' => ['picked_up', 'payment_confirmed'],
            'to' => 'delivered',
            'event' => 'delivered',
        ],
        'confirm_delivery' => [
            'from' => ['delivered'],
            'to' => 'completed',
            'event' => 'delivery_confirmed',
        ],
        'complete_after_ratings' => [
            'from' => ['delivered'],
            'to' => 'completed',
            'event' => 'completed',
        ],
        'cancel' => [
            'from' => ['new', 'accepted', 'picked_up', 'payment_confirmed', 'delivered', 'disputed'],
            'to' => 'cancelled',
            'event' => 'cancelled',
        ],
        'expire' => [
            'from' => ['new'],
            'to' => 'expired',
            'event' => 'expired',
        ],
        'decline' => [
            'from' => ['accepted'],
            'to' => 'new',
            'event' => 'declined',
        ],
        'dispute' => [
            'from' => ['accepted', 'picked_up', 'payment_confirmed', 'delivered'],
            'to' => 'disputed',
            'event' => 'disputed',
        ],
        'reopen' => [
            'from' => ['cancelled', 'disputed', 'expired'],
            'to' => 'new',
            'event' => 'reopened',
        ],
    ];
}

function request_transition_rule(string $transition): ?array {
    $rules = request_transition_rules();
    return $rules[$transition] ?? null;
}

function request_transition_source_statuses(string $transition): array {
    $rule = request_transition_rule($transition);
    $from = is_array($rule) ? ($rule['from'] ?? []) : [];
    return is_array($from) ? array_values(array_filter($from, 'is_string')) : [];
}

function request_transition_target_status(string $transition): string {
    $rule = request_transition_rule($transition);
    return is_array($rule) ? (string)($rule['to'] ?? '') : '';
}

function request_transition_event_type(string $transition): string {
    $rule = request_transition_rule($transition);
    return is_array($rule) ? (string)($rule['event'] ?? '') : '';
}

function request_transition_allows(string $transition, string $fromStatus): bool {
    return in_array($fromStatus, request_transition_source_statuses($transition), true);
}

function request_status_transition_allowed(string $fromStatus, string $toStatus): bool {
    foreach (request_transition_rules() as $rule) {
        if (!is_array($rule) || (string)($rule['to'] ?? '') !== $toStatus) continue;
        $sources = $rule['from'] ?? [];
        if (is_array($sources) && in_array($fromStatus, $sources, true)) return true;
    }
    return false;
}

function request_transition_guard_sql(string $transition, string $column = 'status'): string {
    if (!preg_match('/^[A-Za-z0-9_.]+$/', $column)) {
        throw new InvalidArgumentException('Invalid transition guard column.');
    }
    $sources = request_transition_source_statuses($transition);
    if (!$sources) return '1 = 0';
    $quoted = array_map(static fn(string $status): string => "'" . str_replace("'", "''", $status) . "'", $sources);
    return count($quoted) === 1
        ? $column . ' = ' . $quoted[0]
        : $column . ' IN (' . implode(',', $quoted) . ')';
}

function request_due_status(array $r): array {
    $status = (string)($r['status'] ?? '');
    if (in_array($status, ['completed', 'expired', 'cancelled', 'disputed'], true)) {
        return ['tone' => '', 'label' => '', 'due_at' => ''];
    }
    $schedule = request_schedule_values($r);
    $dueAt = $schedule['sla_due_at'] ?: ($schedule['delivery_window_end'] ?: $schedule['pickup_window_end']);
    if ($dueAt === '') return ['tone' => '', 'label' => '', 'due_at' => ''];
    $ts = strtotime($dueAt);
    if ($ts === false) return ['tone' => '', 'label' => '', 'due_at' => $dueAt];
    $delta = $ts - time();
    if ($delta < 0) return ['tone' => 'overdue', 'label' => t('due.overdue', 'Overdue'), 'due_at' => $dueAt];
    if ($delta <= 24 * 60 * 60) return ['tone' => 'soon', 'label' => t('due.soon', 'Due soon'), 'due_at' => $dueAt];
    return ['tone' => 'scheduled', 'label' => t('due.scheduled', 'Scheduled'), 'due_at' => $dueAt];
}

function render_due_badge(array $r): string {
    $due = request_due_status($r);
    if ($due['label'] === '') return '';
    $class = 'pill status-chip schedule-' . preg_replace('/[^a-z0-9_-]/i', '', $due['tone']);
    return '<span class="' . h($class) . '">' . h($due['label']) . '</span>';
}

function render_task_type_badge(string $name): string {
    return '<span class="badge">' . h($name) . '</span>';
}

function render_status_chip(string $status): string {
    $labels = status_options();
    $label = $labels[$status] ?? t('status.' . $status, ucwords(str_replace('_', ' ', $status)));
    return '<span class="pill status-chip">' . h($label) . '</span>';
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
                $stored = store_uploaded_file($files[$key], $errors, $key, 'att');
                if ($stored) $value = $stored['name'];
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
        return ((int)$v === 1) ? t('common.yes', 'Yes') : t('common.no', 'No');
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

function stored_upload_basename(string $storedName): string {
    $raw = trim($storedName);
    if ($raw === '' || preg_match('/[\/\\\\]/', $raw)) return '';
    $name = basename($raw);
    return $name === $raw ? $name : '';
}

function detect_file_mime(string $path): string {
    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = (string)finfo_file($finfo, $path);
            finfo_close($finfo);
            if ($detected !== '') $mime = $detected;
        }
    }
    return $mime;
}

function resolve_stored_upload_path(string $storedName): ?array {
    global $CFG;

    $name = stored_upload_basename($storedName);
    if ($name === '') return null;

    $base = realpath((string)$CFG['upload_dir']);
    $path = realpath((string)$CFG['upload_dir'] . DIRECTORY_SEPARATOR . $name);
    if (!$base || !$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) return null;

    return [
        'path' => $path,
        'stored_name' => $name,
        'mime' => detect_file_mime($path),
    ];
}

function request_attachment_download(array $r, string $field): ?array {
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $field)) return null;

    $tt = normalize_task_type_row([
        'id' => $r['task_type_id'] ?? 0,
        'name' => $r['task_type_name'] ?? '',
        'fields_json' => $r['task_type_fields_json'] ?? '[]',
        'created_at' => '',
    ]);
    $isAttachmentField = false;
    foreach ($tt['fields'] as $f) {
        if (is_array($f) && (string)($f['key'] ?? '') === $field && (string)($f['type'] ?? '') === 'attachment') {
            $isAttachmentField = true;
            break;
        }
    }
    if (!$isAttachmentField) return null;

    $meta = json_decode((string)($r['metadata'] ?? ''), true);
    if (!is_array($meta)) return null;
    $resolved = resolve_stored_upload_path((string)($meta[$field] ?? ''));
    if (!$resolved) return null;

    $resolved['download_name'] = (string)$resolved['stored_name'];
    return $resolved;
}

function event_attachment_download(array $event): ?array {
    $resolved = resolve_stored_upload_path((string)($event['attachment_name'] ?? ''));
    if (!$resolved) return null;

    $mime = (string)($event['attachment_mime'] ?? '');
    if ($mime !== '') $resolved['mime'] = $mime;
    $downloadName = sanitize_upload_filename((string)($event['attachment_original_name'] ?? ''));
    $resolved['download_name'] = $downloadName !== '' ? $downloadName : (string)$resolved['stored_name'];
    return $resolved;
}

function saved_view_scope(string $scope): string {
    return in_array($scope, ['requests', 'open_pool'], true) ? $scope : 'requests';
}

function saved_views_for_user(int $userId, string $scope): array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM saved_views WHERE user_id = ? AND scope = ? ORDER BY id DESC LIMIT 12");
    $stmt->execute([$userId, saved_view_scope($scope)]);
    return $stmt->fetchAll();
}

function save_user_view(int $userId, string $scope, string $name, array $query): int {
    $pdo = db();
    $now = now_iso();
    $stmt = $pdo->prepare("INSERT INTO saved_views (user_id, scope, name, query_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $userId,
        saved_view_scope($scope),
        $name,
        json_encode($query, JSON_UNESCAPED_SLASHES),
        $now,
        $now,
    ]);
    return (int)$pdo->lastInsertId();
}

function delete_user_view(int $userId, int $viewId): bool {
    $pdo = db();
    $stmt = $pdo->prepare("DELETE FROM saved_views WHERE id = ? AND user_id = ?");
    $stmt->execute([$viewId, $userId]);
    return $stmt->rowCount() > 0;
}

function payment_for_request(int $requestId): ?array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT p.*, u.display_name AS confirmed_by_name
        FROM payments p
        LEFT JOIN users u ON u.id = p.confirmed_by
        WHERE p.request_id = ?");
    $stmt->execute([$requestId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function generate_delivery_otp_code(): string {
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function delivery_otp_required(array $r): bool {
    return $r['runner_id'] !== null
        && in_array((string)($r['status'] ?? ''), ['accepted', 'picked_up', 'payment_confirmed'], true);
}

function create_delivery_otp(int $requestId, ?int $actorId): ?array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, status, runner_id FROM requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $r = $stmt->fetch();
    if (!$r || !delivery_otp_required($r)) return null;

    $code = generate_delivery_otp_code();
    $hint = substr($code, -2);
    $now = now_iso();
    $upd = $pdo->prepare("UPDATE requests
        SET delivery_otp_hash = ?, delivery_otp_hint = ?, delivery_otp_created_at = ?, delivery_otp_verified_at = NULL, updated_at = ?
        WHERE id = ?");
    $upd->execute([password_hash($code, PASSWORD_DEFAULT), $hint, $now, $now, $requestId]);
    add_event($requestId, $actorId, 'delivery_otp_created', 'Delivery OTP generated; code ends in ' . $hint);
    return ['code' => $code, 'hint' => $hint, 'created_at' => $now];
}

function verify_delivery_otp_for_request(array $r, string $code): bool {
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $hash = (string)($r['delivery_otp_hash'] ?? '');
    return $hash !== '' && password_verify($code, $hash);
}

function mark_delivery_otp_verified(int $requestId): void {
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE requests SET delivery_otp_verified_at = ?, updated_at = ? WHERE id = ?");
    $now = now_iso();
    $stmt->execute([$now, $now, $requestId]);
}

function generate_gateway_reference(PDO $pdo): string {
    for ($i = 0; $i < 20; $i++) {
        $ref = 'lgw_' . strtolower(bin2hex(random_bytes(8)));
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM payments WHERE gateway_reference = ?");
        $stmt->execute([$ref]);
        if ((int)$stmt->fetch()['c'] === 0) return $ref;
    }
    return 'lgw_' . strtolower(bin2hex(random_bytes(12)));
}

function record_manual_payment(array $r, int $confirmedBy): array {
    $pdo = db();
    $existing = payment_for_request((int)$r['id']);
    $now = now_iso();
    if ($existing) {
        if ((string)$existing['status'] !== 'confirmed' || (string)$existing['method'] !== 'manual') {
            $stmt = $pdo->prepare("UPDATE payments
                SET method = 'manual', amount_cents = ?, fee_cents = ?, status = 'confirmed', confirmed_by = ?, confirmed_at = ?, updated_at = ?
                WHERE request_id = ?");
            $stmt->execute([(int)$r['price_cents'], (int)$r['fee_cents'], $confirmedBy, $now, $now, (int)$r['id']]);
        }
        return payment_for_request((int)$r['id']) ?: $existing;
    }
    $receipt = generate_receipt_no($pdo);
    $stmt = $pdo->prepare("INSERT INTO payments
        (request_id, method, amount_cents, fee_cents, status, confirmed_by, confirmed_at, receipt_no, created_at, updated_at)
        VALUES (?, 'manual', ?, ?, 'confirmed', ?, ?, ?, ?, ?)");
    $stmt->execute([
        (int)$r['id'],
        (int)$r['price_cents'],
        (int)$r['fee_cents'],
        $confirmedBy,
        $now,
        $receipt,
        $now,
        $now,
    ]);
    return payment_for_request((int)$r['id']) ?: [];
}

function record_gateway_payment(array $r): array {
    $pdo = db();
    $existing = payment_for_request((int)$r['id']);
    if ($existing) return $existing;
    $now = now_iso();
    $receipt = generate_receipt_no($pdo);
    $reference = generate_gateway_reference($pdo);
    $stmt = $pdo->prepare("INSERT INTO payments
        (request_id, method, amount_cents, fee_cents, status, confirmed_by, confirmed_at, receipt_no, gateway_reference, gateway_payload_json, created_at, updated_at)
        VALUES (?, 'gateway', ?, ?, 'pending', NULL, NULL, ?, ?, '{}', ?, ?)");
    $stmt->execute([
        (int)$r['id'],
        (int)$r['price_cents'],
        (int)$r['fee_cents'],
        $receipt,
        $reference,
        $now,
        $now,
    ]);
    return payment_for_request((int)$r['id']) ?: [];
}

function record_payment_webhook_event(string $eventId, string $reference, string $status, array $payload): bool {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO payment_webhook_events (event_id, gateway_reference, status, payload_json, processed_at)
        VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$eventId, $reference, $status, json_encode($payload, JSON_UNESCAPED_SLASHES), now_iso()]);
    return $stmt->rowCount() > 0;
}

function payment_by_gateway_reference(string $reference): ?array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT p.*, u.display_name AS confirmed_by_name
        FROM payments p
        LEFT JOIN users u ON u.id = p.confirmed_by
        WHERE p.gateway_reference = ?");
    $stmt->execute([$reference]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function update_gateway_payment_status(int $paymentId, string $status, string $eventId, array $payload): void {
    $allowed = ['pending', 'confirmed', 'refunded', 'failed'];
    if (!in_array($status, $allowed, true)) $status = 'pending';
    $pdo = db();
    $now = now_iso();
    $confirmedAt = $status === 'confirmed' ? $now : null;
    $stmt = $pdo->prepare("UPDATE payments
        SET status = ?, confirmed_at = COALESCE(?, confirmed_at), gateway_event_id = ?, gateway_payload_json = ?, updated_at = ?
        WHERE id = ?");
    $stmt->execute([$status, $confirmedAt, $eventId, json_encode($payload, JSON_UNESCAPED_SLASHES), $now, $paymentId]);
}
