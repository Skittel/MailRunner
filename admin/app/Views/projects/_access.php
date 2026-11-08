<?php
/**
 * Receive project: SMTP logins and webhook that the customer's application set up, per node read live (never a
 * password hash or the full webhook URL), with the state of the central copy.
 *
 * @var array $project
 * @var list<array{id: int, label: string, node_key: string, data: array|null}> $nodes
 * @var array $central
 * @var array{0: string, 1: string}|null $notice
 */
$notice ??= null;
$auth = ($project['profile'] ?? '') === 'checked_auth';
?>
<?= view('projects/_central', ['project' => $project, 'nodes' => $nodes, 'central' => $central ?? null, 'notice' => $notice]) ?>
<?php foreach ($nodes as $n): $d = $n['data']; ?>
<?php if (count($nodes) > 1): ?><div class="card-body border-bottom bg-light py-2 fw-bold"><?= esc($n['label']) ?> <span class="mono"><?= esc($n['node_key']) ?></span></div><?php endif ?>
<?php if ($d === null): ?>
    <div class="card-body border-bottom"><div class="alert alert-warning mb-0">Node <span class="mono"><?= esc($n['node_key']) ?></span> nicht erreichbar.</div></div>
<?php continue; endif ?>
<div class="card-body border-bottom">
    <h4 class="subheader mb-2">SMTP-Zugänge</h4>
<?php if (! $auth): ?>
    <div class="text-secondary small">Dieses Profil nimmt ohne SMTP-Anmeldung an (nur „Geprüft + SMTP-Anmeldung“ hat SMTP-Zugänge).</div>
<?php elseif (! array_key_exists('smtp_credentials', $d)): ?>
    <div class="text-secondary small">Die Node ist zu alt für diese Ansicht (ab Node 0.2.10).</div>
<?php elseif ($d['smtp_credentials'] === []): ?>
    <div class="text-secondary small">Noch keine: Die Anwendung des Kunden legt sie per API an (<code>POST /v1/smtp-credentials</code>).</div>
<?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-vcenter mb-0">
        <thead><tr><th>Benutzer</th><th>Bezeichnung</th><th>Netze</th><th>Zuletzt benutzt</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($d['smtp_credentials'] as $c): ?>
            <tr>
                <td class="mono"><?= esc($c['username']) ?><?= $c['tls_required'] ? '' : ' <span class="badge bg-yellow-lt" title="Anmeldung ohne TLS auf Port 25 erlaubt">ohne TLS</span>' ?></td>
                <td class="small"><?= esc((string) $c['label']) ?></td>
                <td class="small mono"><?= $c['allowed_networks'] === null ? '<span class="text-secondary">alle</span>' : esc(implode(', ', $c['allowed_networks'])) ?></td>
                <td class="small text-nowrap"><?= ago($c['last_used_at']) ?><?= $c['previous_valid_until'] ? '<div class="text-secondary" title="nach einem Kennwortwechsel">altes Kennwort bis ' . dt($c['previous_valid_until']) . '</div>' : '' ?></td>
                <td class="w-1 text-nowrap"><?= $c['enabled'] ? '<span class="badge bg-green-lt">aktiv</span>' : '<span class="badge bg-secondary-lt" title="vom Kunden ausgeschaltet">aus</span>' ?><?= $c['password_change_required'] ? ' <span class="badge bg-orange-lt">Kennwortwechsel nötig</span>' : '' ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
<?php endif ?>
</div>
<div class="card-body<?= $n === end($nodes) ? '' : ' border-bottom' ?>">
    <h4 class="subheader mb-2">Webhook</h4>
<?php $w = $d['webhook'] ?? null; ?>
<?php if ($w === null): ?>
    <div class="text-secondary small">Kein Webhook: Die Anwendung holt nur per Abfrage ab (setzen per <code>PUT /v1/webhook</code>).</div>
<?php else: ?>
    <div class="small"><span class="mono" title="gekürzt: die Adresse kann ein Geheimnis enthalten"><?= esc($w['url']) ?></span> · Pause <?= (int) $w['cooldown_seconds'] ?> s
        · zuletzt <?= $w['last_status'] === null ? 'noch nicht gesendet' : esc($w['last_status']) . ($w['last_http_code'] ? ' (HTTP ' . (int) $w['last_http_code'] . ')' : '') . ' ' . ago($w['last_attempt_at']) ?>
        <?= $w['last_error'] ? '<div class="text-red">' . esc($w['last_error']) . '</div>' : '' ?></div>
<?php endif ?>
</div>
<?php endforeach ?>
