<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root . '/manifest.webmanifest') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
$sw = file_get_contents($root . '/litegig-sw.js') ?: '';
$pwa = file_get_contents($root . '/litegig-pwa.js') ?: '';
$requests = file_get_contents($root . '/app/controllers/requests.php') ?: '';
$layout = file_get_contents($root . '/app/views/layout.php') ?: '';
$checks = 0;

function check_pwa(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

check_pwa(($manifest['display'] ?? '') === 'standalone', 'manifest is installable standalone');
check_pwa(($manifest['start_url'] ?? '') === 'litegig.php?action=runner_sheet', 'manifest starts at runner job sheet');
check_pwa(count($manifest['icons'] ?? []) >= 2, 'manifest includes app icons');
check_pwa(str_contains($sw, 'offline.html'), 'service worker caches offline fallback');
check_pwa(str_contains($sw, 'litegig.php?action=runner_sheet'), 'service worker caches runner job sheet');
check_pwa(str_contains($pwa, 'indexedDB.open'), 'PWA client uses IndexedDB for offline queue');
check_pwa(str_contains($pwa, 'offlineQueue') && str_contains($pwa, '"runner"'), 'PWA client listens for offline queue forms');
check_pwa(str_contains($pwa, 'window.addEventListener("online"'), 'PWA client syncs when back online');
check_pwa(str_contains($requests, 'data-offline-queue="runner"'), 'runner pickup/delivery forms are marked queueable');
check_pwa(str_contains($layout, '<link rel="manifest" href="manifest.webmanifest">'), 'app layout links manifest');
check_pwa(str_contains($layout, '<script src="litegig-pwa.js" defer></script>'), 'app layout loads PWA script');

echo "PWA static checks passed ({$checks} checks).\n";
