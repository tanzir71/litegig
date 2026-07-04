<?php
declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8765', '/');
$checks = 0;

final class E2EClient {
    /** @var array<string,string> */
    private array $cookies = [];

    /** @return array{status:int,body:string,location:string,headers:array<int,string>} */
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
        $responseHeaders = $http_response_header ?? [];
        foreach ($responseHeaders as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) $status = (int)$m[1];
            if (preg_match('/^Location:\s*(.+)$/i', $line, $m)) $location = trim($m[1]);
            if (stripos($line, 'Set-Cookie:') === 0 && preg_match('/Set-Cookie:\s*([^=]+)=([^;]*)/i', $line, $m)) {
                $this->cookies[$m[1]] = $m[2];
            }
        }
        return [
            'status' => $status,
            'body' => $body === false ? '' : $body,
            'location' => $location,
            'headers' => $responseHeaders,
        ];
    }

    public function csrf(string $url): string {
        $res = $this->request('GET', $url);
        e2e_check($res['status'] === 200, 'CSRF source returns 200: ' . $url);
        e2e_check(preg_match('/name="csrf" value="([^"]+)"/', $res['body'], $m) === 1, 'CSRF token present: ' . $url);
        return $m[1];
    }
}

function e2e_check(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function e2e_form_errors(string $body): string {
    if (preg_match('/const ERRORS=([^;]+);/', $body, $m) !== 1) return 'none found';
    try {
        $errors = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return 'unreadable: ' . $e->getMessage();
    }
    if (!is_array($errors) || !$errors) return 'none';
    $pairs = [];
    foreach ($errors as $field => $message) {
        $pairs[] = (string)$field . '=' . (string)$message;
    }
    return implode(', ', $pairs);
}

function e2e_register(E2EClient $client, string $base, string $email, string $name, string $phone): void {
    $csrf = $client->csrf($base . '/litegig.php?action=register');
    $res = $client->request('POST', $base . '/litegig.php?action=register', [
        'csrf' => $csrf,
        'email' => $email,
        'display_name' => $name,
        'phone' => $phone,
        'password' => 'e2e-test-pass',
    ]);
    e2e_check($res['status'] === 302, 'register redirects for ' . $email);
}

$suffix = bin2hex(random_bytes(4));
$requester = new E2EClient();
$runner = new E2EClient();
$requesterEmail = 'requester+' . $suffix . '@example.test';
$runnerEmail = 'runner+' . $suffix . '@example.test';
e2e_register($requester, $base, $requesterEmail, 'E2E Requester', '+15550004001');
e2e_register($runner, $base, $runnerEmail, 'E2E Runner', '+15550004002');

$create = $requester->request('GET', $base . '/litegig.php?action=create_request');
e2e_check($create['status'] === 200, 'create request page returns 200');
e2e_check(preg_match('/name="csrf" value="([^"]+)"/', $create['body'], $csrfMatch) === 1, 'create request CSRF present');
if (preg_match('/<option value="(\d+)"[^>]*>\s*Delivery\s*<\/option>/', $create['body'], $typeMatch) !== 1) {
    e2e_check(preg_match('/<option value="(\d+)"/', $create['body'], $typeMatch) === 1, 'task type option present');
}
$title = 'E2E delivery ' . $suffix;
$res = $requester->request('POST', $base . '/litegig.php?action=create_request', [
    'csrf' => $csrfMatch[1],
    'task_type_id' => $typeMatch[1],
    'title' => $title,
    'description' => 'End-to-end delivery fixture.',
    'pickup_window_start' => gmdate('Y-m-d\TH:i', time() + 3600),
    'pickup_window_end' => gmdate('Y-m-d\TH:i', time() + 7200),
    'delivery_window_start' => gmdate('Y-m-d\TH:i', time() + 7200),
    'delivery_window_end' => gmdate('Y-m-d\TH:i', time() + 10800),
    'pickup_address' => '1 Test Pickup Way',
    'pickup_lat' => '37.7749',
    'pickup_lng' => '-122.4194',
    'dropoff_address' => '2 Test Dropoff Ave',
    'dropoff_lat' => '37.7849',
    'dropoff_lng' => '-122.4094',
    'price_cents' => '25.00',
    'note' => 'Please handle carefully.',
]);
if ($res['status'] !== 302) {
    fwrite(STDERR, "Create request failed with HTTP {$res['status']} location={$res['location']}\n");
    fwrite(STDERR, "Form errors: " . e2e_form_errors($res['body']) . "\n");
}
e2e_check($res['status'] === 302, 'create request redirects');
e2e_check(preg_match('/id=(\d+)/', $res['location'], $idMatch) === 1, 'created request id appears in redirect');
$requestId = (int)$idMatch[1];

$pool = $runner->request('GET', $base . '/litegig.php?action=open_pool');
e2e_check($pool['status'] === 200, 'runner open pool returns 200');
e2e_check(str_contains($pool['body'], $title), 'runner sees request in open pool');
e2e_check(preg_match('/name="csrf" value="([^"]+)"/', $pool['body'], $runnerCsrf) === 1, 'runner CSRF present');
$res = $runner->request('POST', $base . '/litegig.php?action=accept_request&id=' . $requestId, ['csrf' => $runnerCsrf[1]]);
e2e_check($res['status'] === 302, 'runner accepts request');

$detail = $requester->request('GET', $base . '/litegig.php?action=get_request&id=' . $requestId);
e2e_check($detail['status'] === 200, 'requester detail after accept returns 200');
e2e_check(str_contains($detail['body'], 'Accepted'), 'accepted state is visible');
e2e_check(preg_match('/Tracking code <span class="mono">([^<]+)<\/span>/', $detail['body'], $codeMatch) === 1, 'tracking code appears');
$trackingCode = $codeMatch[1];
e2e_check(preg_match('/name="csrf" value="([^"]+)"/', $detail['body'], $requesterCsrf) === 1, 'requester CSRF present after accept');
$res = $requester->request('POST', $base . '/litegig.php?action=generate_delivery_otp&id=' . $requestId, ['csrf' => $requesterCsrf[1]]);
e2e_check($res['status'] === 302, 'requester generates delivery OTP');
$detail = $requester->request('GET', $base . '/litegig.php?action=get_request&id=' . $requestId);
e2e_check(preg_match('/Delivery OTP generated:\s*(\d{6})/', $detail['body'], $otpMatch) === 1, 'delivery OTP flash exposes one-time code');
$otp = $otpMatch[1];

$runnerDetail = $runner->request('GET', $base . '/litegig.php?action=get_request&id=' . $requestId);
e2e_check($runnerDetail['status'] === 200, 'runner detail returns 200');
e2e_check(preg_match('/name="csrf" value="([^"]+)"/', $runnerDetail['body'], $runnerCsrf) === 1, 'runner detail CSRF present');
$res = $runner->request('POST', $base . '/litegig.php?action=mark_picked_up&id=' . $requestId, ['csrf' => $runnerCsrf[1]]);
e2e_check($res['status'] === 302, 'runner marks picked up');

$detail = $requester->request('GET', $base . '/litegig.php?action=get_request&id=' . $requestId);
e2e_check(str_contains($detail['body'], 'Picked up'), 'picked-up state is visible');
e2e_check(preg_match('/name="csrf" value="([^"]+)"/', $detail['body'], $requesterCsrf) === 1, 'requester CSRF before payment present');
$res = $requester->request('POST', $base . '/litegig.php?action=confirm_payment&id=' . $requestId, ['csrf' => $requesterCsrf[1]]);
e2e_check($res['status'] === 302, 'requester confirms payment');
$detail = $requester->request('GET', $base . '/litegig.php?action=get_request&id=' . $requestId);
e2e_check(str_contains($detail['body'], 'Payment receipt'), 'payment receipt panel appears');
e2e_check(str_contains($detail['body'], 'RCPT-'), 'receipt number appears');

$runnerDetail = $runner->request('GET', $base . '/litegig.php?action=get_request&id=' . $requestId);
e2e_check(preg_match('/name="csrf" value="([^"]+)"/', $runnerDetail['body'], $runnerCsrf) === 1, 'runner CSRF before delivery present');
$res = $runner->request('POST', $base . '/litegig.php?action=mark_delivered&id=' . $requestId, [
    'csrf' => $runnerCsrf[1],
    'delivery_otp' => $otp,
]);
e2e_check($res['status'] === 302, 'runner marks delivered with OTP');

$detail = $requester->request('GET', $base . '/litegig.php?action=get_request&id=' . $requestId);
e2e_check(str_contains($detail['body'], 'Delivered'), 'delivered state is visible');
e2e_check(preg_match('/name="csrf" value="([^"]+)"/', $detail['body'], $requesterCsrf) === 1, 'requester CSRF before closeout present');
$res = $requester->request('POST', $base . '/litegig.php?action=mark_delivered&id=' . $requestId, ['csrf' => $requesterCsrf[1]]);
e2e_check($res['status'] === 302, 'requester confirms delivery closeout');

$detail = $requester->request('GET', $base . '/litegig.php?action=get_request&id=' . $requestId);
e2e_check(str_contains($detail['body'], 'Complete') || str_contains($detail['body'], 'Completed'), 'completed state is visible');
e2e_check(preg_match('/name="csrf" value="([^"]+)"/', $detail['body'], $requesterCsrf) === 1, 'requester CSRF before rating present');
$res = $requester->request('POST', $base . '/litegig.php?action=leave_rating&id=' . $requestId, [
    'csrf' => $requesterCsrf[1],
    'score' => '5',
    'note' => 'Excellent runner.',
]);
e2e_check($res['status'] === 302, 'requester leaves rating');

$runnerDetail = $runner->request('GET', $base . '/litegig.php?action=get_request&id=' . $requestId);
e2e_check(preg_match('/name="csrf" value="([^"]+)"/', $runnerDetail['body'], $runnerCsrf) === 1, 'runner CSRF before rating present');
$res = $runner->request('POST', $base . '/litegig.php?action=leave_rating&id=' . $requestId, [
    'csrf' => $runnerCsrf[1],
    'score' => '5',
    'note' => 'Clear requester.',
]);
e2e_check($res['status'] === 302, 'runner leaves rating');

$public = $requester->request('GET', $base . '/litegig.php?action=track&code=' . rawurlencode($trackingCode));
e2e_check($public['status'] === 200, 'public tracking page returns 200');
e2e_check(str_contains($public['body'], $trackingCode), 'public tracking code renders');
e2e_check(!str_contains($public['body'], $requesterEmail), 'public tracking redacts requester email');
e2e_check(!str_contains($public['body'], $runnerEmail), 'public tracking redacts runner email');

$payments = $requester->request('GET', $base . '/litegig.php?action=payments');
e2e_check($payments['status'] === 200, 'payments page returns 200');
e2e_check(str_contains($payments['body'], 'RCPT-'), 'payments page shows receipt');

echo "E2E happy path passed ({$checks} checks). Request {$requestId}.\n";
