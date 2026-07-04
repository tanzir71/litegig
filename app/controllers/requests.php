<?php
declare(strict_types=1);

function render_create_request_form(array $types, ?array $tt, array $values, array $meta, array $errors, array $options = []): string {
    $heading = (string)($options['heading'] ?? 'Create request');
    $subText = (string)($options['sub'] ?? 'Choose a task type — its schema renders the dynamic fields below.');
    $action = (string)($options['action'] ?? '?action=create_request');
    $button = (string)($options['button'] ?? 'Post request');
    $e = fn(string $k) => isset($errors[$k]) ? '<div class="err">' . h($errors[$k]) . '</div>' : '';
    if (!$types) {
        $u = current_user();
        $actions = ((int)($u['is_admin'] ?? 0) === 1)
            ? [['label' => 'Create task type', 'href' => '?action=create_task_type', 'primary' => true]]
            : [['label' => 'Back to requests', 'href' => '?action=list_requests', 'primary' => true]];
        $body = ((int)($u['is_admin'] ?? 0) === 1)
            ? 'Create a task type before posting requests. Task types define the fields requesters fill in.'
            : 'No request templates are available yet. Ask an admin to create a task type before posting.';
        return '<div class="card"><div class="title">' . h($heading) . '</div>'
            . '<div style="margin-top:12px">' . render_state_box('No task types available', $body, $actions, 'empty') . '</div></div>';
    }

    $typeOptions = '';
    foreach ($types as $t) {
        $sel = ((int)$values['task_type_id'] === (int)$t['id']) ? ' selected' : '';
        $typeOptions .= '<option value="' . (int)$t['id'] . '"' . $sel . '>' . h($t['name']) . '</option>';
    }
    $templates = [];
    foreach ($types as $t) {
        $templates[(string)$t['id']] = ['name' => $t['name'], 'fields' => $t['fields'], 'summary_fields' => $t['summary_fields']];
    }

    $html = '<div class="card"><div class="title">' . h($heading) . '</div>'
        . '<div class="sub">' . h($subText) . '</div>'
        . '<form method="post" action="' . h($action) . '" enctype="multipart/form-data" class="stack" style="margin-top:10px" id="createForm">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<div><label>Task type</label><select name="task_type_id" id="task_type_id" required>' . $typeOptions . '</select>' . $e('task_type_id') . '</div>'
        . '<div><label>Title</label><input name="title" value="' . h((string)$values['title']) . '" placeholder="Quick summary" required>' . $e('title') . '</div>'
        . '<div><label>Description</label><textarea name="description" placeholder="Details for the runner" required>' . h((string)$values['description']) . '</textarea>' . $e('description') . '</div>'
        . '<div class="grid">'
        . '<div><label>Pickup window start</label><input type="datetime-local" name="pickup_window_start" value="' . h((string)$values['pickup_window_start']) . '">' . $e('pickup_window_start') . '</div>'
        . '<div><label>Pickup window end</label><input type="datetime-local" name="pickup_window_end" value="' . h((string)$values['pickup_window_end']) . '">' . $e('pickup_window_end') . '</div>'
        . '<div><label>Delivery window start</label><input type="datetime-local" name="delivery_window_start" value="' . h((string)$values['delivery_window_start']) . '">' . $e('delivery_window_start') . '</div>'
        . '<div><label>Delivery window end</label><input type="datetime-local" name="delivery_window_end" value="' . h((string)$values['delivery_window_end']) . '">' . $e('delivery_window_end') . '</div>'
        . '</div>'
        . '<div id="form_state" class="inline-state" role="alert"></div>'
        . '<div id="dynamic_fields"></div>'
        . '<button class="btn btn-primary btnblock" type="submit">' . h($button) . '</button>'
        . '</form></div>';

    $script = <<<'HTML'
<script>
const TASK_TYPES=__TASK_TYPES__;
const PREV_META=__PREV_META__;
const ERRORS=__ERRORS__;
function el(tag, attrs, text) {
    const e = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach((k) => {
        if (attrs[k] !== null && attrs[k] !== undefined) e.setAttribute(k, attrs[k]);
    });
    if (text !== undefined) e.textContent = text;
    return e;
}
function appendError(wrap, key) {
    if (ERRORS && ERRORS[key]) wrap.appendChild(el("div", {class: "err"}, String(ERRORS[key])));
}
function appendHelp(wrap, text) {
    if (text) wrap.appendChild(el("div", {class: "help"}, text));
}
function setInlineState(node, text, tone) {
    if (!node) return;
    node.textContent = text || "";
    node.className = "inline-state" + (text ? " is-visible" : "") + (tone ? " is-" + tone : "");
}
function renderGeo(field, value) {
    const key = field.key;
    const wrap = el("div");
    wrap.style.marginTop = "12px";
    wrap.appendChild(el("label", null, (field.label || key) + (field.required ? " *" : "")));
    const a = el("input", {type: "text", name: key + "_address", placeholder: field.placeholder || "Address"});
    a.value = (value && value.address) || "";
    if (field.required) a.required = true;
    wrap.appendChild(a);
    const lat = el("input", {type: "hidden", name: key + "_lat"});
    lat.value = (value && value.lat != null) ? String(value.lat) : "";
    wrap.appendChild(lat);
    const lng = el("input", {type: "hidden", name: key + "_lng"});
    lng.value = (value && value.lng != null) ? String(value.lng) : "";
    wrap.appendChild(lng);
    const b = el("button", {type: "button", class: "btn", style: "margin-top:8px"}, "Use my location");
    const status = el("div", {class: "inline-state", role: "status"});
    b.onclick = () => {
        if (!navigator.geolocation) {
            setInlineState(status, "Location is not available in this browser. Enter the address manually.", "error");
            return;
        }
        b.disabled = true;
        b.setAttribute("aria-busy", "true");
        setInlineState(status, "Finding your location...", "");
        navigator.geolocation.getCurrentPosition((pos) => {
            lat.value = String(pos.coords.latitude);
            lng.value = String(pos.coords.longitude);
            if (!a.value) a.value = `${pos.coords.latitude.toFixed(6)}, ${pos.coords.longitude.toFixed(6)}`;
            b.disabled = false;
            b.removeAttribute("aria-busy");
            setInlineState(status, "Location captured. Review the address before posting.", "ok");
        }, (err) => {
            b.disabled = false;
            b.removeAttribute("aria-busy");
            setInlineState(status, "Could not use location: " + err.message + ". Enter the address manually.", "error");
        }, {enableHighAccuracy: true, timeout: 8000, maximumAge: 15000});
    };
    wrap.appendChild(b);
    wrap.appendChild(status);
    appendHelp(wrap, "Stored as address and coordinates.");
    appendError(wrap, key);
    return wrap;
}
function renderField(field, value) {
    const key = field.key || "";
    const type = field.type || "text";
    const wrap = el("div");
    wrap.style.marginTop = "12px";
    if (type === "geo") return renderGeo(field, value);
    wrap.appendChild(el("label", null, (field.label || key) + (field.required ? " *" : "")));
    let input = null;
    if (type === "textarea") {
        input = el("textarea", {name: key, placeholder: field.placeholder || ""});
        input.value = value ?? "";
    } else if (type === "select") {
        input = el("select", {name: key});
        (field.options || []).forEach((o) => input.appendChild(el("option", {value: o.value || ""}, o.label || o.value || "")));
        input.value = value ?? "";
    } else if (type === "boolean") {
        const c = el("div");
        const cb = el("input", {type: "checkbox", name: key, value: "1"});
        cb.style.width = "auto";
        cb.style.marginRight = "10px";
        cb.checked = String(value) === "1" || value === 1 || value === true;
        c.appendChild(cb);
        c.appendChild(el("span", null, "Yes"));
        wrap.appendChild(c);
        appendError(wrap, key);
        return wrap;
    } else if (type === "price") {
        input = el("input", {type: "text", name: key, placeholder: field.placeholder || "e.g., 12.34", inputmode: "decimal"});
        input.value = (typeof value === "number") ? (value / 100).toFixed(2) : (value ?? "");
    } else if (type === "number") {
        input = el("input", {type: "number", name: key, step: "any", placeholder: field.placeholder || ""});
        input.value = value ?? "";
    } else if (type === "date") {
        input = el("input", {type: "date", name: key});
        input.value = value ?? "";
    } else if (type === "time") {
        input = el("input", {type: "time", name: key});
        input.value = value ?? "";
    } else if (type === "datetime") {
        input = el("input", {type: "datetime-local", name: key});
        input.value = value ?? "";
    } else if (type === "attachment") {
        input = el("input", {type: "file", name: key});
        if (value) appendHelp(wrap, "Current file: " + String(value));
    } else if (type === "note") {
        input = el("textarea", {name: key, placeholder: field.placeholder || ""});
        input.value = value ?? "";
    } else if (type === "readonly") {
        input = el("input", {type: "text", name: key, readonly: "readonly"});
        input.value = value ?? "";
    } else {
        input = el("input", {type: "text", name: key, placeholder: field.placeholder || ""});
        input.value = value ?? "";
    }
    if (field.required && input && type !== "attachment") input.required = true;
    if (input) wrap.appendChild(input);
    appendError(wrap, key);
    return wrap;
}
function normalizeTemplate(t) {
    if (!t) return {fields: [], summary_fields: []};
    return {fields: t.fields || [], summary_fields: t.summary_fields || []};
}
function renderDynamic() {
    const sel = document.getElementById("task_type_id");
    const id = sel.value;
    const t = normalizeTemplate(TASK_TYPES[id]);
    const root = document.getElementById("dynamic_fields");
    root.replaceChildren();
    t.fields.forEach((f) => {
        if (!f || typeof f !== "object") return;
        root.appendChild(renderField(f, PREV_META[f.key]));
    });
}
document.getElementById("task_type_id").addEventListener("change", () => renderDynamic());
renderDynamic();
document.getElementById("createForm").addEventListener("submit", (e) => {
    const form = document.getElementById("createForm");
    const id = document.getElementById("task_type_id").value;
    const t = normalizeTemplate(TASK_TYPES[id]);
    const missing = [];
    t.fields.forEach((f) => {
        if (!f.required || f.type === "boolean") return;
        const label = f.label || f.key;
        if (f.type === "geo") {
            const a = form.elements[f.key + "_address"];
            if (a && !String(a.value || "").trim()) missing.push(label);
        } else if (f.type === "attachment") {
            const inp = form.elements[f.key];
            if (inp && !inp.value && !PREV_META[f.key]) missing.push(label);
        } else {
            const inp = form.elements[f.key];
            if (inp && !String(inp.value || "").trim()) missing.push(label);
        }
    });
    if (missing.length) {
        e.preventDefault();
        setInlineState(document.getElementById("form_state"), "Please fill required fields: " + missing.join(", "), "error");
    }
});
</script>
HTML;
    $html .= strtr($script, [
        '__TASK_TYPES__' => json_for_html_script($templates),
        '__PREV_META__' => json_for_html_script($meta),
        '__ERRORS__' => json_for_html_script($errors),
    ]);

    return $html;
}

function action_create_request(): void {
    global $CFG;
    $u = require_login();
    $types = get_task_types();

    $typeId = input_int($_GET, 'task_type_id', input_int($_POST, 'task_type_id', 0, 0, 1000000000), 0, 1000000000);
    $tt = $typeId ? get_task_type_by_id($typeId, false) : null;
    if (!$tt && count($types) > 0) $tt = get_task_type_by_id((int)$types[0]['id'], false);

    $values = [
        'task_type_id' => $tt ? (int)$tt['id'] : 0,
        'title' => input_string($_POST, 'title', 180),
        'description' => input_string($_POST, 'description', 5000),
        'pickup_window_start' => input_datetime_local($_POST, 'pickup_window_start'),
        'pickup_window_end' => input_datetime_local($_POST, 'pickup_window_end'),
        'delivery_window_start' => input_datetime_local($_POST, 'delivery_window_start'),
        'delivery_window_end' => input_datetime_local($_POST, 'delivery_window_end'),
    ];
    $errors = [];
    $meta = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        enforce_critical_rate_limit('create_request', (int)$u['id']);
        $typeId = input_int($_POST, 'task_type_id', 0, 0, 1000000000);
        $tt = $typeId ? get_task_type_by_id($typeId, false) : null;
        if (!$tt) {
            $errors['task_type_id'] = 'Choose a task type.';
        }
        $values['task_type_id'] = $typeId;
        $values['title'] = input_string($_POST, 'title', 180);
        $values['description'] = input_string($_POST, 'description', 5000);
        $values['pickup_window_start'] = input_datetime_local($_POST, 'pickup_window_start');
        $values['pickup_window_end'] = input_datetime_local($_POST, 'pickup_window_end');
        $values['delivery_window_start'] = input_datetime_local($_POST, 'delivery_window_start');
        $values['delivery_window_end'] = input_datetime_local($_POST, 'delivery_window_end');
        if ($values['title'] === '') $errors['title'] = 'Required.';
        if ($values['description'] === '') $errors['description'] = 'Required.';
        validate_time_window((string)$values['pickup_window_start'], (string)$values['pickup_window_end'], 'Pickup window', $errors, 'pickup_window_end');
        validate_time_window((string)$values['delivery_window_start'], (string)$values['delivery_window_end'], 'Delivery window', $errors, 'delivery_window_end');

        if ($tt) {
            $meta = coerce_metadata_from_post($tt, $_POST, $_FILES, $errors, []);
        }

        if (!$errors && $tt) {
            $priceCents = request_primary_price_cents($tt, $meta);
            $feeCents = (int)round($priceCents * (app_fee_percent() / 100.0));
            $pdo = db();
            $stmt = $pdo->prepare("INSERT INTO requests
                (requester_id, task_type_id, code, title, description, price_cents, fee_cents, status, runner_id, metadata, pickup_window_start, pickup_window_end, delivery_window_start, delivery_window_end, sla_due_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'new', NULL, ?, ?, ?, ?, ?, ?, ?, ?)");
            $now = now_iso();
            $slaDueAt = (string)$values['delivery_window_end'] !== '' ? (string)$values['delivery_window_end'] : ((string)$values['pickup_window_end'] ?: null);
            $stmt->execute([
                (int)$u['id'],
                (int)$tt['id'],
                generate_request_code($pdo),
                $values['title'],
                $values['description'],
                $priceCents,
                $feeCents,
                json_encode($meta, JSON_UNESCAPED_SLASHES),
                (string)$values['pickup_window_start'] ?: null,
                (string)$values['pickup_window_end'] ?: null,
                (string)$values['delivery_window_start'] ?: null,
                (string)$values['delivery_window_end'] ?: null,
                $slaDueAt,
                $now,
                $now,
            ]);
            $rid = (int)$pdo->lastInsertId();
            add_event($rid, (int)$u['id'], 'created', 'Request created');
            audit_log((int)$u['id'], 'create_request', 'request', $rid, ['task_type' => $tt['name']]);
            flash_set('ok', 'Request created.');
            redirect_to('?action=get_request&id=' . $rid);
        }
    } else {
        if ($tt) {
            foreach ($tt['fields'] as $f) {
                if (!is_array($f)) continue;
                $k = (string)($f['key'] ?? '');
                if ($k === '') continue;
                $meta[$k] = null;
            }
        }
    }

    render_layout('Create Request', render_create_request_form($types, $tt, $values, $meta, $errors));
}

