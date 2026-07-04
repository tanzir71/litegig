<?php
declare(strict_types=1);

function action_list_task_types(): void {
    require_admin();
    $types = get_task_types(true);
    $rows = '';
    foreach ($types as $t) {
        $isActive = (int)($t['active'] ?? 1) === 1;
        $state = $isActive
            ? '<span class="pill status-chip">' . h(t('common.active', 'Active')) . '</span>'
            : '<span class="pill status-chip">' . h(t('common.archived', 'Archived')) . '</span>';
        $archived = !$isActive && (string)($t['archived_at'] ?? '') !== ''
            ? ' · archived <span class="mono">' . h(format_app_datetime((string)$t['archived_at'])) . '</span>'
            : '';
        $rows .= '<div class="item">'
            . '<div class="itemtop">'
            . '<div class="request-main"><div class="itemtitle">' . h($t['name']) . '</div>'
            . '<div class="itemmeta">id <span class="mono">' . (int)$t['id'] . '</span> · created <span class="mono">' . h(format_app_datetime((string)$t['created_at'])) . '</span>' . $archived . '</div></div>'
            . '<div class="stack item-actions">'
            . $state
            . '<a class="btn" href="?action=edit_task_type&id=' . (int)$t['id'] . '">Edit</a>'
            . '</div>'
            . '</div>'
            . '</div>';
    }
    $html = '<div class="card"><div class="row"><div>'
        . '<div class="title">Task Types</div>'
        . '<div class="sub">Define schemas that drive request forms and detail views.</div>'
        . '</div><div><a class="btn btn-primary" href="?action=create_task_type">New</a></div></div></div>'
        . '<div class="list">' . ($rows ?: render_state_box('No task types yet', 'Create a schema before posting operational requests.', [
            ['label' => 'New task type', 'href' => '?action=create_task_type', 'primary' => true],
        ], 'empty')) . '</div>';
    render_layout('Task Types', $html);
}

function validate_task_type_json(string $json, array &$out): ?string {
    $decoded = json_decode($json, true);
    if ($decoded === null) return 'Invalid JSON.';
    $fields = $decoded;
    $summary = [];
    if (is_array($decoded)) {
        $isAssoc = array_keys($decoded) !== range(0, count($decoded) - 1);
        if ($isAssoc) {
            $fields = $decoded['fields'] ?? null;
            $summary = $decoded['summary_fields'] ?? [];
        }
    }
    if (!is_array($fields)) return 'Expected an array of fields, or an object with {fields:[...], summary_fields:[...]}.';
    foreach ($fields as $f) {
        if (!is_array($f)) return 'Every field must be an object.';
        if (empty($f['key']) || !is_string($f['key'])) return 'Every field needs a string `key`.';
        if (empty($f['label']) || !is_string($f['label'])) return 'Every field needs a string `label`.';
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $f['key'])) return 'Field keys must start with a letter and use only letters, numbers, and underscores.';
        if (strlen($f['label']) > 120) return 'Field labels must be 120 characters or fewer.';
        $type = (string)($f['type'] ?? '');
        $allowed = ['text', 'textarea', 'number', 'price', 'boolean', 'date', 'time', 'datetime', 'geo', 'select', 'attachment', 'note', 'readonly'];
        if (!in_array($type, $allowed, true)) return 'Invalid type: ' . $type;
        if ($type === 'select') {
            $opts = $f['options'] ?? null;
            if (!is_array($opts) || count($opts) === 0) return 'Select fields require `options`.';
            foreach ($opts as $o) {
                if (!is_array($o)) return 'Select options must be objects.';
                if (!array_key_exists('value', $o) || !is_scalar($o['value'])) return 'Select options need scalar values.';
                if (strlen((string)$o['value']) > 120 || strlen((string)($o['label'] ?? $o['value'])) > 120) return 'Select option values and labels must be 120 characters or fewer.';
            }
        }
    }
    $keys = array_flip(array_map(fn($f) => (string)$f['key'], $fields));
    foreach ($summary as $key) {
        if (!is_string($key) || !isset($keys[$key])) return 'Summary fields must reference defined field keys.';
    }
    if (!is_array($summary)) $summary = [];
    $out = $decoded;
    return null;
}

