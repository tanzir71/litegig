<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dist = $root . DIRECTORY_SEPARATOR . 'dist';
$stageRoot = $dist . DIRECTORY_SEPARATOR . 'shared-host';
$stage = $stageRoot . DIRECTORY_SEPARATOR . 'litegig';
$zipPath = $dist . DIRECTORY_SEPARATOR . 'litegig-shared-' . gmdate('Ymd-His') . '.zip';

function package_rm_tree(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            package_rm_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function package_copy_file(string $from, string $to): void {
    $dir = dirname($to);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!copy($from, $to)) {
        throw new RuntimeException('Could not copy ' . $from);
    }
}

function package_copy_dir(string $from, string $to): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $target = $to . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($target)) @mkdir($target, 0755, true);
        } else {
            package_copy_file($item->getPathname(), $target);
        }
    }
}

function package_zip_with_ziparchive(string $source, string $zipPath): bool {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = 'litegig/' . str_replace(DIRECTORY_SEPARATOR, '/', $iterator->getSubPathName());
        if ($item->isDir()) {
            $zip->addEmptyDir($relative);
        } else {
            $zip->addFile($item->getPathname(), $relative);
        }
    }
    return $zip->close();
}

function package_zip_with_command(string $stageRoot, string $zipPath): bool {
    $cmd = 'cd ' . escapeshellarg($stageRoot) . ' && zip -qr ' . escapeshellarg($zipPath) . ' litegig';
    exec($cmd, $output, $code);
    return $code === 0 && is_file($zipPath);
}

package_rm_tree($stageRoot);
if (!is_dir($stage)) @mkdir($stage, 0755, true);
if (!is_dir($dist)) @mkdir($dist, 0755, true);

$files = [
    '.htaccess',
    '.env.example',
    'litegig.php',
    'health.php',
    'manifest.webmanifest',
    'litegig-pwa.js',
    'litegig-sw.js',
    'offline.html',
    'SETUP.md',
    'SECURITY.md',
];
$dirs = [
    'app',
    'brand',
    'styles',
    'monitoring',
];
$toolFiles = [
    'tools/migrate.php',
    'tools/maintenance.php',
    'tools/production_audit.php',
];

foreach ($files as $file) {
    package_copy_file($root . DIRECTORY_SEPARATOR . $file, $stage . DIRECTORY_SEPARATOR . $file);
}
foreach ($dirs as $dir) {
    package_copy_dir($root . DIRECTORY_SEPARATOR . $dir, $stage . DIRECTORY_SEPARATOR . $dir);
}
foreach ($toolFiles as $file) {
    package_copy_file($root . DIRECTORY_SEPARATOR . $file, $stage . DIRECTORY_SEPARATOR . $file);
}

$readme = <<<TXT
LiteGig shared-host package
===========================

This package is the production PHP + SQLite runtime. Upload/extract the litegig folder into public_html/litegig or another PHP 8+ shared-host directory.

First run:
1. Copy .env.example to .env and set LITEGIG_CRON_TOKEN to a long random value.
2. Confirm PHP extensions: pdo, pdo_sqlite, sqlite3, fileinfo.
3. Visit litegig.php and register the first real production admin.
4. Run php tools/migrate.php up and php tools/production_audit.php from SSH if available.
5. Configure cron for php tools/maintenance.php --apply --backup and litegig.php action=cron_notifications.
6. Open health.php or litegig.php?action=health after creating the admin and first verified backup.

Do not upload tests, node_modules, dist, .git, local databases, or old ZIP files to production.
TXT;
file_put_contents($stage . DIRECTORY_SEPARATOR . 'README.txt', $readme);

if (is_file($zipPath)) @unlink($zipPath);
$zipped = package_zip_with_ziparchive($stage, $zipPath) || package_zip_with_command($stageRoot, $zipPath);
if (!$zipped) {
    fwrite(STDERR, "Failed to create zip. Install PHP ZipArchive or the zip CLI.\n");
    exit(1);
}

echo "Shared-host package staged at: {$stage}\n";
echo "Shared-host package zip: {$zipPath}\n";
