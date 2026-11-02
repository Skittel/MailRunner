<?php if ($created): ?>
    <div class="card-body border-bottom bg-green-lt">
        <div class="fw-bold mb-1">Token angelegt – jetzt kopieren, er wird nicht noch einmal angezeigt:</div>
        <input class="form-control mono" readonly value="<?= esc($created['bearer'], 'attr') ?>">
    </div>
<?php endif ?>
<div class="list-group list-group-flush">
<?php foreach (array_reverse($tokens) as $t): $active = $t['revoked_at'] === null && ($t['expires_at'] === null || strtotime($t['expires_at']) > time()); ?>
    <div class="list-group-item">
        <div class="d-flex justify-content-between align-items-center">
            <div><span class="mono small"><?= esc($t['token_id']) ?></span> <?= $active ? '<span class="badge bg-green-lt">aktiv</span>' : '<span class="badge bg-secondary-lt">ungültig</span>' ?>
                <div class="small text-secondary"><?= esc($t['label']) ?> · benutzt <?= ago($t['last_used_at']) ?><?= $t['expires_at'] ? ' · bis ' . dt($t['expires_at'], 'd.m.Y') : '' ?></div></div>
            <?php if ($active && can_write()): ?><button class="btn btn-sm btn-ghost-danger" hx-post="/projects/<?= $project['public_id'] ?>/tokens/<?= esc($t['token_id'], 'attr') ?>/revoke" hx-target="#tokens"
                hx-confirm="Token <?= esc($t['token_id'], 'attr') ?> sofort sperren?">Sperren</button><?php endif ?>
        </div>
    </div>
<?php endforeach ?>
<?php if ($tokens === []): ?><div class="list-group-item text-secondary">Keine Tokens.</div><?php endif ?>
</div>
<?php if (can_write()): ?><form class="card-body" hx-post="/projects/<?= $project['public_id'] ?>/tokens" hx-target="#tokens">
    <div class="row g-2"><div class="col-7"><input name="label" class="form-control form-control-sm" placeholder="Bezeichnung" required></div>
        <div class="col-3"><input name="expires_days" type="number" min="0" class="form-control form-control-sm" placeholder="Tage"></div>
        <div class="col-2"><button class="btn btn-sm btn-primary w-100">Neu</button></div></div>
    <div class="form-hint">Leer = ohne Ablauf.</div>
</form><?php endif ?>
