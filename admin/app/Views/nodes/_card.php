<?php $s = $n['status']; $st = $s['selftest'] ?? null; $h = $n['health']; ?>
<div class="card" id="node-<?= $n['id'] ?>">
    <div class="card-status-top <?= ['ok' => 'bg-green', 'warn' => 'bg-yellow', 'fail' => 'bg-red'][$h['level']] ?? 'bg-secondary' ?>"></div>
    <div class="card-header">
        <h3 class="card-title"><?= status_dot($h['level']) ?><a href="/nodes/<?= $n['id'] ?>" class="text-reset"><?= esc($n['name']) ?></a>
            <span class="text-secondary small ms-2 mono"><?= esc($n['node_key']) ?></span></h3>
        <div class="card-actions">
<?php if (can_write()): ?>
            <button class="btn btn-sm btn-ghost-secondary" hx-post="/nodes/<?= $n['id'] ?>/poll<?= $detail ? '?detail=1' : '' ?>" hx-target="#node-<?= $n['id'] ?>" hx-swap="outerHTML">
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
