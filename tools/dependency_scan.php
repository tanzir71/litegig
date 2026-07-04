<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$ran = [];

function run_dep_command(string $cmd, string $label, array &$failures, array &$ran): void {
    $ran[] = $label;
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $failures[] = $label . " failed:\n" . implode("\n", $out);
    }
}

if (is_file($root . '/composer.lock')) {
    run_dep_command('cd ' . escapeshellarg($root) . ' && composer audit --locked --no-interaction', 'composer audit', $failures, $ran);
}
if (is_file($root . '/package-lock.json')) {
    run_dep_command('cd ' . escapeshellarg($root) . ' && npm audit --omit=dev --audit-level=high', 'npm audit', $failures, $ran);
}
if (is_file($root . '/pnpm-lock.yaml')) {
    run_dep_command('cd ' . escapeshellarg($root) . ' && pnpm audit --prod --audit-level high', 'pnpm audit', $failures, $ran);
}
if (is_file($root . '/yarn.lock')) {
    run_dep_command('cd ' . escapeshellarg($root) . ' && yarn npm audit --severity high', 'yarn audit', $failures, $ran);
}

if ($failures) {
    echo "Dependency scan failed:\n";
    foreach ($failures as $failure) echo " - {$failure}\n";
    exit(1);
}

if (!$ran) {
    echo "Dependency scan passed: no Composer or npm lockfiles found.\n";
} else {
    echo "Dependency scan passed: " . implode(', ', $ran) . ".\n";
}
