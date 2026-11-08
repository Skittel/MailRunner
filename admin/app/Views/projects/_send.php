<?php
/**
 * Send project (E-27 step 7): domains, send profiles with accesses and queue per node, read live from the node.
 *
 * @var array $project
 * @var list<array{label: string, node_key: string, data: array|null}> $nodes
 */
$retry = static fn (array $r) => (int) $r['attempts'] . ' Versuche in ' . ((int) $r['max_minutes'] >= 120 ? round($r['max_minutes'] / 60, 1) . ' h' : (int) $r['max_minutes'] . ' min');
$access = static fn (array $a) => match ($a['type']) {
    'api'  => '<span class="mono">' . esc($a['token_id'] ?? '') . '</span>',
    'smtp' => '<span class="mono">' . esc($a['username'] ?? '') . '</span>' . (empty($a['plaintext_port25']) ? '' : ' <span class="badge bg-yellow-lt" title="Anmeldung ohne TLS auf Port 25 erlaubt">ohne TLS</span>')
        . (empty($a['allowed_networks']) ? '' : ' <span class="small text-secondary">nur ' . esc(implode(', ', $a['allowed_networks'])) . '</span>'),
    'ip'   => '<span class="mono">' . esc(implode(', ', $a['networks'] ?? [])) . '</span>',
    default => '',
};
$central ??= ['central' => null, 'nodes' => []];
$syncError ??= null;
$c = $central['central'];
$queueLabels = ['waiting_test' => 'wartet auf Test', 'queued' => 'wartet', 'sending' => 'in Zustellung', 'deferred' => 'zurückgestellt'];
$finalLabels = ['sent' => 'zugestellt', 'bounced' => 'Bounce', 'failed' => 'fehlgeschlagen'];
?>
<div class="card-body border-bottom">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h4 class="subheader mb-1">Zentrale Konfiguration</h4>
            <?php if ($c === null): ?>
            <div class="small text-secondary">Noch nicht übernommen. Der Admin übernimmt Domains, Profile und Zugänge bei der nächsten Abfrage der Node.</div>
            <?php else: ?>
            <div class="small">Übernommen <?= ago($c['imported_at']) ?><?= $c['source'] ? ' von <span class="mono">' . esc($c['source']) . '</span>' : '' ?>:
                <?= (int) $c['counts']['domains'] ?> Domains, <?= (int) $c['counts']['profiles'] ?> Profile, <?= (int) $c['counts']['accesses'] ?> Zugänge.
                DKIM-Schlüssel und Hashes verschlüsselt gespeichert, nie angezeigt.</div>
            <?php endif ?>
            <?php foreach ($nodes as $n): $st = $central['nodes'][$n['id'] ?? 0] ?? null; ?>
            <div class="small mt-1"><span class="mono"><?= esc($n['node_key']) ?></span>
                <?php if ($st === null): ?><span class="badge bg-secondary-lt">noch nicht abgeglichen</span>
                <?php elseif ($st['error'] !== null): ?><span class="badge bg-red-lt">Fehler</span> <span class="text-red"><?= esc($st['error']) ?></span>
                <?php elseif ($st['pending_hash'] !== null && $c !== null && $st['pending_hash'] === $c['hash'] && ! $st['in_sync']): ?><span class="badge bg-yellow-lt">wartet auf Freigabe an der Node</span>
                <?php elseif ($st['in_sync']): ?><span class="badge bg-green-lt">synchron</span> <span class="text-secondary">seit <?= dt($st['synced_at']) ?></span>
                <?php else: ?><span class="badge bg-orange-lt">weicht ab</span><?php endif ?>
            </div>
            <?php endforeach ?>
            <?php if ($syncError !== null): ?><div class="small mt-1 <?= $syncError === '' ? 'text-green' : 'text-red' ?>"><?= $syncError === '' ? 'Abgeglichen.' : 'Abgleich: ' . esc($syncError) ?></div><?php endif ?>
        </div>
        <?php if (can_write()): ?><button class="btn btn-sm btn-outline-primary" hx-post="/projects/<?= $project['public_id'] ?>/send/sync" hx-target="#send">Jetzt abgleichen</button><?php endif ?>
    </div>
</div>
<?php foreach ($nodes as $n): $d = $n['data']; ?>
<?php if (count($nodes) > 1): ?><div class="card-body border-bottom bg-light py-2 fw-bold"><?= esc($n['label']) ?> <span class="mono"><?= esc($n['node_key']) ?></span></div><?php endif ?>
<?php if ($d === null): ?>
    <div class="card-body border-bottom"><div class="alert alert-warning mb-0">Node <span class="mono"><?= esc($n['node_key']) ?></span> nicht erreichbar: keine Versanddaten.</div></div>
