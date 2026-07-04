<?php
declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8765', '/');
$cookies = [];
$checks = 0;

function smoke_request(string $method, string $url, array $data = []): array {
    global $cookies;
    $headers = [];
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $k => $v) $pairs[] = $k . '=' . $v;
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
    $responseHeaders = $http_response_header ?? [];
    foreach ($responseHeaders as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) $status = (int)$m[1];
        if (stripos($line, 'Set-Cookie:') === 0 && preg_match('/Set-Cookie:\s*([^=]+)=([^;]*)/i', $line, $m)) {
            $cookies[$m[1]] = $m[2];
        }
    }
    return [$status, $body === false ? '' : $body, $responseHeaders];
}

function smoke_check(bool $ok, string $label): void {
    global $checks;
    $checks++;
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function smoke_header_contains(array $headers, string $needle): bool {
    foreach ($headers as $line) {
        if (stripos($line, $needle) !== false) return true;
    }
    return false;
}

foreach (['/index.html', '/docs.html', '/vercel-demo/index.html', '/styles/tokens.css', '/manifest.webmanifest', '/offline.html', '/litegig-pwa.js', '/litegig-sw.js', '/litegig.php?action=health'] as $path) {
    [$status, $body] = smoke_request('GET', $base . $path);
    smoke_check($status === 200, "{$path} returns 200");
    smoke_check($body !== '', "{$path} has a response body");
    if ($path === '/litegig.php?action=health') {
        $health = json_decode($body, true);
        smoke_check(is_array($health) && ($health['ok'] ?? false) === true, 'health endpoint reports liveness ok');
        smoke_check(is_array($health['checks'] ?? null), 'health endpoint includes readiness checks');
        smoke_check(array_key_exists('ready', $health), 'health endpoint includes readiness summary');
    }
}

[$status, $body] = smoke_request('GET', $base . '/litegig.php?action=register');
smoke_check($status === 200, 'register page returns 200');
smoke_check(preg_match('/name="csrf" value="([^"]+)"/', $body, $m) === 1, 'register form includes CSRF');

$email = 'admin+' . bin2hex(random_bytes(4)) . '@example.test';
[$status] = smoke_request('POST', $base . '/litegig.php?action=register', [
    'csrf' => $m[1],
    'email' => $email,
    'display_name' => 'Smoke Admin',
    'phone' => '+15550009999',
    'password' => 'smoke-test-pass',
]);
smoke_check($status === 302, 'admin registration redirects after success');

foreach (['/litegig.php?action=list_requests', '/litegig.php?action=open_pool', '/litegig.php?action=runner_sheet', '/litegig.php?action=payments', '/litegig.php?action=admin_console', '/litegig.php?action=reports'] as $path) {
    [$status, $body] = smoke_request('GET', $base . $path);
    smoke_check($status === 200, "{$path} authenticated page returns 200");
    smoke_check(str_contains($body, 'LiteGig'), "{$path} renders app shell");
}

[$status, $body, $headers] = smoke_request('GET', $base . '/litegig.php?action=export_csv&download=1&format=xls&scope=all');
smoke_check($status === 200, 'request history Excel export returns 200');
smoke_check(smoke_header_contains($headers, 'application/vnd.ms-excel'), 'request history Excel export has Excel content type');
smoke_check(str_contains($body, '<table>') && str_contains($body, 'requester_id'), 'request history Excel export renders table data');

[$status, $body, $headers] = smoke_request('GET', $base . '/litegig.php?action=reports&download=xls');
smoke_check($status === 200, 'reports Excel export returns 200');
smoke_check(smoke_header_contains($headers, 'application/vnd.ms-excel'), 'reports Excel export has Excel content type');
smoke_check(str_contains($body, '<table>') && str_contains($body, 'amount_cents'), 'reports Excel export renders table data');

echo "HTTP smoke passed ({$checks} checks).\n";
