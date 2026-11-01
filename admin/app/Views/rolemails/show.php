<?= $this->extend('layouts/main') ?>
<?php $this->setVar('title', $mail['subject'] ?? ($msg['header']['subject'] ?? '(kein Betreff)'));
$this->setVar('pretitle', '<a href="/role-mails">postmaster & abuse</a> · ' . esc($msg['role']) . ' · <span class="mono">' . esc($msg['project']) . '</span>') ?>
<?= $this->section('actions') ?><?php if ($msg['raw_available']): ?><a href="/role-mails/<?= $node['id'] ?>/<?= esc($msg['message_id'], 'url') ?>/raw" class="btn btn-outline-secondary">Original (.eml)</a><?php endif ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row row-cards">
<div class="col-lg-8"><div class="card">
    <div class="card-body">
        <dl class="row kv mb-0">
            <dt class="col-3">Von</dt><dd class="col-9"><?= esc($mail['from_full'] ?? ($msg['header']['from'] ?? '')) ?></dd>
            <dt class="col-3">An</dt><dd class="col-9"><?= esc($mail['to'] ?? ($msg['header']['to'] ?? '')) ?></dd>
            <?php if (! empty($mail['cc'])): ?><dt class="col-3">Cc</dt><dd class="col-9"><?= esc($mail['cc']) ?></dd><?php endif ?>
            <dt class="col-3">Datum</dt><dd class="col-9"><?= esc($mail['date'] ?? ($msg['header']['date'] ?? '')) ?></dd>
        </dl>
    </div>
    <?php if ($mail === null): ?>
        <div class="card-body border-top text-secondary">Die Originalmail ist nicht mehr vorhanden (<?= esc($msg['delete_reason'] ?? 'gelöscht') ?>).</div>
    <?php else: ?>
        <div class="card-header border-top"><ul class="nav nav-tabs card-header-tabs" data-bs-toggle="tabs">
            <?php if ($mail['text'] !== null): ?><li class="nav-item"><a href="#t-text" class="nav-link active" data-bs-toggle="tab">Text</a></li><?php endif ?>
            <?php if ($mail['html_srcdoc'] !== null): ?><li class="nav-item"><a href="#t-html" class="nav-link<?= $mail['text'] === null ? ' active' : '' ?>" data-bs-toggle="tab">HTML (geschützt)</a></li><?php endif ?>
            <li class="nav-item"><a href="#t-headers" class="nav-link" data-bs-toggle="tab">Header</a></li></ul></div>
        <div class="card-body"><div class="tab-content">
            <?php if ($mail['text'] !== null): ?><div class="tab-pane active show" id="t-text"><div class="mail-text"><?= esc($mail['text']) ?></div></div><?php endif ?>
            <?php if ($mail['html_srcdoc'] !== null): ?><div class="tab-pane<?= $mail['text'] === null ? ' active show' : '' ?>" id="t-html">
                <iframe class="mail-frame" sandbox="" referrerpolicy="no-referrer" srcdoc="<?= esc($mail['html_srcdoc'], 'attr') ?>"></iframe>
                <div class="form-hint">Ohne Skripte, Formulare und externe Inhalte (Bilder von fremden Servern werden nicht geladen).</div></div><?php endif ?>
            <div class="tab-pane" id="t-headers"><div class="mail-text"><?= esc($msg['raw_headers'] ?? '') ?></div></div>
        </div></div>
        <?php if ($mail['attachments'] !== []): ?><div class="card-footer"><div class="small text-secondary mb-1">Anhänge (nur über die .eml-Datei)</div>
            <?php foreach ($mail['attachments'] as $a): ?><span class="badge bg-secondary-lt me-1"><?= esc($a['name']) ?> · <?= esc($a['type']) ?> · <?= bytes($a['size']) ?></span><?php endforeach ?></div><?php endif ?>
    <?php endif ?>
</div></div>
<div class="col-lg-4"><div class="card"><div class="card-header"><h3 class="card-title">Zustellung</h3></div><div class="card-body">
    <?php $d = $msg['debug'] ?? []; $e = $msg['envelope']; ?>
    <dl class="row kv small mb-0">
        <dt class="col-5">Eingang</dt><dd class="col-7"><?= dt($msg['received_at'], 'd.m.Y H:i:s') ?></dd>
        <dt class="col-5">MAIL FROM</dt><dd class="col-7 mono"><?= esc($e['mail_from'] ?: '<>') ?></dd>
        <dt class="col-5">RCPT TO</dt><dd class="col-7 mono"><?= esc($e['rcpt']) ?></dd>
        <dt class="col-5">Server</dt><dd class="col-7"><?= esc($e['helo']) ?><div class="mono"><?= esc($e['client_ip']) ?></div></dd>
        <dt class="col-5">Herkunft</dt><dd class="col-7"><?= esc(trim(($e['client']['as_organization'] ?? '') . ' · ' . ($e['client']['country'] ?? ''), ' ·')) ?: '–' ?></dd>
        <dt class="col-5">TLS</dt><dd class="col-7"><?= esc(($d['smtp']['tls']['protocol'] ?? '–') . ' ' . ($d['smtp']['tls']['status'] ?? '')) ?></dd>
        <dt class="col-5">SPF / DKIM / DMARC</dt><dd class="col-7"><?= esc(($d['spf']['result'] ?? '–') . ' / ' . ($d['dkim']['result'] ?? '–') . ' / ' . ($d['dmarc']['result'] ?? '–')) ?></dd>
        <dt class="col-5">Status</dt><dd class="col-7"><?= $msg['acked_at'] ? 'abgeholt ' . dt($msg['acked_at']) : 'nicht abgeholt' ?></dd>
    </dl></div></div></div>
</div>
<?= $this->endSection() ?>