function action_edit_request(): void {
    global $CFG;
    $u = require_login();
    $id = input_int($_GET, 'id', 0, 0, 1000000000);
    $r = fetch_request_full($id);
    if (!$r) {
        render_not_found('Request not found', 'This request may have been deleted, expired, or the link is stale.');
    }
    if (!can_edit_request($u, $r)) {
        render_forbidden('Edit unavailable', 'Requests can only be edited by the requester or an admin before a runner accepts.');
    }

    $types = get_task_types();
    $currentTaskType = get_task_type_by_id((int)$r['task_type_id'], true);
    if ($currentTaskType && !in_array((int)$currentTaskType['id'], array_map(fn(array $t): int => (int)$t['id'], $types), true)) {
        $types[] = $currentTaskType;
    }
    $existingMeta = json_decode((string)$r['metadata'], true);
    if (!is_array($existingMeta)) $existingMeta = [];
    $typeId = input_int($_POST, 'task_type_id', (int)$r['task_type_id'], 0, 1000000000);
    $tt = ($currentTaskType && $typeId === (int)$currentTaskType['id'])
        ? $currentTaskType
        : get_task_type_by_id($typeId, false);
    if (!$tt) $tt = $currentTaskType;
    $values = [
        'task_type_id' => $tt ? (int)$tt['id'] : (int)$r['task_type_id'],
        'title' => $_SERVER['REQUEST_METHOD'] === 'POST' ? input_string($_POST, 'title', 180) : (string)$r['title'],
        'description' => $_SERVER['REQUEST_METHOD'] === 'POST' ? input_string($_POST, 'description', 5000) : (string)$r['description'],
        'pickup_window_start' => $_SERVER['REQUEST_METHOD'] === 'POST' ? input_datetime_local($_POST, 'pickup_window_start') : (string)($r['pickup_window_start'] ?? ''),
        'pickup_window_end' => $_SERVER['REQUEST_METHOD'] === 'POST' ? input_datetime_local($_POST, 'pickup_window_end') : (string)($r['pickup_window_end'] ?? ''),
        'delivery_window_start' => $_SERVER['REQUEST_METHOD'] === 'POST' ? input_datetime_local($_POST, 'delivery_window_start') : (string)($r['delivery_window_start'] ?? ''),
        'delivery_window_end' => $_SERVER['REQUEST_METHOD'] === 'POST' ? input_datetime_local($_POST, 'delivery_window_end') : (string)($r['delivery_window_end'] ?? ''),
    ];
    $meta = $existingMeta;
    $errors = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        enforce_critical_rate_limit('edit_request', (int)$u['id']);
        if (!$tt) $errors['task_type_id'] = 'Choose a task type.';
        if ($values['title'] === '') $errors['title'] = 'Required.';
        if ($values['description'] === '') $errors['description'] = 'Required.';
        validate_time_window((string)$values['pickup_window_start'], (string)$values['pickup_window_end'], 'Pickup window', $errors, 'pickup_window_end');
        validate_time_window((string)$values['delivery_window_start'], (string)$values['delivery_window_end'], 'Delivery window', $errors, 'delivery_window_end');
        if ($tt) {
            $meta = coerce_metadata_from_post($tt, $_POST, $_FILES, $errors, $existingMeta);
        }

        if (!$errors && $tt) {
            $priceCents = request_primary_price_cents($tt, $meta);
            $feeCents = (int)round($priceCents * (app_fee_percent() / 100.0));
            $slaDueAt = (string)$values['delivery_window_end'] !== '' ? (string)$values['delivery_window_end'] : ((string)$values['pickup_window_end'] ?: null);
            $pdo = db();
            $stmt = $pdo->prepare("UPDATE requests SET task_type_id=?, title=?, description=?, price_cents=?, fee_cents=?, metadata=?, pickup_window_start=?, pickup_window_end=?, delivery_window_start=?, delivery_window_end=?, sla_due_at=?, updated_at=? WHERE id=? AND status='new' AND runner_id IS NULL");
            $now = now_iso();
            $stmt->execute([
                (int)$tt['id'],
                (string)$values['title'],
                (string)$values['description'],
                $priceCents,
                $feeCents,
                json_encode($meta, JSON_UNESCAPED_SLASHES),
                (string)$values['pickup_window_start'] ?: null,
                (string)$values['pickup_window_end'] ?: null,
                (string)$values['delivery_window_start'] ?: null,
                (string)$values['delivery_window_end'] ?: null,
                $slaDueAt,
                $now,
                $id,
            ]);
            if ($stmt->rowCount() < 1) {
                flash_set('error', 'Request can no longer be edited.');
                redirect_to('?action=get_request&id=' . $id);
            }
            add_event($id, (int)$u['id'], 'edited', 'Request details edited before acceptance');
            audit_log((int)$u['id'], 'edit_request', 'request', $id, [
                'before' => [
                    'task_type_id' => (int)$r['task_type_id'],
                    'title' => (string)$r['title'],
                    'price_cents' => (int)$r['price_cents'],
                ],
                'after' => [
                    'task_type_id' => (int)$tt['id'],
                    'title' => (string)$values['title'],
                    'price_cents' => $priceCents,
                ],
            ]);
            flash_set('ok', 'Request updated.');
            redirect_to('?action=get_request&id=' . $id);
        }
    }

    render_layout('Edit Request', render_create_request_form($types, $tt, $values, $meta, $errors, [
        'heading' => 'Edit request',
        'sub' => 'Editing is available until a runner accepts. Changes write an audit event.',
        'action' => '?action=edit_request&id=' . $id,
        'button' => 'Save changes',
    ]));
}

function input_money_cents_optional(array $source, string $key): ?int {
    $raw = input_string($source, $key, 24);
    if ($raw === '' || !is_numeric($raw)) return null;
    return max(0, (int)round((float)$raw * 100));
}

function uploaded_event_attachment(string $field, string $prefix, array &$errors): ?array {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    return store_uploaded_file($_FILES[$field], $errors, $field, $prefix);
}

function render_file_input(string $field, string $label, bool $capture = false): string {
    $captureAttr = $capture ? ' capture="environment"' : '';
    return '<div><label>' . h($label) . '</label>'
        . '<input type="file" name="' . h($field) . '" accept="image/*,.pdf,.txt,.csv"' . $captureAttr . '>'
        . '<div class="help">Optional. Images, PDF, TXT, and CSV files are stored privately and shown inline to request viewers.</div>'
        . '</div>';
}

function render_delivery_otp_input(array $r): string {
    $hint = trim((string)($r['delivery_otp_hint'] ?? ''));
    $help = $hint !== ''
        ? 'Ask the requester for the 6-digit delivery OTP. Current code ends in ' . $hint . '.'
        : 'Ask the requester to generate a delivery OTP before handoff.';
    return '<div><label>Delivery OTP</label>'
        . '<input name="delivery_otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000" required>'
        . '<div class="help">' . h($help) . '</div></div>';
}

function render_delivery_otp_panel(array $u, array $r): string {
    if (!delivery_otp_required($r) && trim((string)($r['delivery_otp_verified_at'] ?? '')) === '') {
        return '';
    }
    $hint = trim((string)($r['delivery_otp_hint'] ?? ''));
    $createdAt = trim((string)($r['delivery_otp_created_at'] ?? ''));
    $verifiedAt = trim((string)($r['delivery_otp_verified_at'] ?? ''));
    $rows = '';
    $rows .= '<tr><td>Status</td><td>' . ($verifiedAt !== '' ? '<span class="pill status-chip">Verified</span>' : '<span class="pill status-chip">Required</span>') . '</td></tr>';
    if ($hint !== '') $rows .= '<tr><td>Code hint</td><td>Ends in <span class="mono">' . h($hint) . '</span></td></tr>';
    if ($createdAt !== '') $rows .= '<tr><td>Generated</td><td><span class="mono">' . h($createdAt) . '</span></td></tr>';
    if ($verifiedAt !== '') $rows .= '<tr><td>Verified</td><td><span class="mono">' . h($verifiedAt) . '</span></td></tr>';

    $action = '';
    if (can_generate_delivery_otp_request($u, $r)) {
        $label = $hint !== '' ? 'Reset and send OTP' : 'Generate OTP';
        $action = '<form method="post" action="?action=generate_delivery_otp&id=' . (int)$r['id'] . '" class="stack" style="margin-top:10px">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<button class="btn btn-primary btnblock" type="submit">' . h($label) . '</button>'
            . '<div class="help">The full code is shown once and queued to the requester by enabled notification channels.</div>'
            . '</form>';
    }

    return '<div class="card"><div class="title">Delivery OTP</div>'
        . '<div class="sub" style="margin-top:8px">The requester shares this code with the runner at handoff. LiteGig stores only a hash and a two-digit hint.</div>'
        . '<table class="table" style="margin-top:10px">' . $rows . '</table>'
        . $action . '</div>';
}

function exception_reason_options(string $type): array {
    $options = [
        'cancel' => [
            'no_longer_needed' => t('exception.cancel.no_longer_needed', 'No longer needed'),
            'wrong_details' => t('exception.cancel.wrong_details', 'Wrong request details'),
            'timing_changed' => t('exception.cancel.timing_changed', 'Timing changed'),
            'other' => t('exception.cancel.other', 'Other'),
        ],
        'decline' => [
            'schedule_conflict' => t('exception.decline.schedule_conflict', 'Schedule conflict'),
            'too_far' => t('exception.decline.too_far', 'Too far'),
            'cannot_complete' => t('exception.decline.cannot_complete', 'Cannot complete'),
            'other' => t('exception.decline.other', 'Other'),
        ],
        'dispute' => [
            'item_issue' => t('exception.dispute.item_issue', 'Item issue'),
            'payment_issue' => t('exception.dispute.payment_issue', 'Payment issue'),
            'delivery_issue' => t('exception.dispute.delivery_issue', 'Delivery issue'),
            'safety_or_policy' => t('exception.dispute.safety_or_policy', 'Safety or policy concern'),
            'other' => t('exception.dispute.other', 'Other'),
        ],
        'reopen' => [
            'work_still_needed' => t('exception.reopen.work_still_needed', 'Work still needed'),
            'issue_resolved' => t('exception.reopen.issue_resolved', 'Issue resolved'),
            'opened_in_error' => t('exception.reopen.opened_in_error', 'Closed in error'),
            'other' => t('exception.reopen.other', 'Other'),
        ],
    ];
    return $options[$type] ?? ['other' => t('exception.cancel.other', 'Other')];
}

function render_reason_options(string $type): string {
    $html = '';
    foreach (exception_reason_options($type) as $value => $label) {
        $html .= '<option value="' . h($value) . '">' . h($label) . '</option>';
    }
    return $html;
}

function exception_note_from_post(string $type): string {
    $reason = input_string($_POST, 'reason', 80);
    $options = exception_reason_options($type);
    if (!array_key_exists($reason, $options)) $reason = 'other';
    $details = input_string($_POST, 'details', 800);
    $note = 'Reason: ' . $options[$reason];
    if ($details !== '') $note .= "\n" . $details;
    return $note;
}

function render_exception_form(int $requestId, string $action, string $type, string $title, string $button, string $buttonClass = 'btn'): string {
    return '<div class="action-card"><div class="itemtitle">' . h($title) . '</div>'
        . '<form method="post" action="?action=' . h($action) . '&id=' . $requestId . '" class="stack" style="margin-top:8px">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<div><label>Reason</label><select name="reason">' . render_reason_options($type) . '</select></div>'
        . '<div><label>Details</label><textarea name="details" placeholder="Short operational note"></textarea></div>'
        . '<button class="' . h($buttonClass) . ' btnblock" type="submit">' . h($button) . '</button>'
        . '</form></div>';
}

function is_image_attachment(string $name, string $mime = ''): bool {
    if ($mime !== '' && str_starts_with($mime, 'image/')) return true;
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

function render_attachment_preview(string $url, string $name, string $mime = ''): string {
    $label = $name !== '' ? $name : 'Attachment';
    $visual = is_image_attachment($name, $mime)
        ? '<img src="' . h($url) . '" alt="" loading="lazy" decoding="async" width="74" height="56">'
        : '<span class="avatar" aria-hidden="true">DOC</span>';
    return '<a class="attachment-preview" href="' . h($url) . '" target="_blank" rel="noopener">'
        . $visual . '<span>' . h($label) . '</span></a>';
}

function render_request_attachment_preview(int $requestId, string $field, string $storedName): string {
    $name = stored_upload_basename($storedName);
    if ($name === '') return '';
    $url = '?action=download_attachment&id=' . $requestId . '&field=' . rawurlencode($field) . '&inline=1';
    return render_attachment_preview($url, $name);
}

function render_event_attachment_preview(array $event): string {
    $storedName = stored_upload_basename((string)($event['attachment_name'] ?? ''));
    if ($storedName === '') return '';
    $label = (string)($event['attachment_original_name'] ?? '');
    if ($label === '') $label = $storedName;
    $url = '?action=download_event_attachment&id=' . (int)$event['id'];
    return render_attachment_preview($url, $label, (string)($event['attachment_mime'] ?? ''));
}

function request_list_query_parts(array $u, string $status, int $taskTypeId, string $query, bool $openPool = false, ?int $minFeeCents = null, ?int $maxFeeCents = null): array {
    $params = [];

    if ($openPool) {
        $visibilitySql = "r.status = 'new' AND r.runner_id IS NULL AND r.requester_id <> :pool_uid";
        $params[':pool_uid'] = (int)$u['id'];
    } elseif ((int)$u['is_admin'] === 1) {
        $visibilitySql = '1 = 1';
    } else {
        $visibilitySql = "(r.requester_id = :uid1 OR r.runner_id = :uid2)";
        $params[':uid1'] = (int)$u['id'];
        $params[':uid2'] = (int)$u['id'];
    }

    $statusSql = '1 = 1';
    if (!$openPool && $status !== 'all') {
        $statusSql = 'r.status = :status_value';
        $params[':status_value'] = $status;
    }

    $taskTypeSql = '1 = 1';
    if ($taskTypeId !== 0) {
        $taskTypeSql = 'r.task_type_id = :task_type_id';
        $params[':task_type_id'] = $taskTypeId;
    }

    $minFeeSql = '1 = 1';
    if ($minFeeCents !== null) {
        $minFeeSql = 'r.price_cents >= :min_fee_cents';
        $params[':min_fee_cents'] = $minFeeCents;
    }

    $maxFeeSql = '1 = 1';
    if ($maxFeeCents !== null) {
        $maxFeeSql = 'r.price_cents <= :max_fee_cents';
        $params[':max_fee_cents'] = $maxFeeCents;
    }

    $qSql = '1 = 1';
    if ($query !== '') {
        $qSql = "(r.title LIKE :q_title
            OR r.description LIKE :q_description
            OR r.metadata LIKE :q_metadata
            OR u1.display_name LIKE :q_requester
            OR COALESCE(u2.display_name, '') LIKE :q_runner)";
        $like = '%' . $query . '%';
        $params[':q_title'] = $like;
        $params[':q_description'] = $like;
        $params[':q_metadata'] = $like;
        $params[':q_requester'] = $like;
        $params[':q_runner'] = $like;
    }

    $joinSql = "FROM requests r
        JOIN task_types tt ON tt.id = r.task_type_id
        JOIN users u1 ON u1.id = r.requester_id
        LEFT JOIN users u2 ON u2.id = r.runner_id";
    $whereSql = "WHERE {$visibilitySql}
          AND {$statusSql}
          AND {$taskTypeSql}
          AND {$minFeeSql}
          AND {$maxFeeSql}
          AND {$qSql}";

    return [$joinSql, $whereSql, $params];
}

