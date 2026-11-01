<?= $this->extend('layouts/main') ?>
<?php $this->setVar('title', 'Übersicht') ?>
<?= $this->section('content') ?>
<div class="row row-deck row-cards mb-3">
    <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body">
        <div class="subheader">Nodes</div><div class="h1 mb-0"><?= count($nodes) ?></div>
        <div class="text-secondary small"><?= count(array_filter($nodes, fn ($n) => $n['health']['level'] === 'ok')) ?> gesund</div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body">
        <div class="subheader">Kunden</div><div class="h1 mb-0"><?= $customers ?></div><div class="text-secondary small"><a href="/customers">verwalten</a></div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body">
        <div class="subheader">Projekte</div><div class="h1 mb-0"><?= $projects ?></div>
        <div class="small <?= $unsynced ? 'text-danger' : 'text-secondary' ?>"><?= $unsynced ? $unsynced . ' nicht übertragen' : 'alle übertragen' ?></div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body">
        <div class="subheader">postmaster & abuse offen</div>
        <div class="h1 mb-0"><?= array_sum(array_map(fn ($n) => (int) ($n['status']['counts']['role_mails_unacked'] ?? 0), $nodes)) ?></div>
        <div class="text-secondary small"><a href="/role-mails">lesen</a></div></div></div></div>
</div>
<div id="node-cards" hx-get="/nodes/cards" hx-trigger="every 30s" hx-swap="innerHTML">
    <?= view('nodes/_cards', ['nodes' => $nodes]) ?>
</div>
<?= $this->endSection() ?>