<?php continue; endif ?>
<?php $q = $d['send_queue'] ?? null; $domains = $d['domains'] ?? []; $profiles = $d['send_profiles'] ?? []; ?>

<div class="card-body border-bottom">
    <h4 class="subheader mb-2">Warteschlange</h4>
<?php if ($q === null): ?>
    <div class="text-secondary small">Die Node ist zu alt für die Warteschlangen-Ansicht (ab Node 0.2.7).</div>
<?php else: ?>
    <div class="row g-2 mb-2">
    <?php foreach ($queueLabels as $k => $l): ?>
        <div class="col-6 col-md-3 col-xl"><div class="border rounded p-2"><div class="small text-secondary"><?= $l ?></div>
            <div class="h3 mb-0<?= $k === 'deferred' && $q[$k] > 0 ? ' text-orange' : '' ?>"><?= (int) $q[$k] ?></div></div></div>
    <?php endforeach ?>
    <?php foreach ($finalLabels as $k => $l): ?>
        <div class="col-6 col-md-3 col-xl"><div class="border rounded p-2"><div class="small text-secondary"><?= $l ?> (24 h)</div>
            <div class="h3 mb-0<?= $k !== 'sent' && $q['last_24h'][$k] > 0 ? ' text-red' : '' ?>"><?= (int) $q['last_24h'][$k] ?></div></div></div>
    <?php endforeach ?>
    </div>
    <div class="small text-secondary">Älteste offene Mail: <?= $q['oldest_open'] ? dt($q['oldest_open']) . ' (' . ago($q['oldest_open']) . ')' : 'keine' ?></div>
<?php endif ?>
</div>

<div class="card-body border-bottom">
    <h4 class="subheader mb-2">Absenderdomains</h4>
<?php if ($domains === []): ?>
    <div class="text-secondary small">Noch keine Domain: Die Anwendung des Kunden legt sie per API an (<code>PUT /v1/domains/{domain}</code>).</div>
<?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-vcenter mb-0">
        <thead><tr><th>Domain</th><th>DKIM</th><th>Test</th><th>Bestanden</th><th>Nächster Test</th><th>Return-Path</th></tr></thead>
        <tbody>
        <?php foreach ($domains as $dm): $t = $dm['test'] ?? []; $failed = $t['details']['failed'] ?? []; ?>
            <tr>
                <td><span class="mono"><?= esc($dm['domain']) ?></span><?= $dm['allowed'] === false ? ' <span class="badge bg-red-lt" title="nicht unter den erlaubten Domains des Projekts: versendet nicht">nicht erlaubt</span>' : '' ?></td>
                <td class="small"><span class="mono"><?= esc($dm['dkim']['selector']) ?></span> · <?= esc($dm['dkim']['algorithm']) ?><?= $dm['dkim']['algorithm'] === 'rsa' ? ' ' . (int) $dm['dkim']['bits'] : '' ?></td>
                <td><?= domain_test_badge($t) ?>
                    <?php if ($failed !== []): ?><div class="small text-red">Fehlt: <?= esc(implode(', ', $failed)) ?></div><?php endif ?>
                    <?php if (! empty($t['error'])): ?><div class="small text-orange" title="letzter Lauf nicht abgeschlossen, Ergebnis davor gilt weiter"><?= esc($t['error']) ?></div><?php endif ?></td>
                <td class="small"><?= dt($t['passed_at'] ?? null) ?></td>
                <td class="small"><?= dt($t['next_at'] ?? null) ?></td>
                <td class="small mono"><?= esc($t['return_path_domain'] ?? '') ?: '<span class="text-secondary">Domain selbst</span>' ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
<?php endif ?>
</div>

<div class="card-body border-bottom">
    <h4 class="subheader mb-2">Sendeprofile und Zugänge</h4>
<?php if ($profiles === []): ?>
    <div class="text-secondary small">Noch kein Sendeprofil: Die Anwendung des Kunden legt es per API an (<code>PUT /v1/send-profiles/{name}</code>).</div>