function count_requests_for_list(array $u, string $status, int $taskTypeId, string $query, bool $openPool = false, ?int $minFeeCents = null, ?int $maxFeeCents = null): int {
    $pdo = db();
    [$joinSql, $whereSql, $params] = request_list_query_parts($u, $status, $taskTypeId, $query, $openPool, $minFeeCents, $maxFeeCents);
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c {$joinSql} {$whereSql}");
    $stmt->execute($params);
    $row = $stmt->fetch();
    return (int)($row['c'] ?? 0);
}

function fetch_requests_for_list(array $u, string $status, int $taskTypeId, string $query, bool $openPool = false, ?int $minFeeCents = null, ?int $maxFeeCents = null, int $limit = 25, int $offset = 0): array {
    $pdo = db();
    [$joinSql, $whereSql, $params] = request_list_query_parts($u, $status, $taskTypeId, $query, $openPool, $minFeeCents, $maxFeeCents);
    $limit = max(1, min(1000, $limit));
    $offset = max(0, $offset);

    $sql = "SELECT r.*, tt.name AS task_type_name, tt.fields_json AS task_type_fields_json,
        u1.display_name AS requester_name, u2.display_name AS runner_name,
        (SELECT AVG(score) FROM ratings WHERE ratee_id = r.requester_id) AS requester_rating_avg,
        (SELECT COUNT(*) FROM ratings WHERE ratee_id = r.requester_id) AS requester_rating_count,
        (SELECT AVG(score) FROM ratings WHERE ratee_id = r.runner_id) AS runner_rating_avg,
        (SELECT COUNT(*) FROM ratings WHERE ratee_id = r.runner_id) AS runner_rating_count
        {$joinSql}
        {$whereSql}
        ORDER BY r.created_at DESC LIMIT {$limit} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function action_list_requests(): void {
    render_request_list_page(false);
}

function action_open_pool(): void {
    render_request_list_page(true);
}

function fetch_runner_jobs(array $u): array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT r.*, tt.name AS task_type_name, tt.fields_json AS task_type_fields_json,
        u1.display_name AS requester_name, u2.display_name AS runner_name,
        (SELECT AVG(score) FROM ratings WHERE ratee_id = r.requester_id) AS requester_rating_avg,
        (SELECT COUNT(*) FROM ratings WHERE ratee_id = r.requester_id) AS requester_rating_count,
        (SELECT AVG(score) FROM ratings WHERE ratee_id = r.runner_id) AS runner_rating_avg,
        (SELECT COUNT(*) FROM ratings WHERE ratee_id = r.runner_id) AS runner_rating_count
        FROM requests r
        JOIN task_types tt ON tt.id = r.task_type_id
        JOIN users u1 ON u1.id = r.requester_id
        LEFT JOIN users u2 ON u2.id = r.runner_id
        WHERE r.runner_id = ?
          AND r.status IN ('accepted','picked_up','payment_confirmed','delivered','disputed')
        ORDER BY COALESCE(r.sla_due_at, r.delivery_window_end, r.pickup_window_end, r.created_at) ASC
        LIMIT 100");
    $stmt->execute([(int)$u['id']]);
    return $stmt->fetchAll();
}

function render_runner_sheet_action(array $u, array $r): string {
    if (can_mark_picked_up_request($u, $r)) {
        return '<form method="post" action="?action=mark_picked_up&id=' . (int)$r['id'] . '" enctype="multipart/form-data" class="stack" data-offline-queue="runner" data-offline-label="Pickup for ' . h((string)$r['title']) . '">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . render_file_input('proof', 'Pickup proof', true)
            . '<button class="btn btn-primary btnblock" type="submit">Mark picked up</button>'
            . '</form>';
    }
    if (can_mark_delivered_request($u, $r)) {
        return '<form method="post" action="?action=mark_delivered&id=' . (int)$r['id'] . '" enctype="multipart/form-data" class="stack" data-offline-queue="runner" data-offline-label="Delivery for ' . h((string)$r['title']) . '">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . render_delivery_otp_input($r)
            . render_file_input('proof', 'Delivery proof', true)
            . '<button class="btn btn-primary btnblock" type="submit">Mark delivered</button>'
            . '</form>';
    }
    if ((string)$r['status'] === 'delivered') {
        return render_state_box('Awaiting requester', 'Delivery is marked. The requester needs to confirm closeout.', [], 'empty');
    }
    if ((string)$r['status'] === 'disputed') {
        return render_state_box('Disputed', 'This job is paused. Add notes and proof from the request detail page.', [
            ['label' => 'Open request', 'href' => '?action=get_request&id=' . (int)$r['id'], 'primary' => true],
        ], 'warn');
    }
    return '<a class="btn btnblock" href="?action=get_request&id=' . (int)$r['id'] . '">Open request</a>';
}

function action_runner_sheet(): void {
    $u = require_login();
    $rows = fetch_runner_jobs($u);
    $items = '';
    foreach ($rows as $r) {
        $tt = normalize_task_type_row([
            'id' => $r['task_type_id'],
            'name' => $r['task_type_name'],
            'fields_json' => $r['task_type_fields_json'],
            'created_at' => '',
        ]);
        $meta = json_decode((string)$r['metadata'], true);
        if (!is_array($meta)) $meta = [];
        $summaryBits = [];
        foreach (infer_summary_keys($tt) as $key) {
            $value = request_summary_value($tt, $meta, $key);
            if ($value !== '') $summaryBits[] = $value;
        }
        $due = request_due_status($r);
        $paymentLine = (string)$r['status'] === 'payment_confirmed'
            ? '<div class="itemmeta">Payment <span class="mono">confirmed</span></div>'
            : '<div class="itemmeta">Payment <span class="mono">pending requester confirmation</span></div>';
        $items .= '<div class="item">'
            . '<div class="itemtop"><div class="request-main">'
            . '<div class="itemtitle"><a href="?action=get_request&id=' . (int)$r['id'] . '">' . h((string)$r['title']) . '</a></div>'
            . '<div class="itemmeta">' . render_task_type_badge((string)$r['task_type_name']) . ' ' . render_status_chip((string)$r['status']) . ' ' . render_due_badge($r) . '</div>'
            . '<div class="user-strip">' . render_user_chip((int)$r['requester_id'], (string)$r['requester_name'], ((int)($r['requester_rating_count'] ?? 0) > 0 ? (float)$r['requester_rating_avg'] : null), (int)($r['requester_rating_count'] ?? 0), 'Requester') . '</div>'
            . ($summaryBits ? '<div class="itemmeta" style="margin-top:6px">' . h(implode(' · ', array_slice($summaryBits, 0, 3))) . '</div>' : '')
            . ($due['due_at'] !== '' ? '<div class="itemmeta" style="margin-top:6px">Due <span class="mono">' . h(format_app_datetime((string)$due['due_at'])) . '</span></div>' : '')
            . $paymentLine
            . '</div><div class="price-actions"><div class="price">' . h(format_cents((int)$r['price_cents'])) . '</div></div></div>'
            . '<div class="action-card" style="margin-top:10px">' . render_runner_sheet_action($u, $r) . '</div>'
            . '</div>';
    }

    $html = '<div class="card"><div class="row"><div><div class="title">Runner job sheet</div>'
        . '<div class="sub">Focused view for active assigned jobs: pickup, delivery, proof capture, payment state, and due timing.</div></div>'
        . '<div><a class="btn" href="?action=open_pool">Find work</a></div></div></div>'
        . '<div class="list">' . ($items ?: render_state_box('No active assigned jobs', 'Accepted runner jobs will appear here for mobile work.', [
            ['label' => 'Open Pool', 'href' => '?action=open_pool', 'primary' => true],
        ], 'empty')) . '</div>';

    render_layout('Runner Job Sheet', $html);
}

function action_payments(): void {
    $u = require_login();
    $pdo = db();
    $isAdmin = (int)$u['is_admin'] === 1;
    $where = $isAdmin ? '1=1' : '(r.requester_id = :uid1 OR r.runner_id = :uid2)';
    $stmt = $pdo->prepare("SELECT r.id, r.title, r.status, r.price_cents, r.fee_cents, r.requester_id, r.runner_id,
        p.receipt_no, p.status AS payment_status, p.amount_cents, p.fee_cents AS paid_fee_cents, p.confirmed_at
        FROM requests r
        LEFT JOIN payments p ON p.request_id = r.id
        WHERE {$where}
        ORDER BY COALESCE(p.confirmed_at, r.updated_at) DESC
        LIMIT 500");
    $stmt->execute($isAdmin ? [] : [':uid1' => (int)$u['id'], ':uid2' => (int)$u['id']]);
    $rows = $stmt->fetchAll();

    $settled = 0;
    $fees = 0;
    $outstanding = 0;
    $items = '';
    foreach ($rows as $row) {
        $paid = (string)($row['payment_status'] ?? '') === 'confirmed';
        if ($paid) {
            $settled += (int)$row['amount_cents'];
            $fees += (int)$row['paid_fee_cents'];
        } elseif (in_array((string)$row['status'], ['accepted', 'picked_up', 'payment_confirmed', 'delivered'], true)) {
            $outstanding += (int)$row['price_cents'];
        } else {
            continue;
        }
        $items .= '<div class="item"><div class="itemtop"><div class="request-main">'
            . '<div class="itemtitle"><a href="?action=get_request&id=' . (int)$row['id'] . '">' . h((string)$row['title']) . '</a></div>'
            . '<div class="itemmeta">' . render_status_chip((string)$row['status']) . ' ' . ($paid ? render_status_chip('confirmed') : '<span class="pill status-chip">' . h(t('status.outstanding', 'Outstanding')) . '</span>') . '</div>'
            . ($paid ? '<div class="itemmeta">Receipt <span class="mono">' . h((string)$row['receipt_no']) . '</span> · <span class="mono">' . h(format_app_datetime((string)$row['confirmed_at'])) . '</span></div>' : '<div class="itemmeta">Awaiting manual confirmation</div>')
            . '</div><div class="price-actions"><div class="price">' . h(format_cents((int)$row['price_cents'])) . '</div></div></div></div>';
    }

    $metrics = '<div class="profile-grid">'
        . '<div class="metric"><strong>' . h(format_cents($outstanding)) . '</strong><span>Outstanding</span></div>'
        . '<div class="metric"><strong>' . h(format_cents($settled)) . '</strong><span>Settled</span></div>'
        . '<div class="metric"><strong>' . h(format_cents($fees)) . '</strong><span>Fees recorded</span></div>'
        . '<div class="metric"><strong>' . count($rows) . '</strong><span>Visible requests</span></div>'
        . '</div>';
    $html = '<div class="card"><div class="title">Payment reconciliation</div>'
        . '<div class="sub" style="margin-top:8px">Manual receipt records for visible requests. Payments stay peer-to-peer; this view tracks what has been recorded.</div>'
        . $metrics . '</div>'
        . '<div class="list">' . ($items ?: render_state_box('No payment activity', 'Accepted, delivered, or manually confirmed requests will appear here.', [], 'empty')) . '</div>';
    render_layout('Payments', $html);
}

function action_save_view(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render_method_not_allowed();
    }
    require_csrf();

    $scope = saved_view_scope(input_string($_POST, 'scope', 20));
    $name = input_string($_POST, 'name', 80);
    if ($name === '') {
        $name = $scope === 'open_pool' ? 'Open Pool view' : 'Request view';
    }
    $query = clean_saved_view_params($_POST, $scope);
    $id = save_user_view((int)$u['id'], $scope, $name, $query);
    audit_log((int)$u['id'], 'save_view', 'saved_view', $id, ['scope' => $scope]);
    flash_set('ok', 'Saved view created.');
    redirect_to(filter_params_to_url($scope === 'open_pool' ? 'open_pool' : 'list_requests', $query, 1));
}

function action_delete_saved_view(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render_method_not_allowed();
    }
    require_csrf();

    $scope = saved_view_scope(input_string($_POST, 'scope', 20));
    $id = input_int($_POST, 'id', 0, 0, 1000000000);
    if ($id > 0 && delete_user_view((int)$u['id'], $id)) {
        audit_log((int)$u['id'], 'delete_view', 'saved_view', $id, ['scope' => $scope]);
        flash_set('ok', 'Saved view deleted.');
    }
    redirect_to('?action=' . ($scope === 'open_pool' ? 'open_pool' : 'list_requests'));
}

function list_filter_params(bool $openPool, string $status, int $taskTypeId, string $query, string $nearby, float $radiusKm, string $latValue, string $lngValue, string $minFeeRaw, string $maxFeeRaw, int $perPage): array {
    $params = [
        'q' => $query,
        'task_type_id' => $taskTypeId > 0 ? (string)$taskTypeId : '',
        'nearby' => $nearby,
        'km' => (string)$radiusKm,
        'lat' => $latValue,
        'lng' => $lngValue,
        'per_page' => (string)$perPage,
    ];
    if ($openPool) {
        $params['min_fee'] = $minFeeRaw;
        $params['max_fee'] = $maxFeeRaw;
    } else {
        $params['status'] = $status;
    }
    return $params;
}

function clean_saved_view_params(array $source, string $scope): array {
    $openPool = saved_view_scope($scope) === 'open_pool';
    $params = [
        'q' => input_string($source, 'q', 120),
        'task_type_id' => (string)input_int($source, 'task_type_id', 0, 0, 1000000000),
        'nearby' => input_string($source, 'nearby', 1) === '1' ? '1' : '',
        'km' => (string)input_float($source, 'km', 10.0, 1.0, 200.0),
        'lat' => isset($source['lat']) && is_numeric($source['lat']) ? (string)max(-90.0, min(90.0, (float)$source['lat'])) : '',
        'lng' => isset($source['lng']) && is_numeric($source['lng']) ? (string)max(-180.0, min(180.0, (float)$source['lng'])) : '',
        'per_page' => (string)input_int($source, 'per_page', 25, 5, 50),
    ];
    if ($openPool) {
        $params['min_fee'] = input_string($source, 'min_fee', 24);
        $params['max_fee'] = input_string($source, 'max_fee', 24);
    } else {
        $params['status'] = validate_status_filter(input_string($source, 'status', 30) ?: 'new');
    }
    return $params;
}

function filter_params_to_url(string $action, array $params, int $page = 1): string {
    $query = ['action' => $action];
    foreach ($params as $key => $value) {
        if ($value === '' || ($key === 'task_type_id' && $value === '0')) continue;
        $query[$key] = $value;
    }
    if ($page > 1) $query['page'] = (string)$page;
    return '?' . http_build_query($query);
}

function hidden_filter_inputs(array $params): string {
    $html = '';
    foreach ($params as $key => $value) {
        $html .= '<input type="hidden" name="' . h((string)$key) . '" value="' . h((string)$value) . '">';
    }
    return $html;
}

function render_saved_views_controls(array $views, string $pageAction, string $scope): string {
    if (!$views) {
        return '<div class="empty" style="margin-top:10px"><div class="empty-title">No saved views</div><div class="empty-body">Save this filter set to return to it quickly.</div></div>';
    }
    $items = '';
    foreach ($views as $view) {
        $params = json_decode((string)$view['query_json'], true);
        if (!is_array($params)) $params = [];
        $href = filter_params_to_url($pageAction, $params, 1);
        $items .= '<div class="item"><div class="itemtop"><div class="request-main">'
            . '<div class="itemtitle"><a href="' . h($href) . '">' . h((string)$view['name']) . '</a></div>'
            . '<div class="itemmeta">Saved <span class="mono">' . h(format_app_datetime((string)$view['created_at'])) . '</span></div>'
            . '</div><div class="item-actions">'
            . '<form method="post" action="?action=delete_saved_view">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<input type="hidden" name="id" value="' . (int)$view['id'] . '">'
            . '<input type="hidden" name="scope" value="' . h($scope) . '">'
            . '<button class="btn btnblock" type="submit">Delete</button>'
            . '</form></div></div></div>';
    }
    return '<div class="list" style="margin-top:10px">' . $items . '</div>';
}

