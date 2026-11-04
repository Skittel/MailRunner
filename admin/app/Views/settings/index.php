<?= $this->extend('layouts/main') ?>
<?php $this->setVar('title', 'Einstellungen') ?>
<?= $this->section('content') ?>
<div class="row row-cards">
<div class="col-lg-7">
<form method="post" action="/settings/secrets" class="card" autocomplete="off">
    <?= csrf_field() ?>
    <div class="card-header"><h3 class="card-title">Gemeinsame Schlüssel für alle Nodes</h3></div>
    <div class="card-body">
        <p class="text-secondary small">Werte werden verschlüsselt gespeichert; angezeigt werden nur die ersten und letzten zwei Zeichen. Leeres Feld = unverändert.
            Die Nodes erhalten sie in <span class="mono">/var/lib/mailrunner/secrets/common.env</span> (nicht in der secrets.env).
            Ein Wert in <span class="mono">/etc/mailrunner/secrets.env</span> einer Node hat dort Vorrang.</p>
        <?php foreach ($keys as $k => [$label, $hint]): $s = $secrets[$k]; ?>
        <div class="mb-3">
            <label class="form-label d-flex align-items-center"><?= esc($label) ?> <span class="mono small text-secondary ms-2"><?= esc($k) ?></span>
                <?= $s['set'] ? '<span class="badge bg-green-lt ms-auto">gesetzt</span>' : '<span class="badge bg-secondary-lt ms-auto">nicht gesetzt</span>' ?></label>
            <input type="password" name="value[<?= esc($k, 'attr') ?>]" class="form-control mono" autocomplete="new-password" <?= can_write() ? '' : 'disabled' ?>
                placeholder="<?= $s['set'] ? esc('gespeichert: ' . ($hints[$k] ?? '?') . ' – neuen Wert eingeben zum Ersetzen', 'attr') : 'Wert eingeben' ?>">
            <div class="form-hint d-flex"><span><?= esc($hint) ?><?= $s['set'] ? ' · geändert ' . dt($s['updated_at']) . ($s['updated_by'] ? ' von ' . esc($s['updated_by']) : '') : '' ?></span>
                <?php if ($s['set'] && can_write()): ?><label class="form-check form-check-inline ms-auto mb-0"><input class="form-check-input" type="checkbox" name="delete[<?= esc($k, 'attr') ?>]" value="1"><span class="form-check-label">löschen</span></label><?php endif ?></div>
        </div>
        <?php endforeach ?>
    </div>
    <?php if (can_write()): ?><div class="card-footer text-end"><button class="btn btn-primary">Speichern und verteilen</button></div><?php endif ?>
</form>
</div>
<div class="col-lg-5">
<div class="card">
    <div class="card-header"><h3 class="card-title">Stand auf den Nodes</h3><?php if ($revision): ?><span class="ms-auto mono small text-secondary" title="Revision">rev <?= esc($revision) ?></span><?php endif ?></div>
    <div class="list-group list-group-flush">
    <?php foreach ($nodes as $n): $st = json_decode((string) $n['last_status'], true) ?: []; $ns = $st['secrets'] ?? null; ?>
        <div class="list-group-item">
            <div class="d-flex"><span class="mono"><?= esc($n['node_key']) ?></span><span class="ms-auto">
            <?php if ($revision === null): ?><span class="text-secondary small">noch nichts gespeichert</span>
            <?php elseif (! is_array($ns)): ?><span class="badge bg-orange-lt">Node-Version ohne Unterstützung</span>
            <?php elseif (! empty($ns['push_error'])): ?><span class="badge bg-red-lt">Fehler</span>
            <?php elseif (($ns['revision'] ?? null) === $revision): ?><span class="badge bg-green-lt">aktuell</span>
            <?php elseif (($ns['pending_revision'] ?? null) === $revision): ?><span class="badge bg-orange-lt">wartet auf Freigabe</span>
            <?php else: ?><span class="badge bg-yellow-lt">veraltet</span><?php endif ?></span></div>
            <?php if (is_array($ns)): ?>
                <?php if (! empty($ns['push_error'])): ?><div class="small text-danger"><?= esc($ns['push_error']) ?></div><?php endif ?>
                <?php if (($ns['pending_revision'] ?? null) === $revision && $revision !== null): ?><div class="small">an der Node: <code>sudo mailrunner mr:config:apply</code></div><?php endif ?>
                <?php $same = $ns['local_same'] ?? []; $over = $ns['local_overrides'] ?? []; $onlyLocal = array_values(array_diff($ns['local'] ?? [], $over, $same, $ns['common'] ?? []));
                    $central = array_values(array_diff($ns['common'] ?? [], $over, $same)); ?>
                <?php if ($central !== []): ?><div class="small text-secondary">zentral: <span class="mono"><?= esc(implode(', ', $central)) ?></span></div><?php endif ?>
                <?php if ($same !== []): ?><div class="small text-secondary">lokal, gleicher Wert wie zentral: <span class="mono"><?= esc(implode(', ', $same)) ?></span></div><?php endif ?>
                <?php if ($over !== []): ?><div class="small text-warning">lokal abweichend (hat Vorrang): <span class="mono"><?= esc(implode(', ', $over)) ?></span></div><?php endif ?>
                <?php if ($onlyLocal !== []): ?><div class="small text-secondary">nur lokal: <span class="mono"><?= esc(implode(', ', $onlyLocal)) ?></span></div><?php endif ?>
            <?php endif ?>
        </div>
    <?php endforeach ?>
    <?php if ($nodes === []): ?><div class="list-group-item text-secondary text-center">Keine Nodes.</div><?php endif ?>
    </div>
</div>
</div>
</div>
<?= $this->endSection() ?>
