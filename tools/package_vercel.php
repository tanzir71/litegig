<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dist = $root . DIRECTORY_SEPARATOR . 'dist';
$stageRoot = $dist . DIRECTORY_SEPARATOR . 'vercel';
$stage = $stageRoot . DIRECTORY_SEPARATOR . 'litegig-vercel';
$zipPath = $dist . DIRECTORY_SEPARATOR . 'litegig-vercel.zip';

function vercel_package_rm_tree(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            vercel_package_rm_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function vercel_package_copy_file(string $from, string $to): void {
    $dir = dirname($to);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!copy($from, $to)) {
        throw new RuntimeException('Could not copy ' . $from);
    }
}

function vercel_package_zip_with_ziparchive(string $source, string $zipPath): bool {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = 'litegig-vercel/' . str_replace(DIRECTORY_SEPARATOR, '/', $iterator->getSubPathName());
        if ($item->isDir()) {
            $zip->addEmptyDir($relative);
        } else {
            $zip->addFile($item->getPathname(), $relative);
        }
    }
    return $zip->close();
}

function vercel_package_zip_with_command(string $stageRoot, string $zipPath): bool {
    $cmd = 'cd ' . escapeshellarg($stageRoot) . ' && zip -qr ' . escapeshellarg($zipPath) . ' litegig-vercel';
    exec($cmd, $output, $code);
    return $code === 0 && is_file($zipPath);
}

vercel_package_rm_tree($stageRoot);
if (!is_dir($stage)) @mkdir($stage, 0755, true);
if (!is_dir($dist)) @mkdir($dist, 0755, true);

$html = file_get_contents($root . DIRECTORY_SEPARATOR . 'vercel-demo' . DIRECTORY_SEPARATOR . 'index.html');
if ($html === false) {
    fwrite(STDERR, "Could not read vercel-demo/index.html.\n");
    exit(1);
}
$html = str_replace('href="../styles/tokens.css"', 'href="styles/tokens.css"', $html);
file_put_contents($stage . DIRECTORY_SEPARATOR . 'index.html', $html);

vercel_package_copy_file(
    $root . DIRECTORY_SEPARATOR . 'styles' . DIRECTORY_SEPARATOR . 'tokens.css',
    $stage . DIRECTORY_SEPARATOR . 'styles' . DIRECTORY_SEPARATOR . 'tokens.css'
);

$vercelJson = <<<JSON
{
  "cleanUrls": true,
  "trailingSlash": false
}
JSON;
file_put_contents($stage . DIRECTORY_SEPARATOR . 'vercel.json', $vercelJson . "\n");

$packageJson = <<<JSON
{
  "name": "litegig-vercel",
  "private": true,
  "scripts": {
    "deploy": "vercel --prod"
  }
}
JSON;
file_put_contents($stage . DIRECTORY_SEPARATOR . 'package.json', $packageJson . "\n");

$gitignore = <<<TXT
.vercel
node_modules
.env
.env.*
TXT;
file_put_contents($stage . DIRECTORY_SEPARATOR . '.gitignore', $gitignore . "\n");

$readme = <<<TXT
LiteGig Vercel artifact
=======================

This folder is the Vercel-ready LiteGig browser deployment artifact. It is separate from the PHP shared-host zip.

Dashboard deploy:
1. Extract dist/litegig-vercel.zip.
2. Upload the extracted litegig-vercel folder contents to a new GitHub repository.
3. In Vercel, choose Add New -> Project and import that repository.
4. Keep the framework as Other or No Framework and deploy.

CLI deploy:
1. Extract dist/litegig-vercel.zip.
2. cd litegig-vercel
3. npx vercel@latest --prod

Do not upload dist/litegig-shared-*.zip to Vercel. That zip is for PHP shared hosting.
TXT;
file_put_contents($stage . DIRECTORY_SEPARATOR . 'README.md', $readme);

if (is_file($zipPath)) @unlink($zipPath);
$zipped = vercel_package_zip_with_ziparchive($stage, $zipPath) || vercel_package_zip_with_command($stageRoot, $zipPath);
if (!$zipped) {
    fwrite(STDERR, "Failed to create zip. Install PHP ZipArchive or the zip CLI.\n");
    exit(1);
}

echo "Vercel artifact staged at: {$stage}\n";
echo "Vercel artifact zip: {$zipPath}\n";
