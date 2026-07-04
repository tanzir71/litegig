<?php
declare(strict_types=1);

function require_cron_authorized(string $description): void {
    global $CFG;

    $token = input_string($_GET, 'token', 200);
    $authorized = false;
    if (PHP_SAPI === 'cli') {
        $authorized = true;
    } elseif (!empty($CFG['http_cron_enabled']) && $CFG['cron_token'] !== '' && hash_equals((string)$CFG['cron_token'], $token)) {
        $authorized = true;
    }
    if (!$authorized) {
        http_response_code(403);
        if (PHP_SAPI !== 'cli') {
            render_forbidden('Cron token required', $description . ' can only run from CLI or with the configured cron token.');
        }
        echo "Forbidden";
        exit;
    }
}

function action_cron_cleanup(): void {
    global $CFG;

    require_cron_authorized('Cleanup');
    $pdo = db();
    $days = (int)$CFG['cleanup_stale_new_days'];
    $cutoffTs = time() - (60 * 60 * 24 * $days);
    $cutoffIso = gmdate('Y-m-d\TH:i:s\Z', $cutoffTs);

    // Shared-host friendly: process in small chunks.
    $pdo->beginTransaction();
    try {
        $sel = $pdo->prepare("SELECT id FROM requests WHERE " . request_transition_guard_sql('expire') . " AND created_at < ? ORDER BY created_at ASC LIMIT 200");
        $sel->execute([$cutoffIso]);
        $ids = array_map(fn($r) => (int)$r['id'], $sel->fetchAll());
        $upd = $pdo->prepare("UPDATE requests SET status='expired', updated_at=? WHERE id=? AND " . request_transition_guard_sql('expire'));
        $now = now_iso();
        $changed = 0;
        foreach ($ids as $id) {
            $upd->execute([$now, $id]);
            if ($upd->rowCount() > 0) {
                add_event($id, null, 'expired', 'Auto-expired stale request');
                $changed++;
            }
        }
        audit_log(null, 'cron_cleanup', 'system', null, ['expired' => $changed, 'cutoff' => $cutoffIso]);
        $pdo->commit();
        $notifications = process_queued_notifications(50);
        audit_log(null, 'cron_notifications', 'system', null, $notifications);
        echo "OK expired={$changed} cutoff={$cutoffIso} notifications_processed={$notifications['processed']} sent={$notifications['sent']} logged={$notifications['logged']} retry={$notifications['retry']} failed={$notifications['failed']}\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo "ERROR\n";
    }
    exit;
}

function action_cron_notifications(): void {
    require_cron_authorized('Notifications');
    $summary = process_queued_notifications(100);
    audit_log(null, 'cron_notifications', 'system', null, $summary);
    echo "OK notifications_processed={$summary['processed']} sent={$summary['sent']} logged={$summary['logged']} skipped={$summary['skipped']} retry={$summary['retry']} failed={$summary['failed']}\n";
    exit;
}
