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
        <div class="col-md-5 mb-3"><label class="form-label">MX-Namen (optional)</label><input name="mx_names" class="form-control mono<?= $err('mx_names') ?>" value="<?= esc($data['mx_names']) ?>" placeholder="node2.eu.example.net=46 node2-ip4.example.net=4 node2-ip6.example.net=6"><?= $fb('mx_names') ?>
            <div class="form-hint">Name=IP-Versionen. Der Selbsttest prüft sie, das Zertifikat enthält sie.</div></div>
    </div></div>
    <div class="card-footer text-end"><a href="/nodes" class="btn btn-link">Abbrechen</a><button class="btn btn-primary">Anleitung und Token erzeugen</button></div>
</form>
<?php else:
    $repo = 'https://github.com/Skittel/MailRunner.git';
    $mx   = $data['mx_names'] !== '' ? ' \\' . "\n    --mx-names=\"" . $data['mx_names'] . '"' : '';
    $tag  = $version !== '' ? '--branch v' . $version . ' ' : ''; ?>
<div class="alert alert-warning">Das Token ist <strong><?= \App\Libraries\Enrollment::TTL_MINUTES ?> Minuten</strong> gültig und nur einmal verwendbar. Es wird nicht noch einmal angezeigt.</div>
<div class="row row-cards">
<div class="col-lg-8">
<div class="card mb-3"><div class="card-header"><h3 class="card-title">2. Vorbereitung</h3></div><div class="card-body">
    <ul class="mb-0">
        <li>Server mit <strong>Ubuntu 26.04</strong>, Zugang per SSH mit sudo.</li>
        <li><strong>DNS:</strong> A- und AAAA-Eintrag für <span class="mono"><?= esc($data['hostname']) ?></span><?= $data['mx_names'] !== '' ? ' und alle MX-Namen' : '' ?> zeigen auf den Server.</li>
        <li><strong>Reverse-DNS (PTR)</strong> der Server-IPs beim Provider auf <span class="mono"><?= esc($data['hostname']) ?></span> setzen.</li>
        <li><strong>Firewall:</strong> Port 25, 80 und 443 öffentlich, alles andere nur von den Admin-Adressen (Regeln: Wiki, KI-Regeln).</li>
    </ul>
</div></div>
<div class="card mb-3"><div class="card-header"><h3 class="card-title">3. Installation auf der neuen Node</h3></div><div class="card-body">
<pre class="mono mb-0">sudo apt-get update &amp;&amp; sudo apt-get install -y git
sudo git clone <?= esc($tag) ?><?= esc($repo) ?> /opt/src/MailRunner
cd /opt/src/MailRunner
sudo install/install.sh --role=receive --node-id=<?= esc($data['node_key']) ?> \
    --hostname=<?= esc($data['hostname']) ?><?= esc($mx) ?> \
    --admin-url=<?= esc($adminUrl) ?> \
    --enroll-token=<?= esc($token) ?></pre>
    <div class="form-hint mt-2">Der Installer holt das Zertifikat bei Let's Encrypt, richtet Postfix, API und Selbsttest ein, übernimmt das CA-Zertifikat dieser Admin-Instanz und meldet die Node hier an.</div>
</div></div>
<div class="card"><div class="card-header"><h3 class="card-title">4. Danach</h3></div><div class="card-body">
<pre class="mono">sudo nano /etc/mailrunner/secrets.env     # Spamhaus DQS, blacklistmaster, ipregistry
sudo mailrunner mr:selftest</pre>
    <ul class="mb-0">
        <li>Die Node erscheint unter <a href="/nodes">Nodes</a>, sobald der Installer fertig ist.</li>
        <li>Projekte legst du beim Kunden an oder ziehst sie auf der Projektseite um („Auf andere Node umziehen“).</li>
    </ul>
</div></div>
</div>
<div class="col-lg-4"><div class="card"><div class="card-body">
    <div class="text-secondary small">Node-ID</div><div class="mono mb-2"><?= esc($data['node_key']) ?></div>
    <div class="text-secondary small">Hostname</div><div class="mono mb-2"><?= esc($data['hostname']) ?></div>
    <div class="text-secondary small">Token gültig bis</div><div><?= dt(gmdate('Y-m-d H:i:s', time() + \App\Libraries\Enrollment::TTL_MINUTES * 60), 'd.m.Y H:i') ?> Uhr</div>
</div></div></div>
</div>
<?php endif ?>
<?= $this->endSection() ?>
