<?php use App\Controllers\Projects;
$isNew = $project === null || empty($project['id']);
$s = $isNew ? (is_string($project['settings'] ?? null) ? json_decode($project['settings'], true) : []) : json_decode($project['settings'], true);
$s = ($s ?: []) + ['recipient_pattern' => null, 'recipient_always_allow' => null, 'delete_after_ack_hours' => 24, 'unacked_days' => 14, 'metadata_days' => 30,
    'journal_scope' => 'own', 'role_mail' => 'default', 'role_mail_forward_to' => null];
$routes = implode("\n", json_decode((string) ($project['routes'] ?? '[]'), true) ?: []);
$type = $project['type'] ?? 'receive';
$s += ['send_limit_hour' => null, 'send_limit_day' => null, 'send_allowed_domains' => null, 'send_test_criteria' => null];
$criteria = array_map(static fn ($c) => $c[1], Projects::TEST_CRITERIA);
if (is_array($s['send_test_criteria'])) {
    $criteria = array_intersect_key($s['send_test_criteria'], $criteria) + $criteria;
}
$allDomains = $isNew && empty($errors) ? false : $s['send_allowed_domains'] === null;
$err = static fn ($k) => isset($errors[$k]) ? ' is-invalid' : '';
$fb = static fn ($k) => isset($errors[$k]) ? '<div class="invalid-feedback">' . esc($errors[$k]) . '</div>' : '';
?>
<?= $this->extend('layouts/main') ?>
<?php $this->setVar('title', $isNew ? 'Projekt anlegen' : $project['name']);
$this->setVar('pretitle', '<a href="/customers/' . $customer['public_id'] . '">' . esc($customer['name']) . '</a>' . ($isNew ? '' : ' · <span class="mono">' . esc($customer['customer_key'] . '/' . $project['project_key']) . '</span>')) ?>
<?php if (! $isNew): ?><?= $this->section('actions') ?>
<?php if (can_write()): ?><form method="post" action="/projects/<?= $project['public_id'] ?>/push" class="d-inline"><?= csrf_field() ?><button class="btn btn-outline-secondary">Erneut übertragen</button></form><?php endif ?>
<?= $this->endSection() ?><?php endif ?>
<?= $this->section('content') ?>
<?php if (! $isNew): ?>
    <?php if (! empty($project['sync_pending']) && ! $project['sync_error']): ?>
    <div class="alert alert-warning">Wartet auf Freigabe an der Node <span class="mono"><?= esc($project['node_key']) ?></span>: Sie übernimmt Konfiguration nur nach Bestätigung (<code>sudo mailrunner mr:config:apply</code>).</div>
    <?php else: ?>
    <div class="alert <?= $project['sync_error'] ? 'alert-danger' : 'alert-success' ?>">
        <?= $project['sync_error'] ? 'Nicht auf die Node übertragen: ' . esc($project['sync_error']) : 'Auf Node <span class="mono">' . esc($project['node_key']) . '</span> übertragen ' . ago($project['synced_at']) . ' (Revision ' . (int) $project['node_revision'] . ').' ?>
    </div>
    <?php endif ?>
