<?= $this->extend('layouts/main') ?>
<?php $this->setVar('title', 'Kunden') ?>
<?= $this->section('actions') ?><a href="/customers/new" class="btn btn-primary">Kunde anlegen</a><?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="card"><div class="table-responsive"><table class="table table-vcenter card-table">
    <thead><tr><th>Kunde</th><th>Schlüssel</th><th>Projekte</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($customers as $c): ?>
        <tr><td><a href="/customers/<?= $c['id'] ?>"><?= esc($c['name']) ?></a></td><td class="mono"><?= esc($c['customer_key']) ?></td>
            <td><?= (int) $c['projects'] ?><?= $c['sync_errors'] ? ' <span class="badge bg-red-lt">' . (int) $c['sync_errors'] . ' nicht übertragen</span>' : '' ?></td>
            <td><?= $c['enabled'] ? '<span class="badge bg-green-lt">aktiv</span>' : '<span class="badge bg-secondary-lt">deaktiviert</span>' ?></td></tr>
    <?php endforeach ?>
    <?php if ($customers === []): ?><tr><td colspan="4" class="text-secondary">Noch keine Kunden.</td></tr><?php endif ?>
    </tbody></table></div></div>
<?= $this->endSection() ?>
