<?= $this->extend('layouts/main') ?>
<?php $this->setVar('title', 'Nodes') ?>
<?= $this->section('actions') ?><?php if (can_write()): ?><a href="/nodes/new" class="btn btn-primary">Node anlegen</a><?php endif ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="card"><div class="table-responsive"><table class="table table-vcenter card-table">
    <thead><tr><th>Node</th><th>Adresse</th><th>Zustand</th><th>Version</th><th>Selbsttest</th><th>Abgefragt</th></tr></thead>
    <tbody>
    <?php foreach ($nodes as $n): $st = $n['status']['selftest'] ?? null; ?>
        <tr><td><a href="/nodes/<?= $n['public_id'] ?>"><?= esc($n['name']) ?></a><div class="small text-secondary mono"><?= esc($n['node_key']) ?></div></td>
            <td class="mono small"><?= esc($n['base_url']) ?></td><td><?= health_badge($n['health']['level']) ?></td>
            <td><?= esc($n['status']['version'] ?? '–') ?></td>
            <td><?= $st ? esc($st['status']) . ' · ' . ago($st['finished_at'] ?? null) : '–' ?></td><td><?= ago($n['last_checked_at']) ?></td></tr>
    <?php endforeach ?>
    <?php if ($nodes === []): ?><tr><td colspan="6" class="text-secondary">Noch keine Node.</td></tr><?php endif ?>
    </tbody></table></div></div>
<?= $this->endSection() ?>