function render_task_type_form(string $mode, array $values, array $errors): string {
    $e = fn(string $k) => isset($errors[$k]) ? '<div class="err">' . h($errors[$k]) . '</div>' : '';
    $idPart = ($mode === 'edit') ? ('&id=' . (int)$values['id']) : '';
    $title = ($mode === 'edit') ? 'Edit task type' : 'New task type';
    $name = (string)($values['name'] ?? '');
    $fieldsJson = (string)($values['fields_json'] ?? "[]");
    $active = (int)($values['active'] ?? 1) === 1;
    $archiveControl = '';
    if ($mode === 'edit') {
        $archiveControl = $active
            ? '<form method="post" action="?action=delete_task_type&id=' . (int)$values['id'] . '" onsubmit="return confirm(\'Archive this task type? Existing requests keep their history.\')">'
                . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
                . '<button class="btn btn-danger btnblock" type="submit">Archive</button></form>'
            : '<form method="post" action="?action=restore_task_type&id=' . (int)$values['id'] . '">'
                . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
                . '<button class="btn btn-primary btnblock" type="submit">Restore</button></form>';
    }
    $html = '<div class="card"><div class="title">' . h($title) . '</div>'
        . '<div class="sub">Fields JSON supports: text, textarea, number, price, boolean, date/time/datetime, geo, select, attachment, note/readonly.</div>'
        . ($mode === 'edit' && !$active ? '<div class="flash warn" style="margin-top:10px">This task type is archived and hidden from new request forms. Existing requests keep their historical schema.</div>' : '')
        . '<form method="post" action="?action=' . ($mode === 'edit' ? 'edit_task_type' : 'create_task_type') . $idPart . '" class="stack" style="margin-top:10px">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<div><label>Name</label><input name="name" value="' . h($name) . '" required>' . $e('name') . '</div>'
        . '<div><label>Fields JSON</label><textarea name="fields_json" id="fields_json" spellcheck="false" required>' . h($fieldsJson) . '</textarea>'
        . '<div class="help">Store either an array of fields, or an object: {"fields":[...],"summary_fields":["key1","key2"]}.</div>'
        . $e('fields_json')
        . '</div>'
        . '<div class="split">'
        . '<button class="btn btn-primary btnblock" type="submit">Save</button>'
        . '<a class="btn btnblock" href="?action=list_task_types">Back</a>'
        . '</div>'
        . '</form>'
        . '<div style="margin-top:12px" class="split">'
        . '<button class="btn btnblock" type="button" onclick="previewTaskType()">Preview form</button>'
        . ($archiveControl ?: '<div></div>')
        . '</div>'
        . '</div>';

    $html .= '<div class="card" id="preview_card" style="display:none"><div class="title">Preview</div><div id="preview_fields" style="margin-top:10px"></div></div>';
    $html .= <<<'HTML'
<script>
function el(tag, attrs, text) {
    var e = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    if (text !== undefined) e.textContent = text;
    return e;
}
function showPreviewMessage(out, cls, text) {
    out.replaceChildren(el("div", {class: cls}, text));
}
function renderField(field) {
    var key = field.key || "";
    var type = field.type || "text";
    var label = field.label || key;
    var req = !!field.required;
    var wrap = el("div");
    wrap.style.marginBottom = "12px";
    wrap.appendChild(el("label", null, label + (req ? " *" : "")));
    var input = null;
    if (type === "textarea") {
        input = el("textarea", {name: key});
    } else if (type === "select") {
        input = el("select", {name: key});
        (field.options || []).forEach(function (o) {
            input.appendChild(el("option", {value: o.value || ""}, o.label || o.value || ""));
        });
    } else if (type === "boolean") {
        var c = el("div");
        var cb = el("input", {type: "checkbox", name: key, value: "1"});
        cb.style.width = "auto";
        cb.style.marginRight = "10px";
        c.appendChild(cb);
        c.appendChild(el("span", null, "Yes"));
        wrap.appendChild(c);
        return wrap;
    } else if (type === "price") {
        input = el("input", {type: "text", name: key, placeholder: field.placeholder || "e.g., 12.34"});
    } else if (type === "number") {
        input = el("input", {type: "number", name: key, step: "any", placeholder: field.placeholder || ""});
    } else if (type === "date") {
        input = el("input", {type: "date", name: key});
    } else if (type === "time") {
        input = el("input", {type: "time", name: key});
    } else if (type === "datetime") {
        input = el("input", {type: "datetime-local", name: key});
    } else if (type === "geo") {
        var a = el("input", {type: "text", name: key + "_address", placeholder: field.placeholder || "Address"});
        wrap.appendChild(a);
        wrap.appendChild(el("div", {class: "help"}, "Geo fields store address and coordinates. Location button appears on request forms."));
        return wrap;
    } else if (type === "attachment") {
        input = el("input", {type: "file", name: key});
    } else {
        input = el("input", {type: "text", name: key, placeholder: field.placeholder || ""});
    }
    if (req && input) input.required = true;
    if (input) wrap.appendChild(input);
    return wrap;
}
function previewTaskType() {
    var txt = document.getElementById("fields_json").value;
    var card = document.getElementById("preview_card");
    var out = document.getElementById("preview_fields");
    out.replaceChildren();
    var obj = null;
    try {
        obj = JSON.parse(txt);
    } catch (e) {
        card.style.display = "block";
        showPreviewMessage(out, "err", "Invalid JSON.");
        return;
    }
    var fields = obj;
    if (obj && !Array.isArray(obj) && typeof obj === "object") fields = obj.fields || [];
    if (!Array.isArray(fields)) {
        card.style.display = "block";
        showPreviewMessage(out, "err", "Expected array of fields.");
        return;
    }
    if (!fields.length) {
        card.style.display = "block";
        showPreviewMessage(out, "empty", "No fields to preview.");
        return;
    }
    fields.forEach(function (f) {
        if (f && typeof f === "object") out.appendChild(renderField(f));
    });
    card.style.display = "block";
    window.scrollTo({top: card.offsetTop - 10, behavior: "smooth"});
}
</script>
HTML;

    return $html;
}

