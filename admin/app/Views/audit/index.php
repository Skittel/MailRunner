<?= $this->extend('layouts/main') ?>
<?php $this->setVar('title', 'Protokoll') ?>
<?= $this->section('content') ?>
<div class="card"><div class="table-responsive"><table class="table table-sm card-table">
<thead><tr><th>Zeit</th><th>Benutzer</th><th>Aktion</th><th>Objekt</th><th>Details</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td class="small text-nowrap"><?= dt($r['created_at'], 'd.m.Y H:i:s') ?></td><td class="small"><?= esc($r['user_name'] ?? '–') ?></td>
    <td class="mono small"><?= esc($r['action']) ?></td><td class="mono small"><?= esc($r['object'] ?? '') ?></td><td class="mono small"><?= esc($r['details'] ?? '') ?></td></tr><?php endforeach ?>
<?php if ($rows === []): ?><tr><td colspan="5" class="text-secondary">Noch keine Einträge.</td></tr><?php endif ?>
</tbody></table></div></div>
<?= $this->endSection() ?>
