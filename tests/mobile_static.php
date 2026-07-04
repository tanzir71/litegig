<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sources = [
    'layout' => file_get_contents($root . '/app/views/layout.php') ?: '',
    'index' => file_get_contents($root . '/index.html') ?: '',
    'docs' => file_get_contents($root . '/docs.html') ?: '',
    'offline' => file_get_contents($root . '/offline.html') ?: '',
    'demo' => file_get_contents($root . '/vercel-demo/index.html') ?: '',
];

$checks = 0;

function check_mobile(bool $value, string $label): void {
    global $checks;
    $checks++;
    if (!$value) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function css_has_media_at_or_below(string $source, int $px): bool {
    if (!preg_match_all('/@media\s*\(\s*max-width\s*:\s*(\d+)px\s*\)/i', $source, $matches)) return false;
    foreach ($matches[1] as $value) {
        if ((int)$value <= $px) return true;
    }
    return false;
}

foreach (['layout', 'index', 'docs', 'offline', 'demo'] as $name) {
    check_mobile(str_contains($sources[$name], 'name="viewport"'), $name . ' declares mobile viewport metadata');
    check_mobile(str_contains($sources[$name], 'overflow-x:hidden') || str_contains($sources[$name], 'overflow-x: hidden'), $name . ' suppresses horizontal body overflow');
    check_mobile(str_contains($sources[$name], 'min-height:44px') || str_contains($sources[$name], 'min-height: 44px'), $name . ' keeps key touch targets at least 44px');
}

check_mobile(str_contains($sources['layout'], 'viewport-fit=cover'), 'app viewport supports safe-area insets');
check_mobile(str_contains($sources['layout'], 'body{font-size:16px'), 'app body uses 16px base text to avoid mobile input zoom');
check_mobile(str_contains($sources['layout'], 'input,select,textarea') && str_contains($sources['layout'], 'font-size:16px'), 'app form controls use at least 16px text');
check_mobile(str_contains($sources['layout'], '.sticky-actions{position:sticky;bottom:0'), 'app has sticky mobile action bar');
check_mobile(str_contains($sources['layout'], '@media(max-width:640px)'), 'app has the required <=640px single-column breakpoint');
check_mobile(str_contains($sources['layout'], '.nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))'), 'app nav becomes a thumb-friendly mobile grid');
check_mobile(str_contains($sources['layout'], '.table,.table tbody,.table tr,.table td{display:block'), 'app tables collapse to block layout on mobile');
check_mobile(str_contains($sources['layout'], '.state-actions{flex-direction:column;}'), 'app state actions stack on mobile');
check_mobile(str_contains($sources['layout'], 'padding-bottom:72px'), 'app mobile shell reserves bottom space for sticky actions');
check_mobile(!str_contains($sources['layout'], '.brand a{min-height:36px'), 'app brand link stays at a 44px touch target on phones');

check_mobile(css_has_media_at_or_below($sources['index'], 580), 'landing page has a phone-size breakpoint');
check_mobile(css_has_media_at_or_below($sources['docs'], 580), 'docs page has a phone-size breakpoint');
check_mobile(css_has_media_at_or_below($sources['demo'], 780), 'demo has a compact mobile breakpoint');
check_mobile(str_contains($sources['index'], '.actions .btn { width: 100%; }'), 'landing page stacks CTA buttons on phones');
check_mobile(str_contains($sources['docs'], '.actions .btn { width: 100%; }'), 'docs page stacks CTA buttons on phones');
check_mobile(str_contains($sources['demo'], '.layout { grid-template-columns: 1fr; }'), 'demo collapses its workspace to one column');
check_mobile(str_contains($sources['demo'], '.tab') && str_contains($sources['demo'], 'min-height: 44px'), 'demo role tabs keep 44px touch targets');
check_mobile(str_contains($sources['offline'], 'width:min(100%,520px)'), 'offline page constrains its panel to the viewport');

echo "Mobile static checks passed ({$checks} checks).\n";
