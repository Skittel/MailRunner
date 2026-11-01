<?= $this->extend('layouts/main') ?>
<?php $this->setVar('title', 'Kunde anlegen') ?>
<?= $this->section('content') ?>
<form method="post" action="/customers" class="card"><?= csrf_field() ?>
    <div class="card-body">
        <div class="mb-3"><label class="form-label required">Schlüssel</label>
            <input name="customer_key" class="form-control<?= isset($errors['customer_key']) ? ' is-invalid' : '' ?>" value="<?= esc($customer['customer_key'] ?? '') ?>" placeholder="acme">
            <div class="invalid-feedback"><?= esc($errors['customer_key'] ?? '') ?></div><div class="form-hint">Kurz und dauerhaft, z. B. <code>acme</code>. Später nicht änderbar.</div></div>
        <div class="mb-3"><label class="form-label">Name</label><input name="name" class="form-control" value="<?= esc($customer['name'] ?? '') ?>" placeholder="ACME GmbH"></div>
    </div>
    <div class="card-footer text-end"><a href="/customers" class="btn btn-link">Abbrechen</a><button class="btn btn-primary">Anlegen</button></div>
</form>
<?= $this->endSection() ?>
