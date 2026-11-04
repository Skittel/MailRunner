<?php $lastUsed ??= []; $error ??= null; $imported ??= true; $local ??= false; $webhooks ??= []; ?>
<?php if ($local): ?><div class="card-body border-bottom small text-secondary">Self-Test-Projekt: lokale Tokens der Node (install.sh), nicht zentral verwaltet.</div><?php endif ?>
<?php if ($error): ?><div class="card-body border-bottom"><div class="alert alert-warning mb-0">Übertragung an die Node: <?= esc($error) ?></div></div><?php endif ?>
<?php if ($created): ?>
    <div class="card-body border-bottom bg-green-lt">
        <div class="fw-bold mb-1">Token angelegt – jetzt kopieren, er wird nicht noch einmal angezeigt:</div>
        <div class="copy-box"><input class="form-control mono copy-src" readonly value="<?= esc($created['bearer'], 'attr') ?>"><?= view('components/copy_btn') ?></div>
        <div class="form-hint">Gilt auf allen Nodes des Projekts.</div>
    </div>
<?php endif ?>
<?php if (! $imported): ?><div class="card-body border-bottom small text-secondary">Die Tokens der Node werden bei der nächsten Abfrage übernommen.</div><?php endif ?>
<div class="list-group list-group-flush">
<?php foreach ($tokens as $t): $active = $t['revoked_at'] === null && ($t['expires_at'] === null || strtotime($t['expires_at'] . ' UTC') > time()); ?>
    <div class="list-group-item">
        <div class="d-flex justify-content-between align-items-center">
            <div><span class="mono small"><?= esc($t['token_id']) ?></span> <?= $active ? '<span class="badge bg-green-lt">aktiv</span>' : '<span class="badge bg-secondary-lt">ungültig</span>' ?>
                <?= $t['origin'] === 'imported' ? '<span class="badge bg-blue-lt" title="vor der zentralen Verwaltung auf der Node angelegt, unverändert gültig">übernommen</span>' : '' ?>
                <div class="small text-secondary"><?= esc($t['label']) ?> · benutzt <?= ago($lastUsed[$t['token_id']] ?? null) ?><?= $t['expires_at'] ? ' · bis ' . dt($t['expires_at'], 'd.m.Y') : '' ?></div></div>
            <?php if ($active && can_write() && ! $local): ?><button class="btn btn-sm btn-ghost-danger" hx-post="/projects/<?= $project['public_id'] ?>/tokens/<?= esc($t['token_id'], 'attr') ?>/revoke" hx-target="#tokens"
                hx-confirm="Token <?= esc($t['token_id'], 'attr') ?> auf allen Nodes sofort sperren?">Sperren</button><?php endif ?>
        </div>
    </div>
<?php endforeach ?>
<?php if ($tokens === []): ?><div class="list-group-item text-secondary">Keine Tokens.</div><?php endif ?>
</div>
<?php if (can_write() && ! $local): ?><form class="card-body" hx-post="/projects/<?= $project['public_id'] ?>/tokens" hx-target="#tokens">
    <div class="row g-2"><div class="col-7"><input name="label" class="form-control form-control-sm" placeholder="Bezeichnung" required></div>
        <div class="col-3"><input name="expires_days" type="number" min="0" class="form-control form-control-sm" placeholder="Tage"></div>
        <div class="col-2"><button class="btn btn-sm btn-primary w-100">Neu</button></div></div>
    <div class="form-hint">Leer = ohne Ablauf. Tokens werden zentral verwaltet und gelten auf allen Nodes des Projekts.</div>
</form><?php endif ?>
<?php foreach ($webhooks as $where => $w): ?>
    <div class="card-body border-top small">
        <div class="fw-bold">Webhook (<?= esc($where) ?>, vom Kunden per API gesetzt)</div>
        <div><span class="mono"><?= esc($w['url']) ?></span> · Abstand <?= (int) $w['cooldown_seconds'] ?> s<?= $w['pending'] ? ' · <span class="badge bg-yellow-lt">Ping wartet</span>' : '' ?></div>
        <div class="text-secondary">Letzter Ping: <?= $w['last_attempt_at'] ? ago($w['last_attempt_at']) . ' · ' . ($w['last_status'] === null ? 'läuft' : ($w['last_status'] === 'sent'
            ? '<span class="text-green">HTTP ' . (int) $w['last_http_code'] . '</span>'
            : '<span class="text-red">fehlgeschlagen: ' . esc(trim(($w['last_http_code'] ? 'HTTP ' . (int) $w['last_http_code'] . ' ' : '') . ($w['last_error'] ?? ''))) . '</span>')) : 'noch keiner' ?></div>
    </div>
<?php endforeach ?>