<?php endif ?>
<?php foreach ($profiles as $pr): ?>
    <div class="border rounded p-2 mb-2">
        <div class="d-flex justify-content-between flex-wrap">
            <div><span class="fw-bold mono"><?= esc($pr['name']) ?></span> <?= $pr['label'] ? '<span class="text-secondary">' . esc($pr['label']) . '</span>' : '' ?>
                <?= $pr['enabled'] ? '<span class="badge bg-green-lt">aktiv</span>' : '<span class="badge bg-secondary-lt">aus</span>' ?></div>
            <div class="small text-secondary">
                Limit <?= (int) ($pr['effective_limit_hour'] ?? $pr['limit_hour']) ?>/h · <?= (int) ($pr['effective_limit_day'] ?? $pr['limit_day']) ?>/Tag<?php if (isset($pr['effective_limit_hour']) && ($pr['effective_limit_hour'] < $pr['limit_hour'] || $pr['effective_limit_day'] < $pr['limit_day'])): ?>
                <span title="Profil <?= (int) $pr['limit_hour'] ?>/h, <?= (int) $pr['limit_day'] ?>/Tag; begrenzt durch das Projekt">(Projekt)</span><?php endif ?>
                · <?= $retry($pr['retry']) ?> · Test alle <?= (int) $pr['test_interval_hours'] ?> h
            </div>
        </div>
        <div class="small mt-1">Absender: <span class="mono"><?= esc(implode(', ', $pr['senders'])) ?></span>
            <?php if (! empty($pr['senders_not_allowed'])): ?><span class="badge bg-red-lt" title="im Projekt nicht (mehr) erlaubt">nicht erlaubt: <?= esc(implode(', ', $pr['senders_not_allowed'])) ?></span><?php endif ?></div>
        <div class="small">Empfänger: <?= $pr['recipients'] === null ? 'alle' : '<span class="mono">' . esc(implode(', ', $pr['recipients'])) . '</span>' ?>
            · Return-Path: <?= $pr['return_path_domain'] ? '<span class="mono">' . esc($pr['return_path_domain']) . '</span>' : 'Absender der Einlieferung' ?></div>
        <?php $list = $pr['access_list'] ?? []; ?>
        <?php if ($list === []): ?><div class="small text-secondary mt-1">Keine Zugänge.</div><?php else: ?>
        <table class="table table-sm mb-0 mt-1">
            <tbody>
            <?php foreach ($list as $a): ?>
                <tr>
                    <td class="w-1"><span class="badge bg-blue-lt"><?= esc(['api' => 'API', 'smtp' => 'SMTP', 'ip' => 'IP'][$a['type']] ?? $a['type']) ?></span></td>
                    <td><?= $access($a) ?><?= $a['label'] ? ' <span class="small text-secondary">' . esc($a['label']) . '</span>' : '' ?></td>
                    <td class="small text-secondary text-nowrap"><?= $a['type'] === 'ip' ? '' : 'benutzt ' . ago($a['last_used_at'] ?? null) ?></td>
                    <td class="w-1"><?= $a['enabled'] ? '<span class="badge bg-green-lt">aktiv</span>' : '<span class="badge bg-secondary-lt">gesperrt</span>' ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        <?php endif ?>
    </div>
<?php endforeach ?>
</div>

<?php if ($q !== null): ?>
<div class="card-body<?= $n === end($nodes) ? '' : ' border-bottom' ?>">
    <h4 class="subheader mb-2">Letzte Mails <span class="text-secondary fw-normal">(offene zuerst, nur Empfängerdomain)</span></h4>
<?php if ($q['recent'] === []): ?>
    <div class="text-secondary small">Noch keine Mails.</div>
<?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-vcenter mb-0">
        <thead><tr><th>Eingeliefert</th><th>Mail</th><th>Profil</th><th>Empfängerdomain</th><th>Status</th><th>Versuche</th><th>Letzte Antwort</th><th>Nächster Versuch</th></tr></thead>
        <tbody>
        <?php foreach ($q['recent'] as $m): $last = $m['last']; ?>
            <tr>
                <td class="small text-nowrap"><?= dt($m['created_at'], 'd.m. H:i:s') ?></td>
                <td class="mono small" title="Einlieferung <?= esc($m['submission_id'], 'attr') ?>"><?= esc($m['message_id']) ?></td>
                <td class="mono small"><?= esc($m['profile']) ?></td>
                <td class="mono small"><?= esc($m['recipient_domain']) ?></td>
                <td><?= send_status_badge($m['status']) ?><?= $m['reason'] ? '<div class="small text-secondary">' . esc($m['reason']) . '</div>' : '' ?></td>
                <td class="small"><?= (int) $m['attempts'] ?></td>
                <td class="small" style="max-width: 22rem"><?php if ($last !== null): ?><span class="d-inline-block text-truncate mw-100 align-bottom" title="<?= esc((string) $last['response'], 'attr') ?>"><?= (int) $last['code'] ?> <?= esc((string) $last['enhanced']) ?> <?= esc((string) $last['response']) ?></span><?php else: ?>–<?php endif ?></td>
                <td class="small text-nowrap"><?= in_array($m['status'], ['queued', 'deferred', 'waiting_test'], true) ? dt($m['next_attempt_at'], 'd.m. H:i') : '–' ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
<?php endif ?>
</div>
<?php endif ?>
<?php endforeach ?>
