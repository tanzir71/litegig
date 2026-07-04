<?php
declare(strict_types=1);

$root = dirname(__DIR__);
define('LITEGIG_ROOT', $root);

require_once LITEGIG_ROOT . '/app/bootstrap.php';
require_once LITEGIG_ROOT . '/app/http.php';
require_once LITEGIG_ROOT . '/app/controllers/requests.php';

$checks = 0;

function check_perf(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

$imagePreview = render_attachment_preview('?action=download_event_attachment&id=7', 'proof.jpg', 'image/jpeg');
check_perf(str_contains($imagePreview, 'loading="lazy"'), 'image attachment previews are lazy-loaded');
check_perf(str_contains($imagePreview, 'decoding="async"'), 'image attachment previews decode asynchronously');
check_perf(str_contains($imagePreview, 'width="74"') && str_contains($imagePreview, 'height="56"'), 'image attachment previews reserve stable dimensions');
check_perf(str_contains($imagePreview, 'target="_blank"') && str_contains($imagePreview, 'rel="noopener"'), 'attachment preview links isolate new tabs');

$docPreview = render_attachment_preview('?action=download_event_attachment&id=8', 'proof.pdf', 'application/pdf');
check_perf(!str_contains($docPreview, '<img'), 'non-image attachment previews do not create image requests');

$renderSmoke = file_get_contents($root . '/tools/render_smoke.js') ?: '';
check_perf(str_contains($renderSmoke, 'loadMs: 2500'), 'browser render smoke enforces the 2.5s load budget');
check_perf(str_contains($renderSmoke, 'encodedBytes'), 'browser render smoke enforces encoded transfer budget');
check_perf(str_contains($renderSmoke, 'resourceCount'), 'browser render smoke records resource count');

$fontSurfaces = [
    'index.html',
    'docs.html',
    'vercel-demo/index.html',
    'offline.html',
    'app/views/layout.php',
];
foreach ($fontSurfaces as $file) {
    $contents = file_get_contents($root . DIRECTORY_SEPARATOR . $file) ?: '';
    check_perf(!str_contains($contents, 'fonts.googleapis.com') && !str_contains($contents, 'fonts.gstatic.com'), $file . ' avoids render-blocking remote font CSS');
}

$assetBudgets = [
    'index.html' => 60 * 1024,
    'docs.html' => 75 * 1024,
    'vercel-demo/index.html' => 120 * 1024,
    'offline.html' => 10 * 1024,
    'litegig-pwa.js' => 20 * 1024,
    'litegig-sw.js' => 10 * 1024,
    'styles/tokens.css' => 8 * 1024,
];
foreach ($assetBudgets as $file => $budget) {
    $size = filesize($root . DIRECTORY_SEPARATOR . $file);
    check_perf($size !== false && $size <= $budget, $file . ' stays within static asset budget');
}

echo "Performance static checks passed ({$checks} checks).\n";