function render_pagination_controls(string $pageAction, array $params, int $page, int $perPage, int $total): string {
    $totalPages = max(1, (int)ceil($total / max(1, $perPage)));
    $from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $to = min($total, $page * $perPage);
    $prev = $page > 1 ? '<a class="btn" href="' . h(filter_params_to_url($pageAction, $params, $page - 1)) . '">Previous</a>' : '<button class="btn" type="button" disabled>Previous</button>';
    $next = $page < $totalPages ? '<a class="btn" href="' . h(filter_params_to_url($pageAction, $params, $page + 1)) . '">Next</a>' : '<button class="btn" type="button" disabled>Next</button>';
    return '<div class="card"><div class="row"><div class="sub">Showing <span class="mono">' . h((string)$from) . '-' . h((string)$to) . '</span> of <span class="mono">' . h((string)$total) . '</span> · Page <span class="mono">' . h((string)$page) . '</span> of <span class="mono">' . h((string)$totalPages) . '</span></div><div class="state-actions" style="margin-top:0">' . $prev . $next . '</div></div></div>';
}

function render_request_list_page(bool $openPool): void {
    global $CFG;
    $u = current_user();
    if (!$u) {
        render_auth_gate();
        return;
    }

    $status = $openPool ? 'new' : validate_status_filter(input_string($_GET, 'status', 30) ?: 'new');
    $taskTypeId = input_int($_GET, 'task_type_id', 0, 0, 1000000000);
    $query = input_string($_GET, 'q', 120);
    $nearby = input_string($_GET, 'nearby', 1);
    $minFeeRaw = $openPool ? input_string($_GET, 'min_fee', 24) : '';
    $maxFeeRaw = $openPool ? input_string($_GET, 'max_fee', 24) : '';
    $minFeeCents = $openPool ? input_money_cents_optional($_GET, 'min_fee') : null;
    $maxFeeCents = $openPool ? input_money_cents_optional($_GET, 'max_fee') : null;
    if ($minFeeCents !== null && $maxFeeCents !== null && $minFeeCents > $maxFeeCents) {
        [$minFeeCents, $maxFeeCents] = [$maxFeeCents, $minFeeCents];
        [$minFeeRaw, $maxFeeRaw] = [$maxFeeRaw, $minFeeRaw];
    }
    $myLat = isset($_GET['lat']) && is_numeric($_GET['lat']) ? max(-90.0, min(90.0, (float)$_GET['lat'])) : null;
    $myLng = isset($_GET['lng']) && is_numeric($_GET['lng']) ? max(-180.0, min(180.0, (float)$_GET['lng'])) : null;
    $latValue = $myLat === null ? '' : (string)$myLat;
    $lngValue = $myLng === null ? '' : (string)$myLng;
    $radiusKm = input_float($_GET, 'km', 10.0, 1.0, 200.0);
    $needsLocation = $nearby === '1' && ($myLat === null || $myLng === null);
    $perPage = input_int($_GET, 'per_page', 25, 5, 50);
    $page = input_int($_GET, 'page', 1, 1, 1000000);
    $offset = ($page - 1) * $perPage;

    $types = get_task_types();
    $typeOptions = '<option value="0">All task types</option>';
    foreach ($types as $t) {
        $sel = ($taskTypeId === (int)$t['id']) ? ' selected' : '';
        $typeOptions .= '<option value="' . (int)$t['id'] . '"' . $sel . '>' . h($t['name']) . '</option>';
    }

    if ($needsLocation) {
        $rows = [];
        $totalRows = 0;
    } elseif ($nearby === '1' && $myLat !== null && $myLng !== null) {
        $allRows = fetch_requests_for_list($u, $status, $taskTypeId, $query, $openPool, $minFeeCents, $maxFeeCents, 1000, 0);
        $filteredRows = [];
        foreach ($allRows as $candidate) {
            $tt = normalize_task_type_row([
                'id' => $candidate['task_type_id'],
                'name' => $candidate['task_type_name'],
                'fields_json' => $candidate['task_type_fields_json'],
                'created_at' => '',
            ]);
            $meta = json_decode((string)$candidate['metadata'], true);
            if (!is_array($meta)) $meta = [];
            $geo = request_first_geo($tt, $meta);
            if (!$geo) continue;
            $d = haversine_km($myLat, $myLng, (float)$geo['lat'], (float)$geo['lng']);
            if ($d > $radiusKm) continue;
            $candidate['_distance_km'] = $d;
            $filteredRows[] = $candidate;
        }
        usort($filteredRows, fn(array $a, array $b): int => (float)$a['_distance_km'] <=> (float)$b['_distance_km']);
        $totalRows = count($filteredRows);
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }
        $rows = array_slice($filteredRows, $offset, $perPage);
    } else {
        $totalRows = count_requests_for_list($u, $status, $taskTypeId, $query, $openPool, $minFeeCents, $maxFeeCents);
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }
        $rows = fetch_requests_for_list($u, $status, $taskTypeId, $query, $openPool, $minFeeCents, $maxFeeCents, $perPage, $offset);
    }
    $items = '';

    foreach ($rows as $r) {
        $tt = normalize_task_type_row([
            'id' => $r['task_type_id'],
            'name' => $r['task_type_name'],
            'fields_json' => $r['task_type_fields_json'],
            'created_at' => '',
        ]);
        $meta = json_decode((string)$r['metadata'], true);
        if (!is_array($meta)) $meta = [];
        $distanceKm = isset($r['_distance_km']) ? (float)$r['_distance_km'] : null;

        $summaryKeys = infer_summary_keys($tt);
        $summaryBits = [];
        foreach ($summaryKeys as $k) {
            $v = request_summary_value($tt, $meta, $k);
            if ($v !== '') $summaryBits[] = $v;
        }
        $summaryLine = $summaryBits ? implode(' · ', array_slice($summaryBits, 0, 3)) : '';

        $price = format_cents((int)$r['price_cents']);
        $requesterRatingCount = (int)($r['requester_rating_count'] ?? 0);
        $requesterRatingAvg = $requesterRatingCount > 0 ? (float)$r['requester_rating_avg'] : null;
        $runnerRatingCount = (int)($r['runner_rating_count'] ?? 0);
        $runnerRatingAvg = $runnerRatingCount > 0 ? (float)$r['runner_rating_avg'] : null;
        $people = '<div class="user-strip">'
            . render_user_chip((int)$r['requester_id'], (string)$r['requester_name'], $requesterRatingAvg, $requesterRatingCount, 'Requester')
            . ($r['runner_id'] !== null ? render_user_chip((int)$r['runner_id'], (string)$r['runner_name'], $runnerRatingAvg, $runnerRatingCount, 'Runner') : '')
            . '</div>';
        $distanceLine = $distanceKm !== null
            ? '<div class="itemmeta" style="margin-top:6px">Distance <span class="mono">' . h(number_format($distanceKm, 1)) . ' km</span></div>'
            : '';
        $due = request_due_status($r);
        $scheduleLine = $due['due_at'] !== ''
            ? '<div class="itemmeta" style="margin-top:6px">' . render_due_badge($r) . ' Due <span class="mono">' . h(format_app_datetime((string)$due['due_at'])) . '</span></div>'
            : '';
        $actions = '<a class="btn" href="?action=get_request&id=' . (int)$r['id'] . '">View</a>';
        if ($openPool) {
            $actions = '<form method="post" action="?action=accept_request&id=' . (int)$r['id'] . '">'
                . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
                . '<button class="btn btn-primary btnblock" type="submit">Accept</button>'
                . '</form>' . $actions;
        }
        $items .= '<div class="item">'
            . '<div class="itemtop">'
            . '<div class="request-main">'
            . '<div class="itemtitle"><a href="?action=get_request&id=' . (int)$r['id'] . '">' . h((string)$r['title']) . '</a></div>'
            . '<div class="itemmeta">' . render_task_type_badge((string)$r['task_type_name']) . ' ' . render_status_chip((string)$r['status']) . ' ' . render_due_badge($r) . '</div>'
            . $people
            . ($summaryLine ? '<div class="itemmeta" style="margin-top:6px">' . h($summaryLine) . '</div>' : '')
            . $distanceLine
            . $scheduleLine
            . '<div class="itemmeta" style="margin-top:6px">Created <span class="mono">' . h(format_app_datetime((string)$r['created_at'])) . '</span></div>'
            . '</div>'
            . '<div class="stack price-actions">'
            . '<div class="price">' . h($price) . '</div>'
            . $actions
            . '</div>'
            . '</div>'
            . '</div>';
    }

    $statusOptions = status_options();
    $statusSel = '';
    foreach ($statusOptions as $k => $label) {
        $sel = ($status === $k) ? ' selected' : '';
        $statusSel .= '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
    }
    $hasPoolFilters = $query !== '' || $taskTypeId !== 0 || $nearby === '1' || $minFeeCents !== null || $maxFeeCents !== null;
    if ($needsLocation) {
        $emptyTitle = 'Location needed for nearby results';
        $emptyBody = 'Allow location access, or turn Nearby off and filter again.';
    } elseif ($openPool && $nearby === '1') {
        $emptyTitle = 'No nearby open jobs found';
        $emptyBody = 'No unassigned requests matched your filters within ' . number_format($radiusKm, 1) . ' km.';
    } elseif ($nearby === '1') {
        $emptyTitle = 'No nearby requests found';
        $emptyBody = 'No visible requests matched your filters within ' . number_format($radiusKm, 1) . ' km.';
    } elseif ($openPool && $hasPoolFilters) {
        $emptyTitle = 'No open jobs match';
        $emptyBody = 'Adjust task type, fee, distance, or search filters to widen the pool.';
    } elseif ($openPool) {
        $emptyTitle = 'No open jobs right now';
        $emptyBody = 'Unassigned requests from other users will appear here when they are ready to accept.';
    } elseif ($query !== '' || $taskTypeId !== 0 || $status !== 'new') {
        $emptyTitle = 'No matching requests';
        $emptyBody = 'Adjust the filters or clear search to see more requests.';
    } else {
        $emptyTitle = 'No open requests yet';
        $emptyBody = 'Create the first request to start the queue.';
    }

    $pageAction = $openPool ? 'open_pool' : 'list_requests';
    $pageTitle = $openPool ? 'Open Pool' : 'Requests';
    $pageSubtitle = $openPool
        ? 'Browse unassigned jobs from other requesters by task type, nearby distance, and runner fee.'
        : 'Search and filter the operational queue by status, task type, nearby distance, or request details.';
    $topAction = $openPool
        ? '<a class="btn" href="?action=list_requests">My queue</a>'
        : '<a class="btn btn-primary" href="?action=create_request">Create</a>';
    $statusField = $openPool ? '' : '<div><label>Status</label><select name="status">' . $statusSel . '</select></div>';
    $feeFields = $openPool
        ? '<div><label>Min fee</label><input name="min_fee" inputmode="decimal" value="' . h($minFeeRaw) . '" placeholder="0.00"></div>'
            . '<div><label>Max fee</label><input name="max_fee" inputmode="decimal" value="' . h($maxFeeRaw) . '" placeholder="Any"></div>'
        : '';
    $searchPlaceholder = $openPool ? 'Title, place, note, requester' : 'Title, place, note, requester';
    $filterLabel = $openPool ? 'Find work' : 'Filter';
    $emptyActions = $openPool
        ? [
            ['label' => 'My queue', 'href' => '?action=list_requests', 'primary' => true],
            ['label' => 'Create request', 'href' => '?action=create_request'],
        ]
        : [
            ['label' => 'Create request', 'href' => '?action=create_request', 'primary' => true],
        ];
    $scope = $openPool ? 'open_pool' : 'requests';
    $filterParams = list_filter_params($openPool, $status, $taskTypeId, $query, $nearby, $radiusKm, $latValue, $lngValue, $minFeeRaw, $maxFeeRaw, $perPage);
    $savedViews = saved_views_for_user((int)$u['id'], $scope);
    $savedControls = render_saved_views_controls($savedViews, $pageAction, $scope);
    $saveViewForm = '<form method="post" action="?action=save_view" class="grid" style="margin-top:12px">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<input type="hidden" name="scope" value="' . h($scope) . '">'
        . hidden_filter_inputs($filterParams)
        . '<div><label>Saved view name</label><input name="name" maxlength="80" placeholder="' . h($openPool ? 'Nearby errands over $20' : 'My active deliveries') . '"></div>'
        . '<button class="btn btnblock" type="submit">Save view</button>'
        . '</form>';
    $pagination = render_pagination_controls($pageAction, $filterParams, $page, $perPage, $totalRows);

    $html = '<div class="card"><div class="row"><div>'
        . '<div class="title">' . h($pageTitle) . '</div>'
        . '<div class="sub">' . h($pageSubtitle) . '</div>'
        . '</div><div>' . $topAction . '</div></div>'
        . '<form method="get" action="" class="grid" style="margin-top:12px">'
        . '<input type="hidden" name="action" value="' . h($pageAction) . '">'
        . '<div><label>Search</label><input name="q" value="' . h($query) . '" placeholder="' . h($searchPlaceholder) . '"></div>'
        . $statusField
        . '<div><label>Task type</label><select name="task_type_id">' . $typeOptions . '</select></div>'
        . '<div><label>Nearby</label>'
        . '<select name="nearby" id="nearby"><option value="">Off</option><option value="1"' . ($nearby === '1' ? ' selected' : '') . '>Within distance</option></select>'
        . '<div class="help">Uses the first geo field in the schema (if present). No tracking.</div>'
        . '<div id="location_state" class="inline-state" role="status"></div></div>'
        . '<div><label>Distance (km)</label><input name="km" inputmode="decimal" value="' . h((string)$radiusKm) . '"></div>'
        . $feeFields
        . '<div><label>Per page</label><select name="per_page">'
        . '<option value="10"' . ($perPage === 10 ? ' selected' : '') . '>10</option>'
        . '<option value="25"' . ($perPage === 25 ? ' selected' : '') . '>25</option>'
        . '<option value="50"' . ($perPage === 50 ? ' selected' : '') . '>50</option>'
        . '</select></div>'
        . '<input type="hidden" name="lat" id="lat" value="' . h($latValue) . '">'
        . '<input type="hidden" name="lng" id="lng" value="' . h($lngValue) . '">'
        . '<button class="btn btnblock" type="submit">' . h($filterLabel) . '</button>'
        . '</form>'
        . '<div style="margin-top:10px"><label class="checkline"><input id="autorefresh" type="checkbox"> Auto-refresh (15s)</label></div>'
        . '<div style="margin-top:12px"><div class="title">Saved views</div>' . $savedControls . $saveViewForm . '</div>'
        . '</div>'
        . '<div class="list">' . ($items ?: render_state_box($emptyTitle, $emptyBody, $emptyActions, $needsLocation ? 'warn' : 'empty')) . '</div>'
        . $pagination;

    $html .= '<script>'
        . 'const POLL_MS=' . (int)$CFG['poll_ms'] . ';'
        . 'const ar=document.getElementById("autorefresh"); ar.checked=(localStorage.getItem("lg_autorefresh")==="1");'
        . 'ar.addEventListener("change",()=>{localStorage.setItem("lg_autorefresh", ar.checked?"1":"0");});'
        . 'if(ar.checked){setInterval(()=>{location.reload();}, POLL_MS);}'
        . 'const nearby=document.getElementById("nearby");'
        . 'const locState=document.getElementById("location_state"); const listForm=nearby.closest("form");'
        . 'function setLocState(text,tone){if(!locState)return; locState.textContent=text||""; locState.className="inline-state"+(text?" is-visible":"")+(tone?" is-"+tone:"");}'
        . 'function ensureLoc(){if(nearby.value!=="1"){setLocState("", ""); return;} const lat=document.getElementById("lat"); const lng=document.getElementById("lng"); if(lat.value && lng.value){setLocState("Location ready for nearby filtering.", "ok"); return;}'
        . 'if(!navigator.geolocation){setLocState("Location is not available in this browser. Turn Nearby off to keep filtering.", "error"); return;} setLocState("Finding your location...", ""); navigator.geolocation.getCurrentPosition((pos)=>{lat.value=String(pos.coords.latitude);lng.value=String(pos.coords.longitude);setLocState("Location ready. Refreshing nearby results...", "ok"); if(listForm.requestSubmit){listForm.requestSubmit();}else{listForm.submit();}},(err)=>{setLocState("Could not use location: "+err.message+". Turn Nearby off to keep filtering.", "error");}, {enableHighAccuracy:true,timeout:7000,maximumAge:20000});}'
        . 'nearby.addEventListener("change",ensureLoc); ensureLoc();'
        . '</script>';

    render_layout('Requests', $html);
}

