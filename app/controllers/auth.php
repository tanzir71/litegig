<?php
declare(strict_types=1);

function render_register_form(string $email, string $name, string $phone, array $errors): string {
    $e = fn(string $k) => isset($errors[$k]) ? '<div class="err">' . h($errors[$k]) . '</div>' : '';
    return '<div class="card"><div class="title">Create account</div>'
        . '<form method="post" action="?action=register" class="stack" style="margin-top:10px">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<div><label>Email</label><input inputmode="email" name="email" value="' . h($email) . '" autocomplete="email" required>' . $e('email') . '</div>'
        . '<div><label>Name</label><input name="display_name" value="' . h($name) . '" autocomplete="name" required>' . $e('display_name') . '</div>'
        . '<div><label>Phone</label><input inputmode="tel" name="phone" value="' . h($phone) . '" autocomplete="tel" placeholder="+15551234567">'
        . '<div class="help">Optional. Add a phone number to receive SMS handoff alerts and delivery OTPs.</div>' . $e('phone') . '</div>'
        . '<div><label>Password</label><input type="password" name="password" autocomplete="new-password" required>'
        . '<div class="help">8+ characters. Stored as a password hash.</div>' . $e('password') . '</div>'
        . '<button class="btn btn-primary btnblock" type="submit">Register</button>'
        . '<a class="btn btnblock" href="?action=login">I already have an account</a>'
        . '</form></div>';
}

function action_register(): void {
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $email = input_string($_POST, 'email', 254);
        $name = input_string($_POST, 'display_name', 120);
        $phone = input_string($_POST, 'phone', 40);
        $pass = (string)($_POST['password'] ?? '');

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email.';
        if ($name === '') $errors['display_name'] = 'Enter your name.';
        if ($phone !== '' && !preg_match('/^\+?[0-9][0-9 .()\-]{6,32}$/', $phone)) $errors['phone'] = 'Enter a valid phone number.';
        if (strlen($pass) < 8) $errors['password'] = 'Use 8+ characters.';
        if (!$errors && user_by_email($email)) $errors['email'] = 'Email already registered.';

        if (!$errors) {
            $isFirst = count_users() === 0;
            $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, display_name, phone, is_admin, notify_sms_enabled, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $name, $phone !== '' ? $phone : null, $isFirst ? 1 : 0, $phone !== '' ? 1 : 0, now_iso()]);
            $uid = (int)$pdo->lastInsertId();
            session_regenerate_id(true);
            $_SESSION['uid'] = $uid;
            $_SESSION['__created_at'] = time();
            $_SESSION['__last_activity'] = time();
            unset($_SESSION['csrf']);
            audit_log($uid, 'register', 'user', $uid, ['is_admin' => $isFirst]);
            flash_set('ok', $isFirst ? 'Account created. You are admin (first user).' : 'Account created.');
            redirect_to('?action=list_requests');
        }

        render_layout('Register', render_register_form($email, $name, $phone, $errors));
        return;
    }
    render_layout('Register', render_register_form('', '', '', []));
}

function render_login_form(string $email, array $errors): string {
    $e = fn(string $k) => isset($errors[$k]) ? '<div class="err">' . h($errors[$k]) . '</div>' : '';
    return '<div class="card"><div class="title">Login</div>'
        . '<form method="post" action="?action=login" class="stack" style="margin-top:10px">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<div><label>Email</label><input inputmode="email" name="email" value="' . h($email) . '" autocomplete="email" required></div>'
        . '<div><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div>'
        . $e('form')
        . '<button class="btn btn-primary btnblock" type="submit">Login</button>'
        . '<a class="btn btnblock" href="?action=register">Create account</a>'
        . '</form></div>';
}

function action_login(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $email = input_string($_POST, 'email', 254);
        $pass = (string)($_POST['password'] ?? '');
        enforce_login_rate_limit($email);
        $u = user_by_email($email);
        if ($u && password_verify($pass, (string)$u['password_hash'])) {
            if (!user_is_active($u)) {
                audit_log((int)$u['id'], 'login_blocked_suspended', 'user', (int)$u['id']);
                render_layout('Login', render_login_form($email, ['form' => 'This account is suspended. Contact an admin to restore access.']));
                return;
            }
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            $_SESSION['__created_at'] = time();
            $_SESSION['__last_activity'] = time();
            unset($_SESSION['csrf']);
            audit_log((int)$u['id'], 'login', 'user', (int)$u['id']);
            flash_set('ok', 'Logged in.');
            redirect_to('?action=list_requests');
        }
        audit_log($u ? (int)$u['id'] : null, 'login_failed', 'user', $u ? (int)$u['id'] : null, ['email' => $email]);
        render_layout('Login', render_login_form($email, ['form' => 'Invalid email or password.']));
        return;
    }
    render_layout('Login', render_login_form('', []));
}

function action_logout(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render_method_not_allowed();
    }
    require_csrf();
    $u = current_user();
    if ($u) audit_log((int)$u['id'], 'logout', 'user', (int)$u['id']);
    reset_session_state();
    flash_set('ok', 'Logged out.');
    redirect_to('?');
}
