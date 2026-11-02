<?= $this->extend('layouts/main') ?>
<?php $this->setVar('title', 'postmaster & abuse') ?>
<?= $this->section('actions') ?>
<label class="form-check form-switch mb-0" title="Mails der Selbsttest-Projekte (Kunde system) anzeigen">
    <input class="form-check-input" type="checkbox" name="selftests" value="1"<?= $selftests ? ' checked' : '' ?>
        hx-get="/role-mails" hx-trigger="change" hx-target="#rolemail-lists" hx-select="#rolemail-lists" hx-swap="outerHTML" hx-push-url="true">
    <span class="form-check-label">Selbsttests anzeigen</span>
</label>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<p class="text-secondary">Mails an postmaster@ und abuse@ aller Projekte. Die Originalmail bleibt bei diesen Mails immer erhalten. Jedes Öffnen wird protokolliert.</p>
<div id="rolemail-lists">
<?php foreach ($nodes as $n): ?>
    <div class="card mb-3"><div class="card-header"><h3 class="card-title"><?= esc($n['name']) ?> <span class="text-secondary small mono ms-2"><?= esc($n['node_key']) ?></span></h3></div>
        <div hx-get="/role-mails/node/<?= $n['public_id'] ?><?= $selftests ? '?selftests=1' : '' ?>" hx-trigger="load"><div class="card-body text-secondary">Lädt …</div></div></div>
<?php endforeach ?>
<?php if ($nodes === []): ?>
    <div class="card"><div class="empty"><p class="empty-title">Keine Datensätze</p><p class="empty-subtitle text-secondary">Es ist noch keine Node angelegt.</p></div></div>
<?php endif ?>
</div>
<?= $this->endSection() ?>
