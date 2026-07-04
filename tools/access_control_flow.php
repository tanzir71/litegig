<?php
declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8765', '/');
$checks = 0;

final class AccessFlowClient {
    /** @var array<string,string> */
    private array $cookies = [];

    /** @return array{status:int,body:string,location:string} */
    public function request(string $method, string $url, array $data = []): array {
        $headers = [];
        if ($this->cookies) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) $pairs[] = $k . '=' . $v;
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
        $content = '';
        if ($method === 'POST') {
            $content = http_build_query($data);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'Content-Length: ' . strlen($content);
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
        ]]);
        $body = file_get_contents($url, false, $ctx);
        $status = 0;
        $location = '';
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) $status = (int)$m[1];
            if (preg_match('/^Location:\s*(.+)$/i', $line, $m)) $location = trim($m[1]);
            if (stripos($line, 'Set-Cookie:') === 0 && preg_match('/Set-Cookie:\s*([^=]+)=([^;]*)/i', $line, $m)) {
                $this->cookies[$m[1]] = $m[2];
            }
        }
        return ['status' => $status, 'body' => $body === false ? '' : $body, 'location' => $location];
    }

    public function csrf(string $url): string {
        $res = $this->request('GET', $url);
        access_flow_check($res['status'] === 200, 'CSRF source returns 200');
        access_flow_check(preg_match('/name="csrf" value="([^"]+)"/', $res['body'], $m) === 1, 'CSRF token is present');
        return $m[1];
    }
}

function access_flow_check(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function access_flow_login(AccessFlowClient $client, string $base, string $email, string $password): array {
    $csrf = $client->csrf($base . '/litegig.php?action=login');
    return $client->request('POST', $base . '/litegig.php?action=login', [
        'csrf' => $csrf,
        'email' => $email,
        'password' => $password,
    ]);
}

$suffix = bin2hex(random_bytes(4));
$seedEmail = 'seed-admin+' . $suffix . '@example.test';
$prodEmail = 'prod-admin+' . $suffix . '@example.test';
$seedPass = 'seed-pass-123';
$prodPass = 'prod-pass-123';

$seed = new AccessFlowClient();
$csrf = $seed->csrf($base . '/litegig.php?action=register');
$res = $seed->request('POST', $base . '/litegig.php?action=register', [
    'csrf' => $csrf,
    'email' => $seedEmail,
    'display_name' => 'Seed Admin',
    'phone' => '',
    'password' => $seedPass,
]);
access_flow_check($res['status'] === 302, 'seed admin registers');

$csrf = $seed->csrf($base . '/litegig.php?action=admin_console');
$res = $seed->request('POST', $base . '/litegig.php?action=create_admin_user', [
    'csrf' => $csrf,
    'email' => $prodEmail,
    'display_name' => 'Production Admin',
    'phone' => '',
    'password' => $prodPass,
    'reason' => 'Create real production admin before disabling seed access',
]);
access_flow_check($res['status'] === 302, 'production admin is created from admin UI');

$csrf = $seed->csrf($base . '/litegig.php?action=admin_console');
$seed->request('POST', $base . '/litegig.php?action=logout', ['csrf' => $csrf]);

$prod = new AccessFlowClient();
$res = access_flow_login($prod, $base, $prodEmail, $prodPass);
access_flow_check($res['status'] === 302, 'production admin can log in');

$adminPage = $prod->request('GET', $base . '/litegig.php?action=admin_console');
access_flow_check($adminPage['status'] === 200, 'production admin can open admin console');
$quoted = preg_quote($seedEmail, '/');
access_flow_check(preg_match('/<div class="item">(?:(?!<div class="item">).)*' . $quoted . '(?:(?!<div class="item">).)*name="id" value="(\d+)"/s', $adminPage['body'], $idMatch) === 1, 'seed admin id appears in user access row');
$seedId = $idMatch[1];
access_flow_check(preg_match('/name="csrf" value="([^"]+)"/', $adminPage['body'], $csrfMatch) === 1, 'admin console CSRF is present');
$csrf = $csrfMatch[1];

$res = $prod->request('POST', $base . '/litegig.php?action=reset_user_password', [
    'csrf' => $csrf,
    'id' => $seedId,
    'password' => 'rotated-seed-pass-123',
    'reason' => 'Rotate seed admin password after production admin verification',
]);
access_flow_check($res['status'] === 302, 'seed admin password reset succeeds');

$adminPage = $prod->request('GET', $base . '/litegig.php?action=admin_console');
access_flow_check(preg_match('/name="csrf" value="([^"]+)"/', $adminPage['body'], $csrfMatch) === 1, 'fresh admin console CSRF is present');
$res = $prod->request('POST', $base . '/litegig.php?action=update_user_role', [
    'csrf' => $csrfMatch[1],
    'id' => $seedId,
    'status' => 'suspended',
    'reason' => 'Disable seed admin after production admin verification',
]);
access_flow_check($res['status'] === 302, 'seed admin disable succeeds');

$oldSeed = new AccessFlowClient();
$res = access_flow_login($oldSeed, $base, $seedEmail, $seedPass);
access_flow_check($res['status'] === 200, 'old seed login no longer redirects');
access_flow_check(str_contains($res['body'], 'Invalid email or password') || str_contains($res['body'], 'suspended'), 'old seed login fails visibly');

echo "Access-control flow passed ({$checks} checks).\n";
