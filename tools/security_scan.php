<?php
declare(strict_types=1);

$path = $argv[1] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'litegig.php';
$source = file_get_contents($path);
if ($source === false) {
    fwrite(STDERR, "Could not read {$path}\n");
    exit(2);
}

$checks = [
    'htmlEscape helper' => '/function\s+htmlEscape\s*\(/',
    'CSP header' => '/Content-Security-Policy/',
    'session regeneration' => '/session_regenerate_id\(true\)/',
    'rate limits table' => '/CREATE TABLE IF NOT EXISTS rate_limits/',
    'request authorization' => '/function\s+can_view_request\s*\(/',
    'private attachment handler' => '/function\s+action_download_attachment\s*\(/',
];

$failures = [];
foreach ($checks as $name => $pattern) {
    if (!preg_match($pattern, $source)) $failures[] = "Missing {$name}";
}

$negative = [
    'caller-controlled innerHTML' => '/innerHTML\s*=\s*html/',
    'insertAdjacentHTML' => '/insertAdjacentHTML/',
    'SQL fragment append' => '/\$sql\s*\.=/',
    'direct uploads link' => '/uploads\/\s*[\'"]?\s*\./',
];

foreach ($negative as $name => $pattern) {
    if (preg_match($pattern, $source)) $failures[] = "Found {$name}";
}

if ($failures) {
    echo "Security scan failed:\n";
    foreach ($failures as $failure) echo " - {$failure}\n";
    exit(1);
}

echo "Security scan passed.\n";