function fetch_request_full(int $id): ?array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT r.*, tt.name AS task_type_name, tt.fields_json AS task_type_fields_json,
        u1.display_name AS requester_name, u1.email AS requester_email,
        u2.display_name AS runner_name, u2.email AS runner_email,
        (SELECT AVG(score) FROM ratings WHERE ratee_id = r.requester_id) AS requester_rating_avg,
        (SELECT COUNT(*) FROM ratings WHERE ratee_id = r.requester_id) AS requester_rating_count,
        (SELECT AVG(score) FROM ratings WHERE ratee_id = r.runner_id) AS runner_rating_avg,
        (SELECT COUNT(*) FROM ratings WHERE ratee_id = r.runner_id) AS runner_rating_count
        FROM requests r
        JOIN task_types tt ON tt.id = r.task_type_id
        JOIN users u1 ON u1.id = r.requester_id
        LEFT JOIN users u2 ON u2.id = r.runner_id
        WHERE r.id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function fetch_request_by_code(string $code): ?array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT r.*, tt.name AS task_type_name, tt.fields_json AS task_type_fields_json,
        u1.display_name AS requester_name, u1.email AS requester_email,
        u2.display_name AS runner_name, u2.email AS runner_email,
        (SELECT AVG(score) FROM ratings WHERE ratee_id = r.requester_id) AS requester_rating_avg,
        (SELECT COUNT(*) FROM ratings WHERE ratee_id = r.requester_id) AS requester_rating_count,
        (SELECT AVG(score) FROM ratings WHERE ratee_id = r.runner_id) AS runner_rating_avg,
        (SELECT COUNT(*) FROM ratings WHERE ratee_id = r.runner_id) AS runner_rating_count
        FROM requests r
        JOIN task_types tt ON tt.id = r.task_type_id
        JOIN users u1 ON u1.id = r.requester_id
        LEFT JOIN users u2 ON u2.id = r.runner_id
        WHERE upper(r.code) = upper(?)");
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function public_event_actor_label(array $event, array $r): string {
    $actorId = $event['actor_id'] === null ? 0 : (int)$event['actor_id'];
    if ($actorId === 0) return 'System';
    if ($actorId === (int)$r['requester_id']) return 'Requester';
    if ($r['runner_id'] !== null && $actorId === (int)$r['runner_id']) return 'Runner';
    return 'Operator';
}

function render_public_tracking_timeline(array $r): string {
    $allowed = ['created', 'accepted', 'picked_up', 'payment_confirmed', 'delivered', 'delivery_confirmed', 'completed', 'cancelled', 'declined', 'disputed', 'reopened', 'expired'];
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM events WHERE request_id = ? ORDER BY id ASC");
    $stmt->execute([(int)$r['id']]);
    $items = '';
    foreach ($stmt->fetchAll() as $event) {
        $type = (string)$event['type'];
        if (!in_array($type, $allowed, true)) continue;
        $items .= '<div class="item"><div class="itemtop"><div class="request-main">'
            . '<div class="itemtitle">' . h(request_event_label($type)) . '</div>'
            . '<div class="itemmeta">' . h(public_event_actor_label($event, $r)) . ' · <span class="mono">' . h(format_app_datetime((string)$event['created_at'])) . '</span></div>'
            . '</div></div></div>';
    }
    return '<div class="card"><div class="title">Public timeline</div>'
        . '<div class="sub">Redacted lifecycle events only. Private comments, names, attachments, and task details stay inside LiteGig.</div>'
        . '<div class="list" style="margin-top:10px">' . ($items ?: '<div class="empty"><div class="empty-title">No public events yet</div><div class="empty-body">Status updates will appear here as the request moves forward.</div></div>') . '</div></div>';
}

function action_track_request(): void {
    $code = strtoupper(input_string($_GET, 'code', 40));
    if (!preg_match('/^LG-[A-F0-9]{8,12}$/', $code)) {
        render_not_found('Tracking link not found', 'Check the tracking code and try again.');
    }
    $r = fetch_request_by_code($code);
    if (!$r) {
        render_not_found('Tracking link not found', 'That public tracking code is not available.');
    }

    $summary = public_tracking_summary($r);
    $steps = request_status_steps();
    $status = (string)$summary['status'];
    $path = '';
    foreach ($steps as $key => $label) {
        $idx = array_search($key, array_keys($steps), true);
        $classes = ['statepoint'];
        if ($key === $status) $classes[] = 'current';
        $path .= '<div class="' . h(implode(' ', $classes)) . '"><small>' . h(str_pad((string)($idx + 1), 2, '0', STR_PAD_LEFT)) . '</small><span>' . h($label) . '</span></div>';
    }
    if (in_array($status, ['expired', 'cancelled', 'disputed'], true)) {
        $path .= '<div class="statepoint current"><small>' . h(ucwords($status)) . '</small><span>Exception</span></div>';
    }

    $html = '<div class="card"><div class="row"><div class="request-main">'
        . '<div class="title">Tracking ' . h((string)$summary['code']) . '</div>'
        . '<div class="sub">' . h((string)$summary['title']) . ' · ' . render_task_type_badge((string)$summary['task_type_name']) . '</div>'
        . '</div><div class="status-block">' . render_status_chip($status) . '</div></div>'
        . '<div class="statepath" aria-label="Public request status path">' . $path . '</div>'
        . '<div class="sub" style="margin-top:10px">Created <span class="mono">' . h(format_app_datetime((string)$summary['created_at'])) . '</span> · Updated <span class="mono">' . h(format_app_datetime((string)$summary['updated_at'])) . '</span></div>'
        . '</div>'
        . render_public_tracking_timeline($r);

    render_layout('Tracking ' . (string)$summary['code'], $html);
}

function action_get_request(): void {
    global $CFG;
    $u = require_login();
    $id = input_int($_GET, 'id', 0, 0, 1000000000);
    $r = fetch_request_full($id);
    if (!$r) {
        render_not_found('Request not found', 'This request may have been deleted, expired, or the link is stale.');
    }
    require_request_view($u, $r);
    $tt = normalize_task_type_row([
        'id' => $r['task_type_id'],
        'name' => $r['task_type_name'],
        'fields_json' => $r['task_type_fields_json'],
        'created_at' => '',
    ]);
    $meta = json_decode((string)$r['metadata'], true);
    if (!is_array($meta)) $meta = [];
    $labels = field_label_map($tt);

    $summaryKeys = infer_summary_keys($tt);
    $summaryBits = [];
    foreach ($summaryKeys as $k) {
        $v = request_summary_value($tt, $meta, $k);
        if ($v !== '') $summaryBits[] = $v;
    }

    $price = format_cents((int)$r['price_cents']);
    $fee = format_cents((int)$r['fee_cents']);
    $requesterRatingCount = (int)($r['requester_rating_count'] ?? 0);
    $requesterRatingAvg = $requesterRatingCount > 0 ? (float)$r['requester_rating_avg'] : null;
    $runnerRatingCount = (int)($r['runner_rating_count'] ?? 0);
    $runnerRatingAvg = $runnerRatingCount > 0 ? (float)$r['runner_rating_avg'] : null;
    $people = '<div class="user-strip">'
        . render_user_chip((int)$r['requester_id'], (string)$r['requester_name'], $requesterRatingAvg, $requesterRatingCount, 'Requester')
        . ($r['runner_id'] !== null ? render_user_chip((int)$r['runner_id'], (string)$r['runner_name'], $runnerRatingAvg, $runnerRatingCount, 'Runner') : '')
        . '</div>';
    $trackingCode = (string)($r['code'] ?? '');
    $tracking = $trackingCode !== ''
        ? '<div class="sub" style="margin-top:10px">Tracking code <span class="mono">' . h($trackingCode) . '</span> · <a href="?action=track&code=' . h(rawurlencode($trackingCode)) . '">Public tracking</a></div>'
        : '';

    $card = '<div class="card">'
        . '<div class="row"><div class="request-main">'
        . '<div class="title">' . h((string)$r['title']) . '</div>'
        . '<div class="sub">' . render_task_type_badge((string)$r['task_type_name']) . ' ' . render_status_chip((string)$r['status']) . '</div>'
        . '</div><div class="detail-price">'
        . '<div class="price">' . h($price) . '</div>'
        . '<div class="sub">Platform fee: <span class="mono">' . h($fee) . '</span></div>'
        . '</div></div>'
        . ($summaryBits ? '<div class="sub" style="margin-top:10px">' . h(implode(' · ', $summaryBits)) . '</div>' : '')
        . $people
        . $tracking
        . '</div>';

    $desc = '<div class="card"><div class="title">Description</div><div class="longtext" style="margin-top:10px">' . h((string)$r['description']) . '</div></div>';

    $metaRows = '';
    foreach ($tt['fields'] as $f) {
        if (!is_array($f)) continue;
        $k = (string)($f['key'] ?? '');
        if ($k === '') continue;
        $label = (string)($f['label'] ?? ($labels[$k] ?? $k));
        $type = (string)($f['type'] ?? 'text');
        $v = $meta[$k] ?? null;
        $render = '';
        if ($type === 'geo') {
            if (is_array($v)) {
                $addr = (string)($v['address'] ?? '');
                $lat = $v['lat'] ?? null;
                $lng = $v['lng'] ?? null;
                $render = h($addr);
                if ($lat !== null && $lng !== null) $render .= '<div class="help mono">' . h((string)$lat) . ', ' . h((string)$lng) . '</div>';
            }
        } elseif ($type === 'price') {
            $render = is_int($v) ? '<span class="mono">' . h(format_cents($v)) . '</span>' : '';
        } elseif ($type === 'boolean') {
            $render = ((int)$v === 1) ? 'Yes' : 'No';
        } elseif ($type === 'attachment') {
            if (is_string($v) && $v !== '') {
                $fn = stored_upload_basename($v);
                $url = '?action=download_attachment&id=' . (int)$r['id'] . '&field=' . rawurlencode($k);
                if ($fn !== '') {
                    $render = render_request_attachment_preview((int)$r['id'], $k, $fn)
                        . '<div class="help"><a href="' . h($url) . '">Download original</a></div>';
                }
            }
        } elseif ($type === 'select') {
            $render = h(request_summary_value($tt, $meta, $k));
        } else {
            $render = h(is_string($v) ? $v : (is_numeric($v) ? (string)$v : (is_null($v) ? '' : json_encode($v, JSON_UNESCAPED_SLASHES))));
            $render = '<div class="longtext">' . $render . '</div>';
        }
        $metaRows .= '<tr><td>' . h($label) . '</td><td>' . ($render ?: '<span class="sub">—</span>') . '</td></tr>';
    }
    $metaBody = $metaRows
        ? '<table class="table" style="margin-top:10px">' . $metaRows . '</table>'
        : '<div style="margin-top:10px">' . render_state_box('No detail fields', 'This task type does not define any additional request fields.', [], 'empty') . '</div>';
    $metaTable = '<div class="card"><div class="title">Details</div>' . $metaBody . '</div>';

    $statePanel = render_request_state_panel($u, $r);
    $actions = render_request_actions($u, $r);
    $thread = render_request_thread($id);
    $schedulePanel = render_request_schedule_panel($r);
    $paymentPanel = render_request_payment_panel($r);
    $otpPanel = render_delivery_otp_panel($u, $r);
    $eventLog = render_request_events($id);
    $rating = render_request_rating_block($u, $r);

    render_layout('Request', $card . $statePanel . $schedulePanel . $paymentPanel . $otpPanel . $actions . $thread . $desc . $metaTable . $eventLog . $rating);
}

function action_download_attachment(): void {
    global $CFG;
    $u = require_login();
    $id = input_int($_GET, 'id', 0, 0, 1000000000);
    $field = input_string($_GET, 'field', 64);
    $inline = input_string($_GET, 'inline', 1) === '1';
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $field)) {
        render_bad_request('Attachment link is invalid', 'Use the attachment link from the request details page.');
    }

    $r = fetch_request_full($id);
    if (!$r) {
        render_not_found('Attachment not found', 'The request for this attachment is not available.');
    }
    require_request_view($u, $r);

    $download = request_attachment_download($r, $field);
    if (!$download) {
        render_not_found('Attachment not found', 'The attachment file is unavailable. Ask the requester to upload it again.');
    }

    header('Content-Type: ' . (string)$download['mime']);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addcslashes((string)$download['download_name'], "\"\\") . '"');
    header('X-Content-Type-Options: nosniff');
    readfile((string)$download['path']);
    exit;
}

function action_download_event_attachment(): void {
    global $CFG;
    $u = require_login();
    $eventId = input_int($_GET, 'id', 0, 0, 1000000000);
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    if (!$event) {
        render_not_found('Attachment not found', 'That event attachment is not available.');
    }

    $r = fetch_request_full((int)$event['request_id']);
    if (!$r) {
        render_not_found('Attachment not found', 'The request for this attachment is not available.');
    }
    require_request_view($u, $r);

    $download = event_attachment_download($event);
    if (!$download) {
        render_not_found('Attachment not found', 'The event attachment file is unavailable.');
    }

    header('Content-Type: ' . (string)$download['mime']);
    header('Content-Disposition: inline; filename="' . addcslashes((string)$download['download_name'], "\"\\") . '"');
    header('X-Content-Type-Options: nosniff');
    readfile((string)$download['path']);
    exit;
}

function request_status_steps(): array {
    return [
        'new' => t('request.step.new', 'Posted'),
        'accepted' => t('request.step.accepted', 'Accepted'),
        'picked_up' => t('request.step.picked_up', 'Picked up'),
        'payment_confirmed' => t('request.step.payment_confirmed', 'Paid'),
        'delivered' => t('request.step.delivered', 'Delivered'),
        'completed' => t('request.step.completed', 'Complete'),
    ];
}

function request_viewer_role_label(array $u, array $r): string {
    $uid = (int)$u['id'];
    $isRequester = $uid === (int)$r['requester_id'];
    $isRunner = $r['runner_id'] !== null && $uid === (int)$r['runner_id'];
    if ($isRequester) return t('role.requester', 'Requester');
    if ($isRunner) return t('role.runner', 'Runner');
    if ((int)$u['is_admin'] === 1) return t('role.admin', 'Admin');
    return t('role.open_pool', 'Open pool');
}

