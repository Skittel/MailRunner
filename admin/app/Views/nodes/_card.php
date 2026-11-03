<?php $s = $n['status']; $st = $s['selftest'] ?? null; $h = $n['health']; ?>
<div class="card" id="node-<?= $n['public_id'] ?>">
    <div class="card-status-top <?= ['ok' => 'bg-green', 'warn' => 'bg-yellow', 'fail' => 'bg-red'][$h['level']] ?? 'bg-secondary' ?>"></div>
    <div class="card-header">
        <h3 class="card-title"><?= status_dot($h['level']) ?><a href="/nodes/<?= $n['public_id'] ?>" class="text-reset"><?= esc($n['name']) ?></a>
            <span class="text-secondary small ms-2 mono"><?= esc($n['node_key']) ?></span></h3>
        <div class="card-actions">
<?php if (can_write()): ?>
            <button class="btn btn-sm btn-ghost-secondary" hx-post="/nodes/<?= $n['public_id'] ?>/poll<?= $detail ? '?detail=1' : '' ?>" hx-target="#node-<?= $n['public_id'] ?>" hx-swap="outerHTML">
                <span class="htmx-indicator spinner-border spinner-border-sm me-1"></span>Aktualisieren</button>
            <?php endif ?>
        </div>
    </div>
    <div class="card-body">
        <div class="mb-2"><?= health_badge($h['level']) ?>
            <?php if (! empty($s['version'])): ?><span class="badge bg-blue-lt ms-1">v<?= esc($s['version']) ?></span><?php endif ?>
            <?php foreach ($s['roles'] ?? [] as $r): ?><span class="badge bg-azure-lt ms-1"><?= esc($r) ?></span><?php endforeach ?></div>
        <?php if ($h['reasons'] !== []): ?><ul class="small text-secondary ps-3 mb-2"><?php foreach ($h['reasons'] as $r): ?><li><?= esc($r) ?></li><?php endforeach ?></ul><?php endif ?>
        <dl class="row kv small mb-0">
            <dt class="col-5">Letzter Selbsttest</dt>
            <dd class="col-7"><?php if ($st): ?><span class="<?= $st['status'] === 'pass' ? 'text-success' : 'text-danger' ?> fw-bold"><?= esc($st['status']) ?></span>
                · <?= (int) ($st['ok'] ?? 0) ?> ok · <?= ago($st['finished_at'] ?? null) ?><?php else: ?>–<?php endif ?></dd>
            <dt class="col-5">Mails 24 h</dt><dd class="col-7"><?= (int) ($s['counts']['messages_24h'] ?? 0) ?> · SMTP-Sitzungen <?= (int) ($s['counts']['smtp_sessions_24h'] ?? 0) ?></dd>
            <dt class="col-5">Nicht abgeholt</dt><dd class="col-7"><?= (int) ($s['counts']['messages_unacked'] ?? 0) ?> (davon postmaster/abuse <?= (int) ($s['counts']['role_mails_unacked'] ?? 0) ?>)</dd>
            <dt class="col-5">Admin darf</dt><dd class="col-7"><?php $pol = $s['policy'] ?? []; ?>
                Updates <?= ($pol['updates'] ?? 'auto') === 'manual' ? '<span class="badge bg-orange-lt">manuell</span>' : '<span class="badge bg-green-lt">auto</span>' ?>
                · Konfiguration <?= ($pol['config'] ?? 'auto') === 'manual' ? '<span class="badge bg-orange-lt">manuell</span>' : '<span class="badge bg-green-lt">auto</span>' ?></dd>
            <?php if (! empty($s['pending_changes'])): ?><dt class="col-5">Wartet auf Freigabe</dt><dd class="col-7 text-warning"><?= (int) $s['pending_changes'] ?> Änderung(en) · <code>sudo mailrunner mr:config:apply</code></dd><?php endif ?>
            <?php if (! empty($s['update_requested']) && version_compare((string) $s['update_requested'], (string) ($s['version'] ?? '0'), '>')): ?><dt class="col-5">Update</dt><dd class="col-7 text-warning">v<?= esc($s['update_requested']) ?> angefordert<?= ($pol['updates'] ?? 'auto') === 'manual' ? ' · an der Node: <code>sudo mailrunner mr:update</code>' : '' ?></dd><?php endif ?>
            <?php $sec = $s['secrets'] ?? null; if (is_array($sec) && (! empty($sec['push_error']) || ! empty($sec['local_overrides']))): ?><dt class="col-5">Schlüssel</dt><dd class="col-7">
                <?php if (! empty($sec['push_error'])): ?><span class="text-danger">Verteilung fehlgeschlagen</span><?php endif ?>
                <?php if (! empty($sec['local_overrides'])): ?><span class="text-secondary">lokal überschrieben: <span class="mono"><?= esc(implode(', ', $sec['local_overrides'])) ?></span></span><?php endif ?></dd><?php endif ?>
            <?php $lu = $s['last_update'] ?? null; if (is_array($lu) && $lu['result'] !== 'updated' && version_compare((string) ($lu['to'] ?? $lu['version'] ?? '0'), (string) ($s['version'] ?? '0'), '>')): ?>
                <dt class="col-5">Letztes Update</dt><dd class="col-7 text-danger">v<?= esc($lu['to'] ?? $lu['version'] ?? '?') ?> <?= $lu['result'] === 'update_failed' ? 'fehlgeschlagen (exit ' . (int) ($lu['exit'] ?? 0) . ')' : 'abgelehnt' ?> · <?= ago((string) $lu['at']) ?>
                <?php foreach (array_slice($lu['output_tail'] ?? $lu['problems'] ?? [], -3) as $l): ?><div class="mono small text-secondary text-truncate" title="<?= esc($l, 'attr') ?>"><?= esc($l) ?></div><?php endforeach ?></dd>
            <?php endif ?>
            <dt class="col-5">Abgefragt</dt><dd class="col-7"><?= ago($n['last_checked_at']) ?></dd>
        </dl>
        <?php if ($detail && $st): ?>
            <hr class="my-2">
            <?php foreach (['failed' => 'text-danger', 'warnings' => 'text-warning'] as $k => $cls): foreach ($st[$k] ?? [] as $c): ?>
                <div class="small <?= $cls ?>"><span class="mono"><?= esc($c['name']) ?></span> – <?= esc($c['detail']) ?></div>
            <?php endforeach; endforeach ?>
            <div class="small text-secondary mt-1">Dauer <?= round(($st['duration_ms'] ?? 0) / 1000, 1) ?> s · Version <?= esc($st['version'] ?? '?') ?> · <?= dt($st['finished_at'] ?? null, 'd.m.Y H:i:s') ?></div>
        <?php endif ?>
    </div>
</div>
