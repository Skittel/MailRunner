<?= $this->extend('layouts/main') ?>
<?php $this->setVar('title', $node['name']); $this->setVar('pretitle', 'Node · ' . esc($node['node_key'])) ?>
<?= $this->section('actions') ?>
<?php if (can_write()): ?><form method="post" action="/nodes/<?= $node['public_id'] ?>/import" class="d-inline"><?= csrf_field() ?>
    <button class="btn btn-outline-secondary" title="Kunden und Projekte, die nur auf der Node existieren, hier übernehmen">Konfiguration übernehmen</button></form><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row row-cards">
    <div class="col-lg-5"><?= view('nodes/_card', ['n' => $node, 'detail' => true]) ?>
        <div class="card mt-3"><div class="card-header"><h3 class="card-title">Projekte auf dieser Node</h3></div>
            <div class="list-group list-group-flush">
            <?php foreach ($projects as $p): ?>
                <a href="/projects/<?= $p['public_id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between">
                    <span class="mono"><?= esc($p['customer_key'] . '/' . $p['project_key']) ?></span>
                    <?= $p['sync_error'] ? '<span class="badge bg-red-lt">nicht übertragen</span>' : '<span class="badge bg-green-lt">übertragen</span>' ?></a>
            <?php endforeach ?>
            <?php if ($projects === []): ?><div class="list-group-item text-secondary">Keine. „Konfiguration übernehmen“ liest vorhandene Projekte der Node ein.</div><?php endif ?>
            </div></div>
    </div>
    <div class="col-lg-7"><div class="card">
        <div class="card-header"><h3 class="card-title">Journal der Node (neueste 50)</h3>
            <div class="card-actions"><select class="form-select form-select-sm" name="event" hx-get="/nodes/<?= $node['public_id'] ?>/journal" hx-target="#journal" hx-trigger="change">
                <option value="">alle Ereignisse</option><option value="smtp.attempt,smtp.connection">Zustellversuche</option>
                <option value="receive.received,receive.acked,receive.role_mail">Empfang</option><option value="node.selftest">Selbsttests</option>
                <option value="project.created,project.updated,routes.updated,customer.created,customer.updated,token.created,token.revoked">Konfiguration</option></select></div></div>
        <div id="journal" hx-get="/nodes/<?= $node['public_id'] ?>/journal" hx-trigger="load"><div class="card-body text-secondary">Lädt …</div></div>
    </div></div>
</div>
<?= $this->endSection() ?>
