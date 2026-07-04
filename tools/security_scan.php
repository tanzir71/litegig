<?php
declare(strict_types=1);

$path = $argv[1] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'litegig.php';

function collect_php_sources(string $path): array {
    $files = [];
    if (is_dir($path)) {
        $root = rtrim($path, DIRECTORY_SEPARATOR);
        $files[] = $root . DIRECTORY_SEPARATOR . 'litegig.php';
    } else {
        $root = dirname($path);
        $files[] = $path;
    }

    $appDir = $root . DIRECTORY_SEPARATOR . 'app';
    if (is_dir($appDir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    $files = array_values(array_unique($files));
    sort($files);
    return $files;
}

$sources = [];
foreach (collect_php_sources($path) as $file) {
    $content = is_file($file) ? file_get_contents($file) : false;
    if ($content === false) {
        fwrite(STDERR, "Could not read {$file}\n");
        exit(2);
    }
    $sources[] = $content;
}
$source = implode("\n", $sources);

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
