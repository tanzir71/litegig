<?php
declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8765', '/');
$iterations = max(5, min(200, (int)($argv[2] ?? 30)));
$cookies = [];

function load_request(string $method, string $url, array $data = []): array {
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
    $start = microtime(true);
    $body = file_get_contents($url, false, $ctx);
    $elapsedMs = (microtime(true) - $start) * 1000;
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) $status = (int)$m[1];
        if (stripos($line, 'Set-Cookie:') === 0 && preg_match('/Set-Cookie:\s*([^=]+)=([^;]*)/i', $line, $m)) {
            $cookies[$m[1]] = $m[2];
        }
    }
    return [$status, $body === false ? '' : $body, $elapsedMs];
}

[$status, $body] = load_request('GET', $base . '/litegig.php?action=register');
if ($status !== 200 || !preg_match('/name="csrf" value="([^"]+)"/', $body, $m)) {
    fwrite(STDERR, "Could not initialize load smoke session.\n");
    exit(1);
}
[$status] = load_request('POST', $base . '/litegig.php?action=register', [
    'csrf' => $m[1],
    'email' => 'load+' . bin2hex(random_bytes(4)) . '@example.test',
    'display_name' => 'Load Admin',
    'phone' => '',
    'password' => 'load-test-pass',
]);
if ($status !== 302) {
    fwrite(STDERR, "Could not register load smoke user.\n");
    exit(1);
}

[$status, $body] = load_request('GET', $base . '/litegig.php?action=list_requests');
if ($status !== 200 || !preg_match('/name="csrf" value="([^"]+)"/', $body, $csrfMatch)) {
    fwrite(STDERR, "Could not read authenticated list page.\n");
    exit(1);
}
$csrf = $csrfMatch[1];

$timings = [];
$failures = 0;
for ($i = 0; $i < $iterations; $i++) {
    [$status,, $ms] = load_request('GET', $base . '/litegig.php?action=list_requests&status=all&per_page=25');
    if ($status !== 200) $failures++;
    $timings[] = $ms;
}
for ($i = 0; $i < max(3, (int)floor($iterations / 10)); $i++) {
    [$status,, $ms] = load_request('POST', $base . '/litegig.php?action=accept_request&id=0', ['csrf' => $csrf]);
    if (!in_array($status, [302, 403, 404], true)) $failures++;
    $timings[] = $ms;
}

sort($timings);
$count = count($timings);
$avg = array_sum($timings) / max(1, $count);
$p95 = $timings[(int)floor(($count - 1) * 0.95)] ?? 0.0;

if ($failures > 0 || $p95 > 2500.0) {
    fwrite(STDERR, sprintf("Load smoke failed: failures=%d avg=%.1fms p95=%.1fms\n", $failures, $avg, $p95));
    exit(1);
}

echo sprintf("Load smoke passed: requests=%d avg=%.1fms p95=%.1fms\n", $count, $avg, $p95);
