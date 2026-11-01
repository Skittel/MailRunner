<?= $this->extend('layouts/main') ?>
<?php $this->setVar('title', 'Node anlegen') ?>
<?= $this->section('content') ?>
<form method="post" action="/nodes" class="card">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="mb-3"><label class="form-label required">Node-ID</label>
            <input name="node_key" class="form-control<?= isset($errors['node_key']) ? ' is-invalid' : '' ?>" value="<?= esc($node['node_key'] ?? '') ?>" placeholder="node1.eu">
            <div class="invalid-feedback"><?= esc($errors['node_key'] ?? '') ?></div><div class="form-hint">Wie bei der Installation (<code>--node-id</code>).</div></div>
        <div class="mb-3"><label class="form-label">Name</label><input name="name" class="form-control" value="<?= esc($node['name'] ?? '') ?>"></div>
        <div class="mb-3"><label class="form-label required">Adresse</label>
            <input name="base_url" class="form-control<?= isset($errors['base_url']) ? ' is-invalid' : '' ?>" value="<?= esc($node['base_url'] ?? '') ?>" placeholder="https://mx.example.net">
            <div class="invalid-feedback"><?= esc($errors['base_url'] ?? '') ?></div>
            <div class="form-hint">Die Node muss das CA-Zertifikat dieser Admin-Instanz kennen (<code>install.sh --admin-ca=…</code>).</div></div>
    </div>
    <div class="card-footer text-end"><a href="/nodes" class="btn btn-link">Abbrechen</a><button class="btn btn-primary">Anlegen</button></div>
</form>
<?= $this->endSection() ?>
