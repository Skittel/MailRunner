<?= $this->extend('layouts/main') ?>
<?php $this->setVar('title', 'postmaster & abuse') ?>
<?= $this->section('content') ?>
<p class="text-secondary">Mails an postmaster@ und abuse@ aller Projekte. Die Originalmail bleibt bei diesen Mails immer erhalten. Jedes Öffnen wird protokolliert.</p>
<?php foreach ($nodes as $n): ?>
    <div class="card mb-3"><div class="card-header"><h3 class="card-title"><?= esc($n['name']) ?> <span class="text-secondary small mono ms-2"><?= esc($n['node_key']) ?></span></h3></div>
        <div hx-get="/role-mails/node/<?= $n['id'] ?>" hx-trigger="load"><div class="card-body text-secondary">Lädt …</div></div></div>
<?php endforeach ?>
<?php if ($nodes === []): ?><div class="text-secondary">Keine Nodes.</div><?php endif ?>
<?= $this->endSection() ?>