function action_create_task_type(): void {
    require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $name = input_string($_POST, 'name', 120);
        $fieldsJson = input_string($_POST, 'fields_json', 20000);
        $errors = [];
        if ($name === '') $errors['name'] = 'Required.';
        if ($fieldsJson === '') $errors['fields_json'] = 'Required.';
        $tmp = [];
        if (!$errors) {
            $err = validate_task_type_json($fieldsJson, $tmp);
            if ($err) $errors['fields_json'] = $err;
        }
        if (!$errors) {
            $pdo = db();
            $stmt = $pdo->prepare("INSERT INTO task_types (name, fields_json, created_at) VALUES (?, ?, ?)");
            try {
                $stmt->execute([$name, $fieldsJson, now_iso()]);
            } catch (Throwable $e) {
                $errors['name'] = 'Name must be unique.';
            }
            if (!$errors) {
                audit_log((int)$_SESSION['uid'], 'create_task_type', 'task_type', (int)$pdo->lastInsertId(), ['name' => $name]);
                flash_set('ok', 'Task type created.');
                redirect_to('?action=list_task_types');
            }
        }
        render_layout('New Task Type', render_task_type_form('create', ['name' => $name, 'fields_json' => $fieldsJson], $errors));
        return;
    }

    $example = [
        ["key" => "pickup_address", "label" => "Pickup address", "type" => "text", "required" => true],
        ["key" => "dropoff_address", "label" => "Dropoff address", "type" => "text", "required" => false],
        ["key" => "price_cents", "label" => "Price (USD)", "type" => "price", "required" => true],
        ["key" => "num_copies", "label" => "Number of flyers", "type" => "number", "required" => false],
    ];
    $prefill = json_encode(['summary_fields' => ['pickup_address', 'dropoff_address', 'price_cents'], 'fields' => $example], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    render_layout('New Task Type', render_task_type_form('create', ['name' => '', 'fields_json' => $prefill], []));
}

function action_edit_task_type(): void {
    require_admin();
    $id = input_int($_GET, 'id', 0, 0, 1000000000);
    $tt = get_task_type_by_id($id);
    if (!$tt) {
        render_not_found('Task type not found', 'This task type may have been deleted or the link is stale.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $name = input_string($_POST, 'name', 120);
        $fieldsJson = input_string($_POST, 'fields_json', 20000);
        $errors = [];
        if ($name === '') $errors['name'] = 'Required.';
        if ($fieldsJson === '') $errors['fields_json'] = 'Required.';
        $tmp = [];
        if (!$errors) {
            $err = validate_task_type_json($fieldsJson, $tmp);
            if ($err) $errors['fields_json'] = $err;
        }
        if (!$errors) {
            $pdo = db();
            $stmt = $pdo->prepare("UPDATE task_types SET name = ?, fields_json = ? WHERE id = ?");
            try {
                $stmt->execute([$name, $fieldsJson, $id]);
            } catch (Throwable $e) {
                $errors['name'] = 'Name must be unique.';
            }
            if (!$errors) {
                audit_log((int)$_SESSION['uid'], 'edit_task_type', 'task_type', $id, ['name' => $name]);
                flash_set('ok', 'Task type updated.');
                redirect_to('?action=list_task_types');
            }
        }
        render_layout('Edit Task Type', render_task_type_form('edit', ['id' => $id, 'name' => $name, 'fields_json' => $fieldsJson, 'active' => (int)($tt['active'] ?? 1)], $errors));
        return;
    }
    render_layout('Edit Task Type', render_task_type_form('edit', ['id' => $id, 'name' => $tt['name'], 'fields_json' => $tt['fields_json'], 'active' => (int)($tt['active'] ?? 1)], []));
}

function action_delete_task_type(): void {
    require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render_method_not_allowed();
    }
    require_csrf();
    $id = input_int($_GET, 'id', 0, 0, 1000000000);
    if (archive_task_type($id)) {
        audit_log((int)$_SESSION['uid'], 'archive_task_type', 'task_type', $id);
        flash_set('ok', 'Task type archived. Existing requests keep their history; new requests will not use it.');
    } else {
        flash_set('error', 'Task type could not be archived.');
    }
    redirect_to('?action=list_task_types');
}

function action_restore_task_type(): void {
    require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render_method_not_allowed();
    }
    require_csrf();
    $id = input_int($_GET, 'id', 0, 0, 1000000000);
    if (restore_task_type($id)) {
        audit_log((int)$_SESSION['uid'], 'restore_task_type', 'task_type', $id);
        flash_set('ok', 'Task type restored.');
    } else {
        flash_set('error', 'Task type could not be restored.');
    }
    redirect_to('?action=list_task_types');
}
