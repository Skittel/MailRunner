<?= $this->extend('layouts/main') ?>
<?php $this->setVar('title', $customer['name']); $this->setVar('pretitle', 'Kunde · ' . esc($customer['customer_key'])) ?>
<?= $this->section('actions') ?><?php if (can_write()): ?><a href="/customers/<?= $customer['public_id'] ?>/projects/new" class="btn btn-primary">Projekt anlegen</a><?php endif ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row row-cards">
    <div class="col-lg-4"><form method="post" action="/customers/<?= $customer['public_id'] ?>" class="card"><?= csrf_field() ?>
        <div class="card-header"><h3 class="card-title">Kunde</h3></div>
        <fieldset class="card-body"<?= can_write() ? '' : ' disabled' ?>>
            <div class="mb-3"><label class="form-label">Name</label><input name="name" class="form-control" value="<?= esc($customer['name']) ?>"></div>
            <label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled" value="1"<?= $customer['enabled'] ? ' checked' : '' ?>>
                <span class="form-check-label">aktiv (deaktiviert: alle Projekte nehmen keine Mails mehr an)</span></label>
        </fieldset>
        <?php if (can_write()): ?><div class="card-footer text-end"><button class="btn btn-primary">Speichern</button></div><?php endif ?></form></div>
    <div class="col-lg-8"><div class="card"><div class="card-header"><h3 class="card-title">Projekte</h3></div>
        <div class="table-responsive"><table class="table table-vcenter card-table"><thead><tr><th>Projekt</th><th>Node</th><th>Profil</th><th>Routen</th><th>Übertragung</th></tr></thead><tbody>
        <?php foreach ($projects as $p): ?>
            <tr><td><a href="/projects/<?= $p['public_id'] ?>"><?= esc($p['name']) ?></a><div class="small text-secondary mono"><?= esc($p['project_key']) ?></div></td>
                <td class="mono small"><?= esc($p['node_key']) ?></td><td><?= esc($p['profile']) ?></td>
                <td class="small"><?= esc(implode(', ', json_decode($p['routes'], true) ?: [])) ?></td>
                <td><?= $p['sync_error'] ? '<span class="badge bg-red-lt" title="' . esc($p['sync_error'], 'attr') . '">Fehler</span>' : '<span class="badge bg-green-lt">' . ago($p['synced_at']) . '</span>' ?></td></tr>
        <?php endforeach ?>
        <?php if ($projects === []): ?><tr><td colspan="5" class="text-secondary">Noch keine Projekte.</td></tr><?php endif ?>
        </tbody></table></div></div></div>
</div>
<?= $this->endSection() ?>
