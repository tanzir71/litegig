<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sources = [
    'layout' => file_get_contents($root . '/app/views/layout.php') ?: '',
    'index' => file_get_contents($root . '/index.html') ?: '',
    'docs' => file_get_contents($root . '/docs.html') ?: '',
    'offline' => file_get_contents($root . '/offline.html') ?: '',
];

$checks = 0;

function check_a11y(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

check_a11y(str_contains($sources['layout'], 'Skip to content'), 'app layout includes a skip link');
check_a11y(str_contains($sources['layout'], '<main id="main"'), 'app layout includes a main landmark');
check_a11y(str_contains($sources['layout'], ':focus-visible'), 'app layout defines visible focus styles');
check_a11y(str_contains($sources['layout'], 'min-height:44px'), 'app layout keeps touch targets at least 44px');
check_a11y(str_contains($sources['layout'], 'role="status"'), 'app layout includes status regions for non-color feedback');
check_a11y(str_contains($sources['index'], 'name="viewport"'), 'landing page includes viewport metadata');
check_a11y(str_contains($sources['docs'], 'name="viewport"'), 'docs page includes viewport metadata');
check_a11y(str_contains($sources['offline'], 'name="viewport"'), 'offline page includes viewport metadata');
check_a11y(!preg_match('/outline\\s*:\\s*none/i', implode("\n", $sources)), 'sources do not remove focus outlines');

echo "Accessibility static checks passed ({$checks} checks).\n";