function request_next_action_context(array $u, array $r): array {
    $uid = (int)$u['id'];
    $isRequester = $uid === (int)$r['requester_id'];
    $isRunner = $r['runner_id'] !== null && $uid === (int)$r['runner_id'];
    $status = (string)$r['status'];
    $role = request_viewer_role_label($u, $r);

    if ($status === 'new') {
        if ($isRequester) return ['role' => $role, 'title' => 'Waiting for acceptance', 'body' => 'The request is live in the Open Pool. Use comments for clarifications before a runner accepts.'];
        return ['role' => $role, 'title' => 'Accept to take custody', 'body' => 'Review the details, requester reputation, fee, and any attachments before accepting this job.'];
    }
    if ($status === 'accepted') {
        if ($isRunner) return ['role' => $role, 'title' => 'Pick up next', 'body' => 'Mark picked up when you have custody. Add optional proof if the handoff needs a record.'];
        if ($isRequester) return ['role' => $role, 'title' => 'Confirm payment when ready', 'body' => 'Payment stays peer-to-peer. Record confirmation here after both sides agree it is settled.'];
        return ['role' => $role, 'title' => 'Runner accepted', 'body' => 'The assigned runner owns the pickup step. Admins can monitor the event log and comments.'];
    }
    if ($status === 'picked_up') {
        if ($isRunner) return ['role' => $role, 'title' => 'Deliver next', 'body' => 'The item is in runner custody. Mark delivered after dropoff and attach proof when useful.'];
        if ($isRequester) return ['role' => $role, 'title' => 'Payment checkpoint', 'body' => 'Confirm manual payment if it is complete, then watch for runner delivery.'];
        return ['role' => $role, 'title' => 'Runner has custody', 'body' => 'Watch delivery progress and the comment thread.'];
    }
    if ($status === 'payment_confirmed') {
        if ($isRunner) return ['role' => $role, 'title' => 'Deliver after payment', 'body' => 'Payment is recorded. Complete dropoff and mark delivered.'];
        return ['role' => $role, 'title' => 'Waiting for delivery', 'body' => 'Payment is recorded. The runner owns the delivery step.'];
    }
    if ($status === 'delivered') {
        if ($isRequester) return ['role' => $role, 'title' => 'Confirm delivery', 'body' => 'Review proof and comments, then confirm delivery to close the operational loop.'];
        if ($isRunner) return ['role' => $role, 'title' => 'Awaiting requester closeout', 'body' => 'The requester now confirms delivery. You can add final notes or proof in the thread.'];
        return ['role' => $role, 'title' => 'Awaiting closeout', 'body' => 'The requester owns the final delivery confirmation.'];
    }
    if ($status === 'completed') {
        return ['role' => $role, 'title' => 'Closed', 'body' => 'The request is complete. Ratings, comments, proof, and events remain available for review and export.'];
    }
    if ($status === 'cancelled') {
        return ['role' => $role, 'title' => 'Cancelled', 'body' => 'The request is cancelled. Requesters or admins can reopen it if the work should return to the pool.'];
    }
    if ($status === 'disputed') {
        return ['role' => $role, 'title' => 'Disputed', 'body' => 'The request is paused for review. Use comments and proof attachments to document the issue.'];
    }
    if ($status === 'expired') {
        return ['role' => $role, 'title' => 'Expired', 'body' => 'This request aged out before acceptance. Create a fresh request if the work is still needed.'];
    }
    return ['role' => $role, 'title' => 'Review activity', 'body' => 'Check the event log and comments for the latest update.'];
}

function request_next_action_hint(array $u, array $r): string {
    $ctx = request_next_action_context($u, $r);
    return (string)$ctx['title'] . ': ' . (string)$ctx['body'];
}

function render_request_state_panel(array $u, array $r): string {
    $status = (string)$r['status'];
    $steps = request_status_steps();
    $isExpired = $status === 'expired';
    $doneByStatus = [
        'new' => [],
        'accepted' => ['new'],
        'picked_up' => ['new', 'accepted'],
        'payment_confirmed' => ['new', 'accepted'],
        'delivered' => ['new', 'accepted', 'picked_up', 'payment_confirmed'],
        'completed' => ['new', 'accepted', 'picked_up', 'payment_confirmed', 'delivered'],
    ];
    $doneKeys = $doneByStatus[$status] ?? [];

    $path = '';
    foreach ($steps as $key => $label) {
        $idx = array_search($key, array_keys($steps), true);
        $classes = ['statepoint'];
        if (!$isExpired && in_array($key, $doneKeys, true)) $classes[] = 'done';
        if (!$isExpired && $key === $status) $classes[] = 'current';
        $path .= '<div class="' . h(implode(' ', $classes)) . '"><small>' . h(str_pad((string)($idx + 1), 2, '0', STR_PAD_LEFT)) . '</small><span>' . h($label) . '</span></div>';
    }
    if (in_array($status, ['expired', 'cancelled', 'disputed'], true)) {
        $path .= '<div class="statepoint current"><small>' . h(t('status.' . $status, ucwords($status))) . '</small><span>' . h(t('request.step.exception', 'Exception')) . '</span></div>';
    }

    $requester = (string)($r['requester_name'] ?? 'Requester');
    $runner = (string)($r['runner_name'] ?? '');
    $holder = 'Open queue';
    if (in_array($status, ['accepted', 'picked_up', 'payment_confirmed', 'delivered'], true)) {
        $holder = $runner !== '' ? $runner : 'Assigned runner';
    } elseif ($status === 'completed') {
        $holder = 'Requester and runner history';
    } elseif (in_array($status, ['expired', 'cancelled', 'disputed'], true)) {
        $holder = 'No active holder';
    }

    $next = request_next_action_context($u, $r);

    return '<div class="card"><div class="row"><div class="request-main">'
        . '<div class="title">Custody path</div>'
        . '<div class="sub">From ' . h($requester) . ' to ' . h($holder) . '</div>'
        . '</div><div class="status-block">' . render_status_chip($status) . '</div></div>'
        . '<div class="statepath" aria-label="Request custody path">' . $path . '</div>'
        . '<div class="next-action"><strong>' . h((string)$next['role'] . ' · ' . (string)$next['title']) . '</strong><span>' . h((string)$next['body']) . '</span></div>'
        . '</div>';
}

function render_request_schedule_panel(array $r): string {
    $schedule = request_schedule_values($r);
    $rows = '';
    $labels = [
        'pickup_window_start' => t('schedule.pickup_window_start', 'Pickup start'),
        'pickup_window_end' => t('schedule.pickup_window_end', 'Pickup end'),
        'delivery_window_start' => t('schedule.delivery_window_start', 'Delivery start'),
        'delivery_window_end' => t('schedule.delivery_window_end', 'Delivery end'),
        'sla_due_at' => t('schedule.sla_due_at', 'Due by'),
    ];
    foreach ($labels as $key => $label) {
        $value = $schedule[$key] ?? '';
        if ($value === '') continue;
        $rows .= '<tr><td>' . h($label) . '</td><td><span class="mono">' . h(format_app_datetime($value)) . '</span></td></tr>';
    }
    $body = $rows
        ? '<table class="table" style="margin-top:10px">' . $rows . '</table>'
        : '<div style="margin-top:10px">' . render_state_box('No schedule set', 'This request does not have pickup or delivery windows yet.', [], 'empty') . '</div>';
    $dueBadge = render_due_badge($r);
    return '<div class="card"><div class="row"><div><div class="title">Schedule</div><div class="sub">Optional windows for planning pickup, delivery, and due status.</div></div><div>' . $dueBadge . '</div></div>' . $body . '</div>';
}

function render_request_payment_panel(array $r): string {
    $payment = payment_for_request((int)$r['id']);
    $amount = (int)$r['price_cents'];
    $fee = (int)$r['fee_cents'];
    $runnerNet = max(0, $amount - $fee);
    if ($payment) {
        $rows = '<tr><td>Receipt</td><td><span class="mono">' . h((string)$payment['receipt_no']) . '</span></td></tr>'
            . '<tr><td>Method</td><td><span class="mono">' . h((string)$payment['method']) . '</span></td></tr>'
            . '<tr><td>Status</td><td>' . render_status_chip((string)$payment['status']) . '</td></tr>'
            . '<tr><td>Amount</td><td><span class="mono">' . h(format_cents((int)$payment['amount_cents'])) . '</span></td></tr>'
            . '<tr><td>Platform fee</td><td><span class="mono">' . h(format_cents((int)$payment['fee_cents'])) . '</span></td></tr>'
            . '<tr><td>Runner net</td><td><span class="mono">' . h(format_cents(max(0, (int)$payment['amount_cents'] - (int)$payment['fee_cents']))) . '</span></td></tr>'
            . ((string)($payment['gateway_reference'] ?? '') !== '' ? '<tr><td>Gateway ref</td><td><span class="mono">' . h((string)$payment['gateway_reference']) . '</span></td></tr>' : '')
            . '<tr><td>Confirmed by</td><td>' . h((string)($payment['confirmed_by_name'] ?? 'Gateway or operator')) . '</td></tr>'
            . '<tr><td>Confirmed at</td><td><span class="mono">' . h(format_app_datetime((string)($payment['confirmed_at'] ?? ''))) . '</span></td></tr>';
        return '<div class="card"><div class="title">Payment receipt</div><table class="table" style="margin-top:10px">' . $rows . '</table></div>';
    }

    $body = '<table class="table" style="margin-top:10px">'
        . '<tr><td>Amount</td><td><span class="mono">' . h(format_cents($amount)) . '</span></td></tr>'
        . '<tr><td>Platform fee</td><td><span class="mono">' . h(format_cents($fee)) . '</span></td></tr>'
        . '<tr><td>Runner net</td><td><span class="mono">' . h(format_cents($runnerNet)) . '</span></td></tr>'
        . '<tr><td>Status</td><td><span class="sub">Awaiting manual confirmation</span></td></tr>'
        . '</table>';
    return '<div class="card"><div class="title">Payment</div><div class="sub" style="margin-top:8px">LiteGig records manual payment confirmation and receipt details; payment itself stays peer-to-peer.</div>' . $body . '</div>';
}

function render_request_actions(array $u, array $r): string {
    global $CFG;
    $primaryActions = '';
    $exceptionActions = '';
    $commentForm = '';

    if (can_accept_request($u, $r)) {
        $primaryActions .= '<div class="action-card"><form method="post" action="?action=accept_request&id=' . (int)$r['id'] . '" class="stack">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<button class="btn btn-primary btnblock" type="submit">Accept</button>'
            . '</form></div>';
    }
    if (can_mark_picked_up_request($u, $r)) {
        $primaryActions .= '<div class="action-card"><form method="post" action="?action=mark_picked_up&id=' . (int)$r['id'] . '" enctype="multipart/form-data" class="stack" data-offline-queue="runner" data-offline-label="Pickup for ' . h((string)$r['title']) . '">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . render_file_input('proof', 'Pickup proof', true)
            . '<button class="btn btn-primary btnblock" type="submit">Mark picked up</button>'
            . '</form></div>';
    }
    if (can_confirm_payment_request($u, $r)) {
        $primaryActions .= '<div class="action-card"><form method="post" action="?action=confirm_payment&id=' . (int)$r['id'] . '" class="stack">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<button class="btn btn-primary btnblock" type="submit">Confirm payment (manual)</button>'
            . '</form></div>';
    }
    if (!empty($CFG['payment_gateway_enabled']) && can_start_gateway_payment_request($u, $r)) {
        $primaryActions .= '<div class="action-card"><form method="post" action="?action=start_gateway_payment&id=' . (int)$r['id'] . '" class="stack">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<button class="btn btnblock" type="submit">Start gateway payment</button>'
            . '<div class="help">Optional. Manual confirmation remains available; LiteGig stores no card data.</div>'
            . '</form></div>';
    }
    if (can_mark_delivered_request($u, $r)) {
        $primaryActions .= '<div class="action-card"><form method="post" action="?action=mark_delivered&id=' . (int)$r['id'] . '" enctype="multipart/form-data" class="stack" data-offline-queue="runner" data-offline-label="Delivery for ' . h((string)$r['title']) . '">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . render_delivery_otp_input($r)
            . render_file_input('proof', 'Delivery proof', true)
            . '<button class="btn btn-primary btnblock" type="submit">Mark delivered</button>'
            . '</form></div>';
    }
    if (can_confirm_delivery_request($u, $r)) {
        $primaryActions .= '<div class="action-card"><form method="post" action="?action=mark_delivered&id=' . (int)$r['id'] . '" class="stack">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<button class="btn btn-primary btnblock" type="submit">Confirm delivery</button>'
            . '</form></div>';
    }
    if (can_edit_request($u, $r)) {
        $exceptionActions .= '<div class="action-card"><div class="itemtitle">Edit before acceptance</div><div class="sub" style="margin-top:4px">Update details, schedule, price, or schema fields before a runner accepts.</div><a class="btn btnblock" style="margin-top:8px" href="?action=edit_request&id=' . (int)$r['id'] . '">Edit request</a></div>';
    }
    if (can_decline_request($u, $r)) {
        $exceptionActions .= render_exception_form((int)$r['id'], 'decline_request', 'decline', 'Decline assignment', 'Decline');
    }
    if (can_cancel_request($u, $r)) {
        $exceptionActions .= render_exception_form((int)$r['id'], 'cancel_request', 'cancel', 'Cancel request', 'Cancel request', 'btn btn-danger');
    }
    if (can_dispute_request($u, $r)) {
        $exceptionActions .= render_exception_form((int)$r['id'], 'dispute_request', 'dispute', 'Open dispute', 'Open dispute');
    }
    if (can_reopen_request($u, $r)) {
        $exceptionActions .= render_exception_form((int)$r['id'], 'reopen_request', 'reopen', 'Reopen request', 'Reopen request', 'btn btn-primary');
    }

    if (can_comment_request($u, $r)) {
        $commentForm = '<form method="post" action="?action=post_event&id=' . (int)$r['id'] . '" enctype="multipart/form-data" class="stack" style="margin-top:10px">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<label>Post an update</label>'
            . '<textarea name="note" placeholder="Short note (no sensitive payment details). Optional if you attach a file."></textarea>'
            . render_file_input('attachment', 'Attach proof or context')
            . '<button class="btn btnblock" type="submit">Post</button>'
            . '</form>';
    }

    $primary = $primaryActions !== '' ? '<div class="stack sticky-actions">' . $primaryActions . '</div>' : '';
    $exceptions = $exceptionActions !== '' ? '<div class="stack" style="margin-top:10px">' . $exceptionActions . '</div>' : '';
    $empty = ($primaryActions === '' && $exceptionActions === '' && $commentForm === '') ? '<div class="empty" style="margin-top:10px"><div class="empty-title">No actions available</div><div class="empty-body">This request has no available action for your account right now.</div></div>' : '';
    return '<div class="card"><div class="title">Actions</div>' . $primary . $exceptions . $commentForm . $empty . '</div>';
}

function request_event_label(string $type): string {
    $labels = [
        'created' => 'Request created',
        'accepted' => 'Accepted',
        'picked_up' => 'Picked up',
        'payment_confirmed' => 'Payment confirmed',
        'delivered' => 'Delivered',
        'delivery_confirmed' => 'Delivery confirmed',
        'completed' => 'Completed',
        'rated' => 'Rated',
        'comment' => 'Comment',
        'edited' => 'Edited',
        'delivery_otp_created' => 'Delivery OTP generated',
        'cancelled' => 'Cancelled',
        'declined' => 'Declined',
        'disputed' => 'Disputed',
        'reopened' => 'Reopened',
        'expired' => 'Expired',
    ];
    return $labels[$type] ?? ucwords(str_replace('_', ' ', $type));
}

