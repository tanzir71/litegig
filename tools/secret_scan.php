<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$allowedDirs = ['app', 'docs', 'monitoring', 'styles', 'tests', 'tools', '.github'];
$allowedFiles = ['litegig.php', 'index.html', 'docs.html', 'offline.html', 'litegig-pwa.js', 'litegig-sw.js', 'manifest.webmanifest', 'README.md', 'SETUP.md', 'SECURITY.md', 'CHANGELOG.md', '.env.example'];

$patterns = [
    'AWS access key' => '/AKIA[0-9A-Z]{16}/',
    'GitHub token' => '/gh[pousr]_[A-Za-z0-9_]{36,}/',
    'Stripe secret key' => '/sk_(?:live|test)_[A-Za-z0-9]{16,}/',
    'Twilio secret' => '/SK[0-9a-fA-F]{32}/',
    'Private key block' => '/-----BEGIN (?:RSA |EC |OPENSSH |)PRIVATE KEY-----/',
    'Generic assignment secret' => '/(?<!EXAMPLE_)(?:api[_-]?key|secret|token|password)[ \t]*[:=][ \t]*[\'"]?(?!change-me|replace-with|test-secret|YOUR_TOKEN|no-reply|support|smoke-test-pass|authz-test|notifications|m3-test|admin-test)[A-Za-z0-9+\/=_\-.]{24,}/i',
];

function secret_scan_files(string $root, array $dirs, array $files): array {
    $out = [];
    foreach ($files as $file) {
        $path = $root . DIRECTORY_SEPARATOR . $file;
        if (is_file($path)) $out[] = $path;
    }
    foreach ($dirs as $dir) {
        $path = $root . DIRECTORY_SEPARATOR . $dir;
        if (!is_dir($path)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) continue;
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['php', 'js', 'css', 'html', 'md', 'yml', 'yaml', 'json', 'webmanifest', 'ps1'], true)) continue;
            $out[] = $file->getPathname();
        }
    }
    return array_values(array_unique($out));
}

$failures = [];
foreach (secret_scan_files($root, $allowedDirs, $allowedFiles) as $file) {
    $content = file_get_contents($file);
    if ($content === false) continue;
    foreach ($patterns as $name => $pattern) {
        if (preg_match($pattern, $content, $m)) {
            $failures[] = $name . ' in ' . str_replace($root . DIRECTORY_SEPARATOR, '', $file);
        }
    }
}

if ($failures) {
    echo "Secret scan failed:\n";
    foreach ($failures as $failure) echo " - {$failure}\n";
    exit(1);
}

echo "Secret scan passed.\n";
