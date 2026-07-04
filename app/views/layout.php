<?php
declare(strict_types=1);

function render_state_box(string $title, string $body, array $actions = [], string $tone = 'empty'): string {
    $classes = 'state-box state-' . preg_replace('/[^a-z0-9_-]/i', '', $tone);
    $html = '<div class="' . h($classes) . '">'
        . '<div class="empty-title">' . h($title) . '</div>'
        . '<div class="empty-body">' . h($body) . '</div>';
    if ($actions) {
        $html .= '<div class="state-actions">';
        foreach ($actions as $action) {
            if (!is_array($action)) continue;
            $label = (string)($action['label'] ?? '');
            $href = (string)($action['href'] ?? '');
            if ($label === '' || $href === '') continue;
            $primary = !empty($action['primary']) ? ' btn-primary' : '';
            $html .= '<a class="btn' . $primary . '" href="' . h($href) . '">' . h($label) . '</a>';
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}

function user_initials(string $name): string {
    $clean = trim(preg_replace('/[^A-Za-z0-9 ]+/', ' ', $name) ?? '');
    if ($clean === '') return 'U';
    $parts = preg_split('/\s+/', $clean) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        if ($part === '') continue;
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) break;
    }
    return $initials !== '' ? $initials : 'U';
}

function rating_text(?float $avg, int $count): string {
    return $count > 0 && $avg !== null
        ? number_format($avg, 1) . '/5 from ' . $count . ' rating' . ($count === 1 ? '' : 's')
        : t('ratings.none', 'No ratings yet');
}

function render_user_chip(int $id, string $name, ?float $ratingAvg = null, int $ratingCount = 0, string $label = ''): string {
    $labelHtml = $label !== '' ? '<span class="user-role">' . h($label) . '</span>' : '';
    return '<a class="user-chip" href="?action=profile&id=' . (int)$id . '">'
        . '<span class="avatar" aria-hidden="true">' . h(user_initials($name)) . '</span>'
        . '<span class="user-stack">' . $labelHtml
        . '<span class="user-name">' . h($name) . '</span>'
        . '<span class="user-rating">' . h(rating_text($ratingAvg, $ratingCount)) . '</span>'
        . '</span></a>';
}

function render_design_tokens_css(string $accent): string {
    $fallback = ':root{--accent:#0f7a52;--accent-ink:#0c6042;--accent-soft:#eaf4ef;--bg:#f7f8f9;--panel:#ffffff;--panel-2:#f6f7f9;--soft:#f2f4f5;--fg:#14171c;--ink:#0b0d12;--muted:#6b7280;--faint:#9aa1ac;--line:#ececef;--line-2:#e0e2e7;--ok:#0f7a52;--warn:#9a5b00;--danger:#c0362c;--focus:#0b0d12;--shadow-sm:0 1px 2px rgba(11,13,18,.05),0 1px 3px rgba(11,13,18,.05);--shadow:0 14px 36px rgba(11,13,18,.10),0 3px 8px rgba(11,13,18,.05);--shadow-lg:0 30px 80px rgba(11,13,18,.26);--sans:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;--mono:ui-monospace,SFMono-Regular,"JetBrains Mono",Menlo,Consolas,monospace;}';
    $path = LITEGIG_ROOT . DIRECTORY_SEPARATOR . 'styles' . DIRECTORY_SEPARATOR . 'tokens.css';
    $css = is_file($path) ? (string)file_get_contents($path) : $fallback;
    $safeAccent = preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : '#0f7a52';
    $updated = preg_replace('/--accent:\s*#[0-9a-fA-F]{6}\s*;/', '--accent: ' . $safeAccent . ';', $css, 1);
    return $updated ?: $css;
}