function render_request_thread(int $requestId): string {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT e.*, u.display_name AS actor_name
        FROM events e
        LEFT JOIN users u ON u.id = e.actor_id
        WHERE e.request_id = ? AND e.type = 'comment'
        ORDER BY e.id ASC");
    $stmt->execute([$requestId]);
    $rows = $stmt->fetchAll();
    $items = '';
    foreach ($rows as $e) {
        $note = (string)$e['note'];
        $items .= '<div class="thread-item">'
            . '<div class="thread-head"><div>'
            . '<div class="itemtitle">' . h((string)($e['actor_name'] ?? 'System')) . '</div>'
            . '<div class="itemmeta mono">' . h(format_app_datetime((string)$e['created_at'])) . '</div>'
            . '</div><div>' . render_task_type_badge('Comment') . '</div></div>'
            . ($note !== '' ? '<div class="longtext" style="margin-top:8px">' . h($note) . '</div>' : '<div class="sub" style="margin-top:8px">Attachment added.</div>')
            . render_event_attachment_preview($e)
            . '</div>';
    }

    return '<div class="card"><div class="title">Comment thread</div>'
        . '<div class="sub">Participant updates rendered from comment events.</div>'
        . '<div class="thread">' . ($items ?: '<div class="empty"><div class="empty-title">No comments yet</div><div class="empty-body">Requester, runner, and admins can post updates from the action panel.</div></div>') . '</div></div>';
}

function render_request_events(int $requestId): string {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT e.*, u.display_name AS actor_name FROM events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.request_id = ? ORDER BY e.id ASC");
    $stmt->execute([$requestId]);
    $rows = $stmt->fetchAll();
    $items = '';
    foreach ($rows as $e) {
        $items .= '<div class="item">'
            . '<div class="itemtop">'
            . '<div class="request-main">'
            . '<div class="itemtitle">' . h(request_event_label((string)$e['type'])) . '</div>'
            . '<div class="itemmeta">' . h((string)($e['actor_name'] ?? 'System')) . ' · <span class="mono">' . h(format_app_datetime((string)$e['created_at'])) . '</span></div>'
            . '<div class="longtext" style="margin-top:8px">' . h((string)$e['note']) . '</div>'
            . render_event_attachment_preview($e)
            . '</div>'
            . '</div>'
            . '</div>';
    }
    return '<div class="card"><div class="title">Event log</div><div class="list" style="margin-top:10px">' . ($items ?: '<div class="empty"><div class="empty-title">No events yet</div><div class="empty-body">Status changes and comments will appear here.</div></div>') . '</div></div>';
}

function render_request_rating_block(array $u, array $r): string {
    $uid = (int)$u['id'];
    $isRequester = $uid === (int)$r['requester_id'];
    $isRunner = $r['runner_id'] !== null && $uid === (int)$r['runner_id'];
    $status = (string)$r['status'];
    if (!in_array($status, ['delivered', 'completed'], true)) {
        return '<div class="card"><div class="title">Ratings</div><div style="margin-top:10px">' . render_state_box('Ratings not available yet', 'Ratings unlock after delivery.', [], 'empty') . '</div></div>';
    }
    if (!can_rate_request($u, $r)) {
        return '<div class="card"><div class="title">Ratings</div><div style="margin-top:10px">' . render_state_box('Participants only', 'Only the requester and assigned runner can rate this request.', [], 'empty') . '</div></div>';
    }

    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM ratings WHERE request_id = ?");
    $stmt->execute([(int)$r['id']]);
    $ratings = $stmt->fetchAll();
    $byRater = [];
    foreach ($ratings as $x) $byRater[(int)$x['rater_id']] = $x;

    $targetId = $isRequester ? (int)$r['runner_id'] : (int)$r['requester_id'];
    $targetLabel = $isRequester ? 'Rate runner' : 'Rate requester';
    $already = $byRater[$uid] ?? null;

    $existing = '';
    if ($ratings) {
        $existing .= '<div class="list" style="margin-top:10px">';
        $stmt2 = $pdo->prepare("SELECT r.*, u1.display_name AS rater_name, u2.display_name AS ratee_name
            FROM ratings r
            JOIN users u1 ON u1.id = r.rater_id
            JOIN users u2 ON u2.id = r.ratee_id
            WHERE r.request_id = ? ORDER BY r.id ASC");
        $stmt2->execute([(int)$r['id']]);
        foreach ($stmt2->fetchAll() as $rr) {
            $existing .= '<div class="item">'
                . '<div class="itemtitle">' . h((string)$rr['rater_name']) . ' → ' . h((string)$rr['ratee_name']) . ' · <span class="mono">' . (int)$rr['score'] . '/5</span></div>'
                . '<div class="itemmeta mono">' . h(format_app_datetime((string)$rr['created_at'])) . '</div>'
                . '<div class="longtext" style="margin-top:8px">' . h((string)$rr['note']) . '</div>'
                . '</div>';
        }
        $existing .= '</div>';
    }

    $form = '';
    if ($already) {
        $form = '<div style="margin-top:10px">' . render_state_box('Rating submitted', 'You already rated this request.', [], 'empty') . '</div>';
    } elseif (!$targetId) {
        $form = '<div style="margin-top:10px">' . render_state_box('Cannot rate yet', 'There is no counterpart assigned to rate.', [], 'empty') . '</div>';
    } else {
        $form = '<form method="post" action="?action=leave_rating&id=' . (int)$r['id'] . '" class="stack" style="margin-top:10px">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<label>' . h($targetLabel) . '</label>'
            . '<select name="score" required>'
            . '<option value="">Select…</option>'
            . '<option value="5">5 - Excellent</option>'
            . '<option value="4">4 - Good</option>'
            . '<option value="3">3 - OK</option>'
            . '<option value="2">2 - Poor</option>'
            . '<option value="1">1 - Bad</option>'
            . '</select>'
            . '<textarea name="note" placeholder="Short note"></textarea>'
            . '<button class="btn btn-primary btnblock" type="submit">Submit rating</button>'
            . '</form>';
    }

    return '<div class="card"><div class="title">Ratings</div>' . $existing . $form . '</div>';
}

function action_post_event(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render_method_not_allowed();
    }
    require_csrf();
    enforce_critical_rate_limit('post_event', (int)$u['id']);
    $id = input_int($_GET, 'id', 0, 0, 1000000000);
    $r = fetch_request_full($id);
    if (!$r) {
        flash_set('error', 'Request not found.');
        redirect_to('?action=list_requests');
    }
    if (!can_comment_request($u, $r)) {
        render_forbidden('Participants only', 'Only the requester, assigned runner, or an admin can post updates on this request.');
    }
    $errors = [];
    $attachment = uploaded_event_attachment('attachment', 'evt', $errors);
    if ($errors) {
        flash_set('error', reset($errors) ?: 'Attachment could not be saved.');
        redirect_to('?action=get_request&id=' . $id);
    }
    $note = input_string($_POST, 'note', 1000);
    if ($note === '' && !$attachment) {
        flash_set('error', 'Add a note or attach a file.');
        redirect_to('?action=get_request&id=' . $id);
    }
    add_event($id, (int)$u['id'], 'comment', $note !== '' ? $note : 'Attachment added', $attachment);
    audit_log((int)$u['id'], 'post_event', 'request', $id, ['attachment' => $attachment !== null]);
    $updated = fetch_request_full($id);
    if ($updated) queue_request_notifications('request_comment', $updated, $u, $note !== '' ? $note : 'Attachment added');
    flash_set('ok', 'Posted.');
    redirect_to('?action=get_request&id=' . $id);
}

function action_accept_request(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render_method_not_allowed();
    }
    require_csrf();
    enforce_critical_rate_limit('accept_request', (int)$u['id']);
    $id = input_int($_GET, 'id', 0, 0, 1000000000);
    $pdo = db();
    $r = fetch_request_full($id);
    if (!$r) {
        flash_set('error', 'Request not found.');
        redirect_to('?action=list_requests');
    }
    if (!can_accept_request($u, $r)) {
        render_forbidden('Accept unavailable', 'You can only accept unassigned open requests posted by another user.');
    }

    // Race protection: only one runner can accept. Transaction + conditional UPDATE.
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE requests SET status='accepted', runner_id=?, updated_at=? WHERE id=? AND " . request_transition_guard_sql('accept') . " AND requester_id <> ?");
        $stmt->execute([(int)$u['id'], now_iso(), $id, (int)$u['id']]);
        if ($stmt->rowCount() < 1) {
            $pdo->rollBack();
            flash_set('error', 'Could not accept (already accepted or not eligible).');
            redirect_to('?action=get_request&id=' . $id);
        }
        add_event($id, (int)$u['id'], 'accepted', 'Runner accepted the request');
        audit_log((int)$u['id'], 'accept_request', 'request', $id);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('error', 'Accept failed.');
        redirect_to('?action=get_request&id=' . $id);
    }
    $updated = fetch_request_full($id);
    if ($updated) {
        queue_request_notifications('request_accepted', $updated, $u);
        try {
            $otp = create_delivery_otp($id, (int)$u['id']);
            if ($otp) {
                $updatedWithOtp = fetch_request_full($id) ?: $updated;
                queue_request_notifications('delivery_otp', $updatedWithOtp, $u, '', [
                    'delivery_otp' => (string)$otp['code'],
                    'delivery_otp_hint' => (string)$otp['hint'],
                ]);
            }
        } catch (Throwable $e) {
            app_log('Delivery OTP generation failed after accept', ['request_id' => $id, 'error' => $e->getMessage()]);
        }
    }
    flash_set('ok', 'Accepted.');
    redirect_to('?action=get_request&id=' . $id);
}

function action_mark_picked_up(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render_method_not_allowed();
    }
    require_csrf();
    enforce_critical_rate_limit('mark_picked_up', (int)$u['id']);
    $id = input_int($_GET, 'id', 0, 0, 1000000000);
    $pdo = db();
    $r = fetch_request_full($id);
    if (!$r) {
        flash_set('error', 'Request not found.');
        redirect_to('?action=list_requests');
    }
    if (!can_mark_picked_up_request($u, $r)) {
        render_forbidden('Pickup unavailable', 'Only the assigned runner can mark an accepted request as picked up.');
    }
    $errors = [];
    $attachment = uploaded_event_attachment('proof', 'proof', $errors);
    if ($errors) {
        flash_set('error', reset($errors) ?: 'Proof file could not be saved.');
        redirect_to('?action=get_request&id=' . $id);
    }
    $stmt = $pdo->prepare("UPDATE requests SET status='picked_up', updated_at=? WHERE id=? AND " . request_transition_guard_sql('mark_picked_up') . " AND runner_id=?");
    $stmt->execute([now_iso(), $id, (int)$u['id']]);
    if ($stmt->rowCount() < 1) {
        if ($attachment) delete_stored_upload((string)$attachment['name']);
        flash_set('error', 'Cannot mark picked up.');
        redirect_to('?action=get_request&id=' . $id);
    }
    add_event($id, (int)$u['id'], 'picked_up', 'Runner marked picked up', $attachment);
    audit_log((int)$u['id'], 'mark_picked_up', 'request', $id, ['attachment' => $attachment !== null]);
    $updated = fetch_request_full($id);
    if ($updated) queue_request_notifications('request_picked_up', $updated, $u);
    flash_set('ok', 'Marked picked up.');
    redirect_to('?action=get_request&id=' . $id);
}

function action_confirm_payment(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render_method_not_allowed();
    }
    require_csrf();
    enforce_critical_rate_limit('confirm_payment', (int)$u['id']);
    $id = input_int($_GET, 'id', 0, 0, 1000000000);
    $pdo = db();
    $r = fetch_request_full($id);
    if (!$r) {
        flash_set('error', 'Request not found.');
        redirect_to('?action=list_requests');
    }
    if (!can_confirm_payment_request($u, $r)) {
        render_forbidden('Payment confirmation unavailable', 'Only the requester can record manual payment for an accepted or picked-up request.');
    }
    $stmt = $pdo->prepare("UPDATE requests SET status='payment_confirmed', updated_at=? WHERE id=? AND " . request_transition_guard_sql('confirm_payment') . " AND requester_id=?");
    $stmt->execute([now_iso(), $id, (int)$u['id']]);
    if ($stmt->rowCount() < 1) {
        flash_set('error', 'Cannot confirm payment.');
        redirect_to('?action=get_request&id=' . $id);
    }
    add_event($id, (int)$u['id'], 'payment_confirmed', 'Requester confirmed payment (manual)');
    $payment = record_manual_payment($r, (int)$u['id']);
    audit_log((int)$u['id'], 'confirm_payment', 'request', $id);
    $updated = fetch_request_full($id);
    if ($updated) queue_request_notifications('payment_confirmed', $updated, $u);
    flash_set('ok', 'Payment confirmed. Receipt ' . (string)($payment['receipt_no'] ?? '') . ' recorded.');
    redirect_to('?action=get_request&id=' . $id);
}

function action_start_gateway_payment(): void {
    global $CFG;
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render_method_not_allowed();
    }
    require_csrf();
    enforce_critical_rate_limit('start_gateway_payment', (int)$u['id']);
    if (empty($CFG['payment_gateway_enabled'])) {
        flash_set('error', 'Gateway payments are not enabled for this deployment.');
        redirect_to('?action=list_requests');
    }
    $id = input_int($_GET, 'id', 0, 0, 1000000000);
    $r = fetch_request_full($id);
    if (!$r) {
        flash_set('error', 'Request not found.');
        redirect_to('?action=list_requests');
    }
    if (!can_start_gateway_payment_request($u, $r)) {
        render_forbidden('Gateway payment unavailable', 'Only the requester can start a gateway payment before manual payment is confirmed.');
    }
    $payment = record_gateway_payment($r);
    audit_log((int)$u['id'], 'start_gateway_payment', 'request', $id, [
        'gateway_reference' => (string)($payment['gateway_reference'] ?? ''),
    ]);
    $reference = (string)($payment['gateway_reference'] ?? '');
    $checkoutUrl = (string)($CFG['payment_gateway_checkout_url'] ?? '');
    if ($checkoutUrl !== '' && preg_match('/^https?:\/\//i', $checkoutUrl) && $reference !== '') {
        $sep = str_contains($checkoutUrl, '?') ? '&' : '?';
        redirect_to($checkoutUrl . $sep . http_build_query(['reference' => $reference]));
    }
    flash_set('ok', 'Gateway payment reference created: ' . $reference . '. Use your configured gateway checkout to collect payment.');
    redirect_to('?action=get_request&id=' . $id);
}

function webhook_text_response(int $status, string $body): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $body;
    exit;
}