<?php endif ?>
<div class="row row-cards">
<div class="col-lg-8">
<form method="post" action="<?= $isNew ? '/customers/' . $customer['public_id'] . '/projects' : '/projects/' . $project['public_id'] ?>" class="card"><?= csrf_field() ?>
    <fieldset class="card-body"<?= can_write() ? '' : ' disabled' ?>>
        <div class="row">
            <?php if ($isNew): ?>
            <div class="col-md-4 mb-3"><label class="form-label required">Schlüssel</label><input name="project_key" class="form-control<?= $err('project_key') ?>" value="<?= esc($project['project_key'] ?? '') ?>" placeholder="inbox"><?= $fb('project_key') ?></div>
            <div class="col-md-4 mb-3"><label class="form-label required">Node</label><select name="node_id" class="form-select<?= $err('node_id') ?>">
                <?php foreach ($nodes as $n): ?><option value="<?= $n['public_id'] ?>"<?= (int) ($project['node_id'] ?? 0) === (int) $n['id'] ? ' selected' : '' ?>><?= esc($n['name']) ?> (<?= esc($n['node_key']) ?>)</option><?php endforeach ?></select><?= $fb('node_id') ?></div>
            <div class="col-md-4 mb-3"><label class="form-label required">Typ</label><select name="type" id="project-type" class="form-select">
                <?php foreach (Projects::TYPES as $k => $l): ?><option value="<?= $k ?>"<?= $type === $k ? ' selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select>
                <div class="form-hint">Kann später nicht geändert werden.</div></div>
            <div class="col-md-4 mb-3" data-type="receive"><label class="form-label required">Profil</label><select name="profile" class="form-select">
                <?php foreach (Projects::PROFILES as $k => $l): ?><option value="<?= $k ?>"<?= ($project['profile'] ?? 'analysis') === $k ? ' selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></div>
            <?php else: ?>
            <div class="col-md-4 mb-3"><label class="form-label">Node</label><div class="form-control-plaintext mono"><?= esc($project['node_key']) ?></div></div>
            <div class="col-md-4 mb-3"><label class="form-label">Typ</label><div class="form-control-plaintext"><?= esc(Projects::TYPES[$type] ?? $type) ?></div></div>
            <?php if ($type === 'receive'): ?><div class="col-md-4 mb-3"><label class="form-label">Profil</label><div class="form-control-plaintext"><?= esc(Projects::PROFILES[$project['profile']] ?? $project['profile']) ?></div></div><?php endif ?>
            <?php endif ?>
        </div>
        <div class="mb-3"><label class="form-label">Name</label><input name="name" class="form-control" value="<?= esc($project['name'] ?? '') ?>"></div>
        <?php if ($isNew || $type === 'receive'): ?>
        <div data-type="receive">
        <div class="mb-3"><label class="form-label">Routen</label><textarea name="routes" rows="3" class="form-control mono<?= $err('routes') ?>" placeholder="example.org&#10;info@other.example"><?= esc($routes) ?></textarea><?= $fb('routes') ?>
            <div class="form-hint">Eine Domain oder Adresse pro Zeile. postmaster@ und abuse@ werden für jede Domain immer angenommen.</div></div>
        <div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">Empfängerregel (Regex für den lokalen Teil)</label><input name="recipient_pattern" class="form-control mono<?= $err('recipient_pattern') ?>" value="<?= esc($s['recipient_pattern'] ?? '') ?>" placeholder="^[a-z0-9_-]{8,}$"><?= $fb('recipient_pattern') ?></div>
            <div class="col-md-6 mb-3"><label class="form-label">Immer erlaubt (zusätzlich)</label><input name="always_allow" class="form-control mono" value="<?= esc(implode(',', $s['recipient_always_allow'] ?? [])) ?>" placeholder="info,kontakt"></div>
        </div>
        <div class="row">
            <div class="col-md-4 mb-3"><label class="form-label">Löschen nach Abholung (h)</label><input type="number" min="0" max="87600" name="delete_after_ack_hours" class="form-control" value="<?= (int) $s['delete_after_ack_hours'] ?>"></div>
            <div class="col-md-4 mb-3"><label class="form-label">Nicht abgeholt löschen (Tage)</label><input type="number" min="0" max="3650" name="unacked_days" class="form-control" value="<?= (int) $s['unacked_days'] ?>"></div>
            <div class="col-md-4 mb-3"><label class="form-label">Metadaten löschen (Tage)</label><input type="number" min="0" max="3650" name="metadata_days" class="form-control" value="<?= (int) $s['metadata_days'] ?>"></div>
        </div>
        <div class="row">
            <div class="col-md-4 mb-3"><label class="form-label">Journal: Einträge ohne Projekt</label><select name="journal_scope" class="form-select">
                <?php foreach (Projects::JOURNAL_SCOPE as $k => $l): ?><option value="<?= $k ?>"<?= $s['journal_scope'] === $k ? ' selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></div>
            <div class="col-md-4 mb-3"><label class="form-label">postmaster & abuse</label><select name="role_mail" class="form-select">
                <?php foreach (Projects::ROLE_MAIL as $k => $l): ?><option value="<?= $k ?>"<?= $s['role_mail'] === $k ? ' selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></div>
            <div class="col-md-4 mb-3"><label class="form-label">Weiterleiten an</label><input type="email" name="role_mail_forward_to" class="form-control<?= $err('role_mail_forward_to') ?>" value="<?= esc($s['role_mail_forward_to'] ?? '') ?>"><?= $fb('role_mail_forward_to') ?></div>
        </div>
        </div>
        <?php endif ?>
        <?php if ($isNew || $type === 'send'): ?>
        <div data-type="send">
        <div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">Mails pro Stunde (Projekt)</label><input type="number" min="0" max="<?= Projects::MAX_SEND_LIMIT ?>" name="send_limit_hour" class="form-control<?= $err('send_limit_hour') ?>" value="<?= esc((string) ($s['send_limit_hour'] ?? '')) ?>" placeholder="keine Grenze"><?= $fb('send_limit_hour') ?></div>
            <div class="col-md-6 mb-3"><label class="form-label">Mails pro Tag (Projekt)</label><input type="number" min="0" max="<?= Projects::MAX_SEND_LIMIT ?>" name="send_limit_day" class="form-control<?= $err('send_limit_day') ?>" value="<?= esc((string) ($s['send_limit_day'] ?? '')) ?>" placeholder="keine Grenze"><?= $fb('send_limit_day') ?></div>
            <div class="col-12 form-hint mb-3">Obergrenzen für alle Sendeprofile des Projekts. Die Node lehnt Profile ab, die darüber liegen.</div>
        </div>
        <div class="mb-3"><label class="form-label">Erlaubte Absenderdomains</label>
            <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="send_all_domains" value="1"<?= $allDomains ? ' checked' : '' ?>><span class="form-check-label">alle Domains</span></label>
            <textarea name="send_allowed_domains" rows="3" class="form-control mono<?= $err('send_allowed_domains') ?>" placeholder="shop.example&#10;example.org"><?= esc(implode("\n", $s['send_allowed_domains'] ?? [])) ?></textarea><?= $fb('send_allowed_domains') ?>
            <div class="form-hint">Eine Domain pro Zeile, Subdomains sind eingeschlossen. Der Kunde kann DKIM-Schlüssel nur für diese Domains setzen.</div></div>
        <div class="mb-3"><label class="form-label">Domain-Test muss bestehen</label>
            <?php foreach (Projects::TEST_CRITERIA as $k => [$label]): ?>
            <label class="form-check"><input class="form-check-input" type="checkbox" name="criteria[<?= $k ?>]" value="1"<?= ! empty($criteria[$k]) ? ' checked' : '' ?>><span class="form-check-label"><?= esc($label) ?></span></label>
            <?php endforeach ?>
            <div class="form-hint">Ohne bestandenen Test versendet die Node nichts von dieser Domain. Der Kunde sieht nur bestanden oder nicht bestanden mit den Gründen.</div></div>
        </div>
        <?php endif ?>
        <label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled" value="1"<?= ($project['enabled'] ?? 1) ? ' checked' : '' ?>><span class="form-check-label">aktiv</span></label>
    </fieldset>
    <?php if (can_write()): ?><div class="card-footer text-end"><a href="/customers/<?= $customer['public_id'] ?>" class="btn btn-link">Abbrechen</a><button class="btn btn-primary"><?= $isNew ? 'Anlegen und übertragen' : 'Speichern und übertragen' ?></button></div><?php endif ?>