function render_layout(string $title, string $content): void {
    global $CFG;
    $u = current_user();
    $flashes = flash_get_all();

    $accent = (string)$CFG['accentColor'];
    $appName = (string)$CFG['app_name'];
    $isAdmin = $u && ((int)$u['is_admin'] === 1);

    $nav = '';
    if ($u) {
        $nav .= '<a class="navlink" href="?action=list_requests">' . h(t('nav.requests', 'Requests')) . '</a>';
        $nav .= '<a class="navlink" href="?action=open_pool">' . h(t('nav.open_pool', 'Open Pool')) . '</a>';
        $nav .= '<a class="navlink" href="?action=runner_sheet">' . h(t('nav.job_sheet', 'Job Sheet')) . '</a>';
        $nav .= '<a class="navlink" href="?action=payments">' . h(t('nav.payments', 'Payments')) . '</a>';
        $nav .= '<a class="navlink" href="?action=create_request">' . h(t('nav.create', 'Create')) . '</a>';
        $nav .= '<a class="navlink" href="?action=profile&id=' . (int)$u['id'] . '">' . h(t('nav.profile', 'Profile')) . '</a>';
        if ($isAdmin) {
            $nav .= '<a class="navlink" href="?action=admin_console">' . h(t('nav.admin', 'Admin')) . '</a>';
            $nav .= '<a class="navlink" href="?action=reports">' . h(t('nav.reports', 'Reports')) . '</a>';
            $nav .= '<a class="navlink" href="?action=list_task_types">' . h(t('nav.task_types', 'Task Types')) . '</a>';
            $nav .= '<a class="navlink" href="?action=export_csv">' . h(t('nav.export', 'Export')) . '</a>';
            if (!empty($CFG['sample_data_enabled'])) {
                $nav .= '<a class="navlink" href="?action=load_sample_data">' . h(t('nav.load_sample', 'Load Sample')) . '</a>';
            }
        }
        $nav .= '<form class="navform" method="post" action="?action=logout">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<button class="navlink navbutton" type="submit">' . h(t('nav.logout', 'Logout')) . '</button>'
            . '</form>';
    } else {
        $nav .= '<a class="navlink" href="?action=login">' . h(t('nav.login', 'Login')) . '</a>';
        $nav .= '<a class="navlink" href="?action=register">' . h(t('nav.register', 'Register')) . '</a>';
    }

    $meta = '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    $favicon = '<link rel="icon" href="brand/favicon.svg">'
        . '<link rel="apple-touch-icon" href="brand/apple-touch-icon.png">'
        . '<link rel="manifest" href="manifest.webmanifest">'
        . '<meta name="theme-color" content="#0b0d12">';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">' . $meta . '<title>' . h($title) . ' · ' . h($appName) . '</title>' . $favicon . '<script src="litegig-pwa.js" defer></script>';
    echo '<style>';
    echo render_design_tokens_css($accent)
        . '*{box-sizing:border-box;}'
        . 'html,body{margin:0;padding:0;overflow-x:hidden;background:var(--bg);color:var(--fg);font-family:var(--sans);-webkit-text-size-adjust:100%;}'
        . 'body{font-size:16px;line-height:1.45;}'
        . 'a{color:var(--accent);text-decoration:none;text-underline-offset:3px;}a:hover{text-decoration:underline;}'
        . 'img,svg,video,canvas{max-width:100%;height:auto;}'
        . 'a,button,input,select,textarea{-webkit-tap-highlight-color:transparent;}'
        . ':where(a,button,input,select,textarea):focus-visible{outline:3px solid rgba(15,122,82,.38);outline-offset:2px;}'
        . '.skip-link{position:absolute;left:12px;top:-80px;z-index:1000;background:var(--fg);color:#fff;padding:10px 14px;font-weight:700;}'
        . '.skip-link:focus{top:12px;}'
        . '.wrap{width:min(100%,920px);margin:0 auto;padding:0 14px 44px;}'
        . '.top{position:sticky;top:0;z-index:20;display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 -14px 14px;padding:10px 14px 12px;border-bottom:1px solid var(--line);background:rgba(247,248,249,.94);backdrop-filter:blur(14px);}'
        . '.brand{font-weight:700;letter-spacing:0;font-size:17px;}.brand a{display:inline-flex;align-items:center;gap:9px;color:var(--ink);min-height:44px;}.brand a:hover{text-decoration:none;}.brandmark{display:inline-grid;place-items:center;width:28px;height:28px;}.brandmark svg{display:block;}'
        . '.nav{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;max-width:100%;}'
        . '.navform{display:flex;margin:0;min-width:0;}'
        . '.navlink{display:inline-flex;align-items:center;justify-content:center;min-height:44px;max-width:100%;padding:0 12px;border:1px solid var(--line);border-radius:0;color:var(--fg);font-weight:600;font-size:13px;line-height:1.15;text-align:center;white-space:normal;background:var(--panel);box-shadow:var(--shadow-sm);}'
        . '.navbutton{font:inherit;cursor:pointer;}'
        . '.navlink:hover{border-color:var(--line-2);background:var(--panel-2);text-decoration:none;}'
        . '.navlink:active,.btn:active{transform:translateY(1px);}'
        . '.card{border:1px solid var(--line);background:var(--panel);border-radius:0;padding:18px;margin:14px 0;box-shadow:var(--shadow-sm);}'
        . '.row{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;min-width:0;}'
        . '.row>*,.itemtop>*,.stack>*{min-width:0;}'
        . '.stack{display:flex;flex-direction:column;gap:10px;min-width:0;}'
        . '.title{font-size:18px;font-weight:700;letter-spacing:0;color:var(--ink);line-height:1.2;}'
        . '.sub{color:var(--muted);font-size:13px;line-height:1.45;}'
        . '.badge,.pill{display:inline-flex;align-items:center;gap:6px;max-width:100%;min-height:28px;padding:4px 9px;border:1px solid var(--line);border-radius:0;background:var(--panel-2);font-size:11px;font-weight:700;letter-spacing:0;text-transform:uppercase;line-height:1.2;white-space:normal;overflow-wrap:anywhere;}'
        . '.badge{background:var(--panel);color:var(--fg);}'
        . '.pill{color:var(--ink);font-family:var(--mono);font-variant-numeric:tabular-nums;}'
        . '.status-chip{border-color:var(--line-2);background:var(--soft);}'
        . '.schedule-overdue{border-color:rgba(192,54,44,.26);background:#fff7f6;color:#7a1e18;}'
        . '.schedule-soon{border-color:rgba(154,91,0,.24);background:#fffaf1;color:#5d3600;}'
        . '.schedule-scheduled{border-color:rgba(15,122,82,.24);background:var(--accent-soft);color:var(--accent-ink);}'
        . '.user-chip{display:inline-flex;align-items:center;gap:10px;min-height:44px;max-width:100%;padding:6px 8px 6px 6px;border:1px solid var(--line);background:var(--panel);color:var(--fg);box-shadow:var(--shadow-sm);}'
        . '.user-chip:hover{border-color:var(--line-2);background:var(--panel-2);text-decoration:none;}'
        . '.avatar{display:inline-grid;place-items:center;flex:0 0 34px;width:34px;height:34px;border:1px solid var(--line-2);background:var(--soft);color:var(--ink);font-family:var(--mono);font-size:12px;font-weight:700;}'
        . '.user-stack{display:flex;min-width:0;flex-direction:column;gap:1px;line-height:1.2;}'
        . '.user-role{font-size:10px;color:var(--muted);font-weight:700;text-transform:uppercase;}'
        . '.user-name{font-size:13px;font-weight:700;color:var(--ink);overflow-wrap:anywhere;}'
        . '.user-rating{font-size:12px;color:var(--muted);overflow-wrap:anywhere;}'
        . '.user-strip{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;}'
        . '.next-action{border:1px solid var(--line);background:var(--panel-2);padding:14px;margin-top:10px;box-shadow:var(--shadow-sm);}'
        . '.next-action strong{display:block;color:var(--ink);font-size:14px;line-height:1.3;}'
        . '.next-action span{display:block;margin-top:4px;color:var(--muted);font-size:13px;line-height:1.45;}'
        . '.action-card{border:1px solid var(--line);background:var(--panel);padding:12px;box-shadow:var(--shadow-sm);}'
        . '.action-card + .action-card{margin-top:10px;}'
        . '.attachment-preview{display:flex;align-items:center;gap:10px;max-width:100%;margin-top:8px;padding:8px;border:1px solid var(--line);background:var(--panel-2);color:var(--fg);box-shadow:var(--shadow-sm);}'
        . '.attachment-preview:hover{text-decoration:none;background:var(--panel);border-color:var(--line-2);}'
        . '.attachment-preview img{display:block;width:74px;height:56px;object-fit:cover;border:1px solid var(--line);background:var(--soft);}'
        . '.attachment-preview span{min-width:0;overflow-wrap:anywhere;font-size:13px;color:var(--fg);}'
        . '.thread{display:flex;flex-direction:column;gap:10px;margin-top:10px;}'
        . '.thread-item{border:1px solid var(--line);background:var(--panel);padding:14px;box-shadow:var(--shadow-sm);}'
        . '.thread-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;}'
        . '.profile-head{display:flex;align-items:flex-start;gap:14px;}'
        . '.profile-head .avatar{width:54px;height:54px;flex-basis:54px;font-size:17px;}'
        . '.profile-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px;}'
        . '.metric{border:1px solid var(--line);background:var(--panel);padding:12px;box-shadow:var(--shadow-sm);}'
        . '.metric strong{display:block;color:var(--ink);font-family:var(--mono);font-size:18px;line-height:1.1;}'
        . '.metric span{display:block;margin-top:5px;color:var(--muted);font-size:12px;line-height:1.35;}'
        . '.price,.mono,code,kbd,.statepoint small{font-family:var(--mono);font-variant-numeric:tabular-nums;}'
        . '.price{font-weight:700;font-size:17px;color:var(--ink);white-space:nowrap;}'
        . '.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;max-width:100%;padding:11px 16px;border-radius:0;border:1px solid var(--line-2);background:var(--panel);color:var(--ink);font-weight:600;font-size:14px;line-height:1.15;text-align:center;white-space:normal;cursor:pointer;transition:background .15s ease,border-color .15s ease,box-shadow .15s ease,color .15s ease;}'
        . '.btn:hover{border-color:var(--ink);background:var(--panel-2);text-decoration:none;}'
        . '.btn[aria-busy="true"]{opacity:.72;cursor:progress;}'
        . '.btn-primary{background:var(--fg);border-color:var(--fg);color:#fff;box-shadow:var(--shadow-sm);}'
        . '.btn-primary:hover{background:var(--ink);border-color:var(--ink);color:#fff;}'
        . '.btn-danger{background:var(--danger);border-color:var(--danger);color:#fff;}'
        . '.btn-danger:hover{background:#a62d25;border-color:#a62d25;color:#fff;}'
        . '.btn:disabled,.btn.disabled,.btn[aria-disabled="true"]{opacity:.6;cursor:not-allowed;pointer-events:none;}'
        . '.btnblock{width:100%;}'
        . '.grid{display:grid;grid-template-columns:1fr;gap:12px;}'
        . 'label{display:block;font-weight:600;font-size:11px;letter-spacing:0;text-transform:uppercase;color:var(--muted);margin:0 0 7px;}'
        . 'input,select,textarea{width:100%;max-width:100%;min-width:0;min-height:44px;box-sizing:border-box;border:1px solid var(--line-2);border-radius:0;padding:12px 12px;font-size:16px;background:var(--panel);color:var(--fg);font:inherit;}'
        . 'textarea{min-height:104px;resize:vertical;}'
        . 'input[type="checkbox"]{width:18px;height:18px;min-height:18px;padding:0;accent-color:var(--accent);}'
        . 'input:focus,select:focus,textarea:focus{border-color:var(--accent);}'
        . 'code{padding:2px 4px;background:var(--soft);border:1px solid var(--line);font-size:.92em;white-space:normal;overflow-wrap:anywhere;}'
        . 'pre{max-width:100%;white-space:pre-wrap;overflow-wrap:anywhere;}'
        . '.checkline{display:flex;gap:10px;align-items:center;min-height:44px;color:var(--fg);font-size:14px;font-weight:600;letter-spacing:0;text-transform:none;margin:0;}'
        . '.help{color:var(--muted);font-size:12px;margin-top:6px;line-height:1.4;}'
        . '.err{color:var(--danger);font-size:12px;margin-top:6px;}'
        . '.ok{color:var(--ok);font-size:12px;margin-top:6px;}'
        . '.list{display:flex;flex-direction:column;gap:10px;}'
        . '.item{border:1px solid var(--line);border-radius:0;padding:16px;background:var(--panel);box-shadow:var(--shadow-sm);}'
        . '.itemtop{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;min-width:0;}'
        . '.itemtitle{font-weight:700;font-size:16px;margin:0 0 4px;line-height:1.3;color:var(--ink);}'
        . '.itemmeta{color:var(--muted);font-size:12px;line-height:1.45;}'
        . '.table{width:100%;border-collapse:collapse;}'
        . '.table td{padding:11px 0;border-bottom:1px solid var(--line);vertical-align:top;overflow-wrap:anywhere;}'
        . '.table tr:last-child td{border-bottom:0;}'
        . '.table td:first-child{color:var(--muted);width:38%;padding-right:12px;font-size:11px;font-weight:600;letter-spacing:0;text-transform:uppercase;}'
        . '.foot{margin-top:16px;color:var(--muted);font-size:12px;line-height:1.45;}'
        . '.flash{border-radius:0;padding:12px 14px;margin:10px 0;border:1px solid var(--line);background:var(--panel);box-shadow:var(--shadow-sm);font-size:14px;}'
        . '.flash.error{border-color:rgba(192,54,44,.26);background:#fff7f6;color:#7a1e18;}'
        . '.flash.ok{border-color:rgba(15,122,82,.24);background:var(--accent-soft);color:var(--accent-ink);}'
        . '.flash.warn{border-color:rgba(154,91,0,.24);background:#fffaf1;color:var(--warn);}'
        . '.split{display:grid;grid-template-columns:1fr;gap:10px;}'
        . '.statepath{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:6px;margin-top:12px;}'
        . '.statepoint{border:1px solid var(--line);border-radius:0;background:var(--panel);min-height:58px;padding:9px;box-shadow:var(--shadow-sm);}'
        . '.statepoint.done{border-color:rgba(15,122,82,.24);background:var(--accent-soft);}'
        . '.statepoint.current{border-color:var(--accent);box-shadow:inset 0 0 0 1px var(--accent),var(--shadow-sm);}'
        . '.statepoint small{display:block;color:var(--muted);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0;}'
        . '.statepoint span{display:block;margin-top:4px;font-size:12px;font-weight:700;color:var(--ink);line-height:1.25;}'
        . '.empty,.state-box{border:1px solid var(--line);background:var(--panel);box-shadow:var(--shadow-sm);padding:18px;}'
        . '.state-error{border-color:rgba(192,54,44,.26);background:#fff7f6;}'
        . '.state-warn{border-color:rgba(154,91,0,.24);background:#fffaf1;}'
        . '.empty-title{font-weight:700;color:var(--ink);letter-spacing:0;}'
        . '.empty-body{margin-top:4px;color:var(--muted);font-size:13px;line-height:1.45;}'
        . '.state-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px;}'
        . '.state-actions .btn{flex:1 1 180px;}'
        . '.inline-state{display:none;margin-top:8px;padding:10px 12px;border:1px solid var(--line);background:var(--panel-2);font-size:12px;color:var(--muted);line-height:1.4;}'
        . '.inline-state.is-visible{display:block;}'
        . '.inline-state.is-error{border-color:rgba(192,54,44,.26);background:#fff7f6;color:#7a1e18;}'
        . '.inline-state.is-ok{border-color:rgba(15,122,82,.24);background:var(--accent-soft);color:var(--accent-ink);}'
        . '.offline-banner{margin:10px 0;padding:10px 12px;border:1px solid rgba(154,91,0,.24);background:#fffaf1;color:#5d3600;font-size:13px;box-shadow:var(--shadow-sm);}'
        . '[hidden]{display:none!important;}'
        . '.request-main{min-width:0;}'
        . '.longtext{white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word;}'
        . '.price-actions{align-items:flex-end;min-width:120px;}'
        . '.price-actions form{width:100%;}'
        . '.detail-price,.status-block{text-align:right;}'
        . '.item-actions{min-width:140px;}'
        . '.sticky-actions{position:sticky;bottom:0;margin:0 -18px -18px;padding:12px 18px calc(12px + env(safe-area-inset-bottom));background:linear-gradient(180deg,rgba(255,255,255,0),var(--panel) 22%);border-top:1px solid var(--line);}'
        . '.sticky-actions form,.sticky-actions .btn{width:100%;}'
        . '@media(min-width:720px){.grid{grid-template-columns:1fr 1fr;}.split{grid-template-columns:1fr 1fr;}.sticky-actions{position:static;margin:10px 0 0;padding:0;background:transparent;border-top:0;}}'
        . '@media(max-width:720px){.statepath{grid-template-columns:1fr;}}'
        . '@media(max-width:640px){.wrap{padding-left:12px;padding-right:12px;padding-bottom:72px;}.top{align-items:flex-start;flex-direction:column;margin-left:-12px;margin-right:-12px;padding-left:12px;padding-right:12px;}.nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));width:100%;justify-content:stretch;}.navform{display:block;width:100%;}.navlink{width:100%;padding-left:8px;padding-right:8px;}.card{padding:15px;margin:12px 0;}.row,.itemtop{flex-direction:column;}.row>div,.itemtop>div{width:100%;}.itemtop .stack,.price-actions,.item-actions{align-items:stretch!important;min-width:0!important;width:100%;}.detail-price,.status-block{text-align:left;}.state-actions{flex-direction:column;}.state-actions .btn{width:100%;}.sticky-actions{margin:0 -15px -15px;padding:12px 15px calc(12px + env(safe-area-inset-bottom));}.table,.table tbody,.table tr,.table td{display:block;width:100%;}.table td{padding:8px 0;}.table td:first-child{width:auto;padding:10px 0 0;}.profile-grid{grid-template-columns:1fr 1fr;}.price{font-size:16px;white-space:normal;}.brand a{min-height:44px;}}'
        . '</style>';
    echo '</head><body><div class="wrap">';
    echo '<a class="skip-link" href="#main">Skip to content</a>';
    echo '<div class="top"><div class="brand"><a href="?"><span class="brandmark" aria-hidden="true"><svg viewBox="0 0 32 32" width="24" height="24"><rect x="6" y="6" width="4.2" height="20" fill="#0b0d12"></rect><rect x="6" y="21.8" width="20" height="4.2" fill="#0b0d12"></rect><rect x="17.8" y="6" width="8.2" height="8.2" fill="#0f7a52"></rect></svg></span><span>' . h($appName) . '</span></a></div><div class="nav">' . $nav . '</div></div>';
    echo '<div class="offline-banner" id="offline_banner" role="status" hidden>You are offline. Runner pickup and delivery actions from the job sheet can be queued on this device.</div>';

    foreach ($flashes as $f) {
        $cls = ($f['type'] === 'error') ? 'error' : 'ok';
        $role = $cls === 'error' ? 'alert' : 'status';
        echo '<div class="flash ' . $cls . '" role="' . $role . '">' . h($f['msg']) . '</div>';
    }

    echo '<main id="main" tabindex="-1">';
    echo $content;
    echo '</main>';
    echo '<div class="foot">Manual payment is default. Optional gateway adapters store references and signed webhook results only; LiteGig does not store card details. <a href="docs.html#security">Security</a> · <a href="docs.html">Docs</a></div>';
    echo '<script>(function(){let labelSeq=0;function labelControls(root){root.querySelectorAll("label").forEach(function(label){if(label.control)return;const control=label.nextElementSibling;if(!control||!control.matches("input:not([type=hidden]),select,textarea"))return;if(!control.id)control.id="lg-control-"+(++labelSeq);label.htmlFor=control.id;});}labelControls(document);const main=document.getElementById("main");if(main&&window.MutationObserver){new MutationObserver(function(records){records.forEach(function(record){record.addedNodes.forEach(function(node){if(node.nodeType===1)labelControls(node);});});}).observe(main,{childList:true,subtree:true});}const banner=document.getElementById("offline_banner");function syncOffline(){if(!banner)return;const off=!navigator.onLine;banner.hidden=!off;document.documentElement.classList.toggle("is-offline",off);}window.addEventListener("online",syncOffline);window.addEventListener("offline",syncOffline);syncOffline();document.addEventListener("submit",function(ev){const form=ev.target;if(!(form instanceof HTMLFormElement)||form.dataset.noLoading==="1")return;const btn=form.querySelector("button[type=submit],input[type=submit]");if(!btn)return;requestAnimationFrame(function(){if(ev.defaultPrevented)return;if(btn.tagName==="BUTTON"&&!btn.dataset.originalText){btn.dataset.originalText=btn.textContent||"";btn.textContent="Working...";}btn.setAttribute("aria-busy","true");btn.disabled=true;});});})();</script>';
    echo '</div></body></html>';
}

function render_auth_gate(): void {
    $html = '<div class="card"><div class="title">LiteGig</div>'
        . '<div class="sub">Minimal, schema-driven, on-demand gigs. Create tasks, accept gigs, confirm pickup/payment/delivery, and rate each other.</div>'
        . '<div style="margin-top:12px" class="grid">'
        . '<a class="btn btn-primary btnblock" href="?action=register">Create account</a>'
        . '<a class="btn btnblock" href="?action=login">Login</a>'
        . '</div></div>';
    render_layout('Welcome', $html);
}