function action_payment_gateway_webhook(): void {
    global $CFG;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        webhook_text_response(405, 'Method not allowed');
    }
    if (empty($CFG['payment_gateway_enabled']) || (string)$CFG['payment_gateway_webhook_secret'] === '') {
        webhook_text_response(403, 'Gateway webhook disabled');
    }
    $raw = (string)file_get_contents('php://input');
    $signature = (string)($_SERVER['HTTP_X_LITEGIG_SIGNATURE'] ?? '');
    $expected = 'sha256=' . hash_hmac('sha256', $raw, (string)$CFG['payment_gateway_webhook_secret']);
    if (!hash_equals($expected, $signature)) {
        webhook_text_response(403, 'Bad signature');
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        webhook_text_response(400, 'Bad JSON');
    }
    $eventId = substr(trim((string)($payload['event_id'] ?? $payload['id'] ?? '')), 0, 160);
    $reference = substr(trim((string)($payload['gateway_reference'] ?? $payload['reference'] ?? '')), 0, 160);
    $status = substr(trim((string)($payload['status'] ?? '')), 0, 40);
    if ($eventId === '' || $reference === '' || !in_array($status, ['pending', 'confirmed', 'refunded', 'failed'], true)) {
        webhook_text_response(400, 'Missing event_id, reference, or valid status');
    }
    if (!record_payment_webhook_event($eventId, $reference, $status, $payload)) {
        webhook_text_response(200, 'OK duplicate');
    }
    $payment = payment_by_gateway_reference($reference);
    if (!$payment) {
        webhook_text_response(404, 'Payment reference not found');
    }
    if (isset($payload['amount_cents']) && (int)$payload['amount_cents'] !== (int)$payment['amount_cents']) {
        update_gateway_payment_status((int)$payment['id'], 'failed', $eventId, $payload);
        audit_log(null, 'payment_gateway_amount_mismatch', 'payment', (int)$payment['id'], [
            'event_id' => $eventId,
            'expected' => (int)$payment['amount_cents'],
            'received' => (int)$payload['amount_cents'],
        ]);
        webhook_text_response(409, 'Amount mismatch');
    }

    update_gateway_payment_status((int)$payment['id'], $status, $eventId, $payload);
    audit_log(null, 'payment_gateway_webhook', 'payment', (int)$payment['id'], [
        'event_id' => $eventId,
        'status' => $status,
    ]);

    if ($status === 'confirmed') {
        $pdo = db();
        $request = fetch_request_full((int)$payment['request_id']);
        if ($request && request_transition_allows('gateway_payment_confirmed', (string)$request['status'])) {
            $stmt = $pdo->prepare("UPDATE requests SET status='payment_confirmed', updated_at=? WHERE id=? AND " . request_transition_guard_sql('gateway_payment_confirmed'));
            $stmt->execute([now_iso(), (int)$payment['request_id']]);
            if ($stmt->rowCount() > 0) {
                add_event((int)$payment['request_id'], null, 'payment_confirmed', 'Gateway payment confirmed');
                $updated = fetch_request_full((int)$payment['request_id']);
                if ($updated) {
                    queue_request_notifications('payment_confirmed', $updated, ['id' => 0, 'display_name' => 'Payment gateway']);
                }
            }
        }
    }

    webhook_text_response(200, 'OK');
}

function action_generate_delivery_otp(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render_method_not_allowed();
    }
    require_csrf();
    enforce_critical_rate_limit('generate_delivery_otp', (int)$u['id']);
    $id = input_int($_GET, 'id', 0, 0, 1000000000);
    $r = fetch_request_full($id);
    if (!$r) {
        flash_set('error', 'Request not found.');
        redirect_to('?action=list_requests');
    }
    if (!can_generate_delivery_otp_request($u, $r)) {
        render_forbidden('Delivery OTP unavailable', 'Only the requester or an admin can generate a delivery OTP while the job is active.');
    }
    $otp = create_delivery_otp($id, (int)$u['id']);
    if (!$otp) {
        flash_set('error', 'Delivery OTP could not be generated for this request state.');
        redirect_to('?action=get_request&id=' . $id);
    }
    $updated = fetch_request_full($id) ?: $r;
    queue_request_notifications('delivery_otp', $updated, $u, '', [
        'delivery_otp' => (string)$otp['code'],
        'delivery_otp_hint' => (string)$otp['hint'],
    ]);
    audit_log((int)$u['id'], 'generate_delivery_otp', 'request', $id, ['hint' => (string)$otp['hint']]);
    flash_set('ok', 'Delivery OTP generated: ' . (string)$otp['code'] . '. Share it with the runner only at handoff.');
    redirect_to('?action=get_request&id=' . $id);
}

function action_mark_delivered(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render_method_not_allowed();
    }
    require_csrf();
    enforce_critical_rate_limit('mark_delivered', (int)$u['id']);
    $id = input_int($_GET, 'id', 0, 0, 1000000000);
    $pdo = db();
    $r = fetch_request_full($id);
    if (!$r) {
        flash_set('error', 'Request not found.');
        redirect_to('?action=list_requests');
    }
    if (!can_mark_delivered_request($u, $r) && !can_confirm_delivery_request($u, $r)) {
        render_forbidden('Delivery action unavailable', 'Only the assigned runner can mark delivery, and only the requester can confirm it.');
    }
    $isRunnerDelivery = can_mark_delivered_request($u, $r);
    if ($isRunnerDelivery) {
        $otp = input_string($_POST, 'delivery_otp', 12);
        if (!delivery_otp_required($r) || trim((string)($r['delivery_otp_hash'] ?? '')) === '') {
            flash_set('error', 'Ask the requester to generate a delivery OTP before handoff.');
            redirect_to('?action=get_request&id=' . $id);
        }
        if (!verify_delivery_otp_for_request($r, $otp)) {
            audit_log((int)$u['id'], 'delivery_otp_failed', 'request', $id);
            flash_set('error', 'Delivery OTP did not match.');
            redirect_to('?action=get_request&id=' . $id);
        }
    }
    $errors = [];
    $attachment = uploaded_event_attachment('proof', 'proof', $errors);
    if ($errors) {
        flash_set('error', reset($errors) ?: 'Proof file could not be saved.');
        redirect_to('?action=get_request&id=' . $id);
    }

    // Single endpoint for both sides:
    // - Runner: picked_up/payment_confirmed -> delivered
    // - Requester: delivered -> completed
    $stmtRunner = $pdo->prepare("UPDATE requests SET status='delivered', updated_at=? WHERE id=? AND " . request_transition_guard_sql('mark_delivered') . " AND runner_id=?");
    $stmtRunner->execute([now_iso(), $id, (int)$u['id']]);
    if ($stmtRunner->rowCount() > 0) {
        mark_delivery_otp_verified($id);
        add_event($id, (int)$u['id'], 'delivered', 'Runner marked delivered with OTP verification', $attachment);
        audit_log((int)$u['id'], 'mark_delivered', 'request', $id, ['attachment' => $attachment !== null, 'otp_verified' => true]);
        $updated = fetch_request_full($id);
        if ($updated) queue_request_notifications('request_delivered', $updated, $u);
        flash_set('ok', 'Marked delivered.');
        redirect_to('?action=get_request&id=' . $id);
    }

    $stmtReq = $pdo->prepare("UPDATE requests SET status='completed', updated_at=? WHERE id=? AND " . request_transition_guard_sql('confirm_delivery') . " AND requester_id=?");
    $stmtReq->execute([now_iso(), $id, (int)$u['id']]);
    if ($stmtReq->rowCount() > 0) {
        add_event($id, (int)$u['id'], 'delivery_confirmed', 'Requester confirmed delivery', $attachment);
        audit_log((int)$u['id'], 'confirm_delivery', 'request', $id, ['attachment' => $attachment !== null]);
        flash_set('ok', 'Delivery confirmed.');
        redirect_to('?action=get_request&id=' . $id);
    }

    if ($attachment) delete_stored_upload((string)$attachment['name']);
    flash_set('error', 'Cannot mark delivered / confirm delivery.');
    redirect_to('?action=get_request&id=' . $id);
}

function action_cancel_request(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render_method_not_allowed();
    }
    require_csrf();
    enforce_critical_rate_limit('cancel_request', (int)$u['id']);
    $id = input_int($_GET, 'id', 0, 0, 1000000000);
    $r = fetch_request_full($id);
    if (!$r) {
        flash_set('error', 'Request not found.');
        redirect_to('?action=list_requests');
    }
    if (!can_cancel_request($u, $r)) {
        render_forbidden('Cancel unavailable', 'Only the requester or an admin can cancel this request before it is closed.');
    }
    $note = exception_note_from_post('cancel');
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE requests SET status='cancelled', updated_at=? WHERE id=? AND " . request_transition_guard_sql('cancel'));
    $stmt->execute([now_iso(), $id]);
    if ($stmt->rowCount() < 1) {
        flash_set('error', 'Cannot cancel request.');
        redirect_to('?action=get_request&id=' . $id);
    }
    add_event($id, (int)$u['id'], 'cancelled', $note);
    audit_log((int)$u['id'], 'cancel_request', 'request', $id, ['note' => $note]);
    $updated = fetch_request_full($id);
    if ($updated) queue_request_notifications('request_comment', $updated, $u, 'Request cancelled. ' . $note);
    flash_set('ok', 'Request cancelled.');
    redirect_to('?action=get_request&id=' . $id);
}

function action_decline_request(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render_method_not_allowed();
    }
    require_csrf();
    enforce_critical_rate_limit('decline_request', (int)$u['id']);
    $id = input_int($_GET, 'id', 0, 0, 1000000000);
    $r = fetch_request_full($id);
    if (!$r) {
        flash_set('error', 'Request not found.');
        redirect_to('?action=list_requests');
    }
    if (!can_decline_request($u, $r)) {
        render_forbidden('Decline unavailable', 'Only the assigned runner can decline an accepted request before pickup.');
    }
    $note = exception_note_from_post('decline');
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE requests SET status='new', runner_id=NULL, updated_at=? WHERE id=? AND " . request_transition_guard_sql('decline') . " AND runner_id=?");
    $stmt->execute([now_iso(), $id, (int)$u['id']]);
    if ($stmt->rowCount() < 1) {
        flash_set('error', 'Cannot decline request.');
        redirect_to('?action=get_request&id=' . $id);
    }
    add_event($id, (int)$u['id'], 'declined', $note);
    audit_log((int)$u['id'], 'decline_request', 'request', $id, ['note' => $note]);
    $updated = fetch_request_full($id);
    if ($updated) queue_request_notifications('request_comment', $updated, $u, 'Runner declined. ' . $note);
    flash_set('ok', 'Assignment declined; request returned to the Open Pool.');
    redirect_to('?action=get_request&id=' . $id);
}

function action_dispute_request(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render_method_not_allowed();
    }
    require_csrf();
    enforce_critical_rate_limit('dispute_request', (int)$u['id']);
    $id = input_int($_GET, 'id', 0, 0, 1000000000);
    $r = fetch_request_full($id);
    if (!$r) {
        flash_set('error', 'Request not found.');
        redirect_to('?action=list_requests');
    }
    if (!can_dispute_request($u, $r)) {
        render_forbidden('Dispute unavailable', 'Only participants or admins can dispute an active request.');
    }
    $note = exception_note_from_post('dispute');
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE requests SET status='disputed', updated_at=? WHERE id=? AND " . request_transition_guard_sql('dispute'));
    $stmt->execute([now_iso(), $id]);
    if ($stmt->rowCount() < 1) {
        flash_set('error', 'Cannot dispute request.');
        redirect_to('?action=get_request&id=' . $id);
    }
    add_event($id, (int)$u['id'], 'disputed', $note);
    audit_log((int)$u['id'], 'dispute_request', 'request', $id, ['note' => $note]);
    $updated = fetch_request_full($id);
    if ($updated) queue_request_notifications('request_comment', $updated, $u, 'Dispute opened. ' . $note);
    flash_set('ok', 'Dispute opened.');
    redirect_to('?action=get_request&id=' . $id);
}

function action_reopen_request(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render_method_not_allowed();
    }
    require_csrf();
    enforce_critical_rate_limit('reopen_request', (int)$u['id']);
    $id = input_int($_GET, 'id', 0, 0, 1000000000);
    $r = fetch_request_full($id);
    if (!$r) {
        flash_set('error', 'Request not found.');
        redirect_to('?action=list_requests');
    }
    if (!can_reopen_request($u, $r)) {
        render_forbidden('Reopen unavailable', 'Only the requester or an admin can reopen a cancelled, disputed, or expired request.');
    }
    $note = exception_note_from_post('reopen');
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE requests SET status='new', runner_id=NULL, updated_at=? WHERE id=? AND " . request_transition_guard_sql('reopen'));
    $stmt->execute([now_iso(), $id]);
    if ($stmt->rowCount() < 1) {
        flash_set('error', 'Cannot reopen request.');
        redirect_to('?action=get_request&id=' . $id);
    }
    add_event($id, (int)$u['id'], 'reopened', $note);
    audit_log((int)$u['id'], 'reopen_request', 'request', $id, ['note' => $note]);
    flash_set('ok', 'Request reopened.');
    redirect_to('?action=get_request&id=' . $id);
}

function action_leave_rating(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render_method_not_allowed();
    }
    require_csrf();
    enforce_critical_rate_limit('leave_rating', (int)$u['id']);
    $id = input_int($_GET, 'id', 0, 0, 1000000000);
    $r = fetch_request_full($id);
    if (!$r) {
        flash_set('error', 'Request not found.');
        redirect_to('?action=list_requests');
    }
    $status = (string)$r['status'];
    if (!in_array($status, ['delivered', 'completed'], true)) {
        flash_set('error', 'Ratings unlock after delivery.');
        redirect_to('?action=get_request&id=' . $id);
    }

    $uid = (int)$u['id'];
    $isRequester = $uid === (int)$r['requester_id'];
    $isRunner = $r['runner_id'] !== null && $uid === (int)$r['runner_id'];
    if (!can_rate_request($u, $r)) {
        flash_set('error', 'Only participants can rate.');
        redirect_to('?action=get_request&id=' . $id);
    }
    $rateeId = $isRequester ? (int)$r['runner_id'] : (int)$r['requester_id'];
    if (!$rateeId) {
        flash_set('error', 'Cannot rate: no counterpart.');
        redirect_to('?action=get_request&id=' . $id);
    }

    $score = input_int($_POST, 'score', 0, 0, 5);
    $note = input_string($_POST, 'note', 800);
    if ($score < 1 || $score > 5) {
        flash_set('error', 'Select a score.');
        redirect_to('?action=get_request&id=' . $id);
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM ratings WHERE request_id=? AND rater_id=?");
    $stmt->execute([$id, $uid]);
    if ((int)$stmt->fetch()['c'] > 0) {
        flash_set('error', 'You already rated this request.');
        redirect_to('?action=get_request&id=' . $id);
    }
    $ins = $pdo->prepare("INSERT INTO ratings (request_id, rater_id, ratee_id, score, note, created_at) VALUES (?, ?, ?, ?, ?, ?)");
    $ins->execute([$id, $uid, $rateeId, $score, $note, now_iso()]);
    add_event($id, $uid, 'rated', 'A rating was submitted');
    audit_log($uid, 'leave_rating', 'request', $id, ['score' => $score]);

    $stmt2 = $pdo->prepare("SELECT COUNT(DISTINCT rater_id) AS c FROM ratings WHERE request_id=?");
    $stmt2->execute([$id]);
    $c = (int)$stmt2->fetch()['c'];
    if ($c >= 2 && request_transition_allows('complete_after_ratings', (string)$r['status'])) {
        $upd = $pdo->prepare("UPDATE requests SET status='completed', updated_at=? WHERE id=? AND " . request_transition_guard_sql('complete_after_ratings'));
        $upd->execute([now_iso(), $id]);
        if ($upd->rowCount() > 0) {
            add_event($id, null, 'completed', 'Request completed (both sides rated)');
        }
    }

    flash_set('ok', 'Rating submitted.');
    redirect_to('?action=get_request&id=' . $id);
}
