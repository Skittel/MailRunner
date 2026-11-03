<?= $this->extend('layouts/main') ?>
<?php $this->setVar('title', 'Neue Node einrichten');
$err = static fn ($k) => isset($errors[$k]) ? ' is-invalid' : '';
$fb  = static fn ($k) => isset($errors[$k]) ? '<div class="invalid-feedback">' . esc($errors[$k]) . '</div>' : ''; ?>
<?= $this->section('content') ?>
<?php if ($token === null): ?>
<form method="post" action="/nodes/enroll" class="card"><?= csrf_field() ?>
    <div class="card-header"><h3 class="card-title">1. Angaben zur neuen Node</h3></div>
    <div class="card-body"><div class="row">
        <div class="col-md-3 mb-3"><label class="form-label required">Node-ID</label><input name="node_key" class="form-control<?= $err('node_key') ?>" value="<?= esc($data['node_key']) ?>" placeholder="node2.eu"><?= $fb('node_key') ?></div>
        <div class="col-md-4 mb-3"><label class="form-label required">Hostname</label><input name="hostname" class="form-control<?= $err('hostname') ?>" value="<?= esc($data['hostname']) ?>" placeholder="node2.eu.example.net"><?= $fb('hostname') ?>
            <div class="form-hint">Name der Node (API, Zertifikat, SMTP-Begrüßung).</div></div>
        <div class="col-md-5 mb-3"><label class="form-label">MX-Namen (optional)</label><input name="mx_names" class="form-control mono<?= $err('mx_names') ?>" value="<?= esc($data['mx_names']) ?>" placeholder="node2.eu.example.net=46, node2-ip4.example.net=4, node2-ip6.example.net=6"><?= $fb('mx_names') ?>
            <div class="form-hint">Name=IP-Versionen, getrennt durch Komma oder Leerzeichen. Der Selbsttest prüft sie, das Zertifikat enthält sie.</div></div>
    </div>
    <div id="enroll-dns" class="mt-2"></div></div>
    <div class="card-footer d-flex justify-content-between">
        <button type="button" class="btn btn-outline-secondary" hx-get="/nodes/enroll/dns" hx-include="[name=hostname],[name=mx_names]" hx-target="#enroll-dns">DNS prüfen</button>
        <span><a href="/nodes" class="btn btn-link">Abbrechen</a><button class="btn btn-primary">Anleitung und Token erzeugen</button></span></div>
</form>
<?php else:
    $dnsQuery = http_build_query(['hostname' => $data['hostname'], 'mx_names' => $data['mx_names']]); ?>
<div class="alert alert-warning">Das Token ist <strong><?= \App\Libraries\Enrollment::TTL_MINUTES ?> Minuten</strong> gültig und nur einmal verwendbar. Es wird nicht noch einmal angezeigt.</div>
<div class="row row-cards">
<div class="col-lg-8">
<div class="card mb-3"><div class="card-header"><h3 class="card-title">2. Vorbereitung</h3></div><div class="card-body">
    <ul>
        <li>Server mit <strong>Ubuntu 26.04</strong>, SSH mit sudo.</li>
        <li><strong>DNS</strong> wie unten (A/AAAA zeigen auf den Server), <strong>Reverse-DNS (PTR)</strong> beim Provider auf <span class="mono"><?= esc($data['hostname']) ?></span>.</li>
        <li><strong>Firewall:</strong> Port 25, 80 und 443 öffentlich, alles andere nur von den Admin-Adressen.</li>
    </ul>
    <div hx-get="/nodes/enroll/dns?<?= esc($dnsQuery, 'attr') ?>" hx-trigger="load"><div class="text-secondary small">DNS wird geprüft …</div></div>
    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" hx-get="/nodes/enroll/dns?<?= esc($dnsQuery, 'attr') ?>" hx-target="previous div">DNS erneut prüfen</button>
</div></div>
<div class="card mb-3"><div class="card-header"><h3 class="card-title">3. Auf der neuen Node (ein Befehl)</h3></div><div class="card-body">
<div class="copy-box mb-2"><pre class="mono copy-src mb-0">curl -fsSL <?= esc($adminUrl) ?>/enroll/bootstrap.sh | sudo bash -s -- <?= esc($token) ?></pre><?= view('components/copy_btn') ?></div>
    <div class="form-hint">Holt MailRunner <?= esc($version) ?> und die Konfiguration dieser Node von hier, prüft DNS, holt das Zertifikat bei Let's Encrypt, installiert Postfix, API und Selbsttest, übernimmt das CA-Zertifikat dieser Admin-Instanz und meldet die Node hier an.</div>
</div></div>
<div class="card"><div class="card-header"><h3 class="card-title">4. Danach</h3></div><div class="card-body">
<div class="copy-box mb-3"><pre class="mono copy-src mb-0">sudo nano /etc/mailrunner/secrets.env</pre><?= view('components/copy_btn') ?></div>
    <div class="form-hint mb-2">Dort die Schlüssel für Spamhaus DQS, blacklistmaster und ipregistry eintragen.</div>
    <ul class="mb-0">
        <li>Die Node erscheint unter <a href="/nodes">Nodes</a>. Projekte legst du beim Kunden an oder ziehst sie auf der Projektseite um.</li>
        <li>Ohne MailRunner Admin geht es auch: Wiki MailRunnerPub, „Installation und Umzug“.</li>
    </ul>
</div></div>
</div>
<div class="col-lg-4"><div class="card"><div class="card-body">
    <div class="text-secondary small">Node-ID</div><div class="mono mb-2"><?= esc($data['node_key']) ?></div>
    <div class="text-secondary small">Hostname</div><div class="mono mb-2"><?= esc($data['hostname']) ?></div>
    <?php if ($data['mx_names'] !== ''): ?><div class="text-secondary small">MX-Namen</div><div class="mono small mb-2"><?= esc($data['mx_names']) ?></div><?php endif ?>
    <div class="text-secondary small">Token gültig bis</div><div><?= dt(gmdate('Y-m-d H:i:s', time() + \App\Libraries\Enrollment::TTL_MINUTES * 60), 'd.m.Y H:i') ?> Uhr</div>
</div></div></div>
</div>
<?php endif ?>
<?= $this->endSection() ?>