</form>
</div>
<?php if (! $isNew): $moveOld ??= null; ?>
<div class="col-lg-4">
<div class="card mb-3"><div class="card-header"><h3 class="card-title">Node</h3></div><div class="card-body">
    <div class="mb-2"><span class="mono"><?= esc($project['node_key']) ?></span> <span class="text-secondary small">API: <?= esc($nodeBase = (string) (array_column($nodes, 'base_url', 'id')[$project['node_id']] ?? '')) ?></span></div>
<?php if ($moveOld !== null): $st = $moveOld['state']; ?>
    <div class="alert alert-info mb-3">
        <div class="fw-bold mb-1">Umzug von <span class="mono"><?= esc($moveOld['node']['node_key']) ?></span> läuft</div>
        <ol class="mb-1 ps-3 small">
            <li>DNS: MX der Routen-Domains auf <span class="mono"><?= esc($project['node_key']) ?></span> umstellen (TTL abwarten).</li>
            <li>Anwendung: API-Adresse auf <span class="mono"><?= esc($nodeBase) ?></span> umstellen. Die Tokens bleiben gleich, sie gelten auf beiden Nodes.</li>
            <li>Alte Node: offene Mails abholen lassen.</li>
            <li>„Alte Node abschalten“.</li>
        </ol>
        <div class="small">Alte Node: <?= $st === null ? '<span class="text-danger">nicht erreichbar</span>'
            : (($st['enabled'] ? 'nimmt noch an' : 'deaktiviert') . ' · offene Mails: <strong>' . (int) ($st['messages_unacked'] ?? 0) . '</strong>') ?></div>
    </div>
    <?php if (can_write()): ?>
    <form method="post" action="/projects/<?= $project['public_id'] ?>/move/finish" class="d-inline"><?= csrf_field() ?><button class="btn btn-primary btn-sm">Alte Node abschalten</button></form>
    <form method="post" action="/projects/<?= $project['public_id'] ?>/move/cancel" class="d-inline"><?= csrf_field() ?><button class="btn btn-ghost-danger btn-sm">Umzug abbrechen</button></form>
    <?php endif ?>
<?php elseif (can_write() && count($nodes) > 1): ?>
    <form method="post" action="/projects/<?= $project['public_id'] ?>/move"><?= csrf_field() ?>
        <div class="input-group input-group-sm"><select name="node_id" class="form-select">
            <?php foreach ($nodes as $n): if ((int) $n['id'] === (int) $project['node_id']) { continue; } ?><option value="<?= $n['public_id'] ?>"><?= esc($n['name']) ?> (<?= esc($n['node_key']) ?>)</option><?php endforeach ?>
        </select><button class="btn btn-outline-primary">Auf andere Node umziehen</button></div>
        <div class="form-hint">Legt das Projekt auf der neuen Node an. Die alte Node nimmt weiter an, bis du sie abschaltest.</div>
    </form>
<?php endif ?>
</div></div>
<div class="card"><div class="card-header"><h3 class="card-title">API-Tokens</h3></div>
    <div id="tokens" hx-get="/projects/<?= $project['public_id'] ?>/tokens" hx-trigger="load"><div class="card-body text-secondary">Lädt …</div></div></div>
</div>
<?php endif ?>
</div>
<?= $this->endSection() ?>
