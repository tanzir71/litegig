<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . DIRECTORY_SEPARATOR . '_config.yml';
if (!is_file($path)) {
    fwrite(STDERR, "FAIL: _config.yml is missing.\n");
    exit(1);
}

$lines = file($path, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "FAIL: _config.yml could not be read.\n");
    exit(1);
}

$inExclude = false;
$excludes = [];
foreach ($lines as $line) {
    if (preg_match('/^exclude:\s*$/', $line) === 1) {
        $inExclude = true;
        continue;
    }
    if ($inExclude && preg_match('/^\S/', $line) === 1) {
        $inExclude = false;
    }
    if ($inExclude && preg_match('/^\s*-\s+(.+?)\s*$/', $line, $m) === 1) {
        $value = trim($m[1], "\"'");
        $excludes[] = $value;
    }
}

$required = [
    'app/',
    'tools/',
    'tests/',
    'vercel-demo/',
    '.github/',
    'node_modules/',
    'vendor/',
    'dist/',
    'packages/',
    'litegig_data/',
    'litegig_uploads/',
    'uploads/',
    'litegig.php',
    'health.php',
    '.env',
    '.env.example',
    '.htaccess',
    'package.json',
    'CODEX_BUILD_PLAN.md',
    'monitoring/',
    '*.zip',
    '*.db',
    '*.sqlite',
    '*.log',
];

$missing = array_values(array_diff($required, $excludes));
if ($missing) {
    fwrite(STDERR, "FAIL: _config.yml is missing Pages excludes: " . implode(', ', $missing) . "\n");
    exit(1);
}

echo "Pages config validation passed (" . count($excludes) . " excludes).\n";
