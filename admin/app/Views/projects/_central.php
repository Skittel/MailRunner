<?php
/**
 * State of the central copy (AccessConfigs) and of every node of the project, with "Jetzt abgleichen". Used by the
 * send card and the access card of receive projects.
 *
 * @var array $project
 * @var list<array{id: int, node_key: string}> $nodes
 * @var array{central: array|null, nodes: array} $central
 * @var array{0: string, 1: string}|null $notice
 */
$central ??= ['central' => null, 'nodes' => []];
$notice ??= null;
$c = $central['central'];
$labels = ['domains' => 'Domains', 'profiles' => 'Profile', 'accesses' => 'Zugänge', 'smtp_credentials' => 'SMTP-Zugänge'];
$parts = [];
foreach ($labels as $k => $l) {
    if (isset($c['counts'][$k])) {
        $parts[] = (int) $c['counts'][$k] . ' ' . $l;
    }
}
if (isset($c['counts']['webhook'])) {
    $parts[] = $c['counts']['webhook'] ? 'Webhook gesetzt' : 'kein Webhook';
}
$send = ($project['type'] ?? 'receive') === 'send';
?>
<div class="card-body border-bottom">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h4 class="subheader mb-1">Zentrale Konfiguration</h4>
            <?php if ($c === null): ?>
            <div class="small text-secondary">Noch nicht übernommen. Der Admin übernimmt <?= $send ? 'Domains, Profile, Zugänge' : 'SMTP-Zugänge' ?> und Webhook bei der nächsten Abfrage der Node.</div>
            <?php else: ?>
            <div class="small">Übernommen <?= ago($c['imported_at']) ?><?= $c['source'] ? ' von <span class="mono">' . esc($c['source']) . '</span>' : '' ?>:
                <?= esc(implode(', ', $parts)) ?>.
                <?= $send ? 'DKIM-Schlüssel, Hashes und Webhook-Adresse' : 'Kennwort-Hashes und Webhook-Adresse' ?> verschlüsselt gespeichert, nie angezeigt.</div>
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
            <?php if ($notice !== null): ?><div class="small mt-1 <?= $notice[0] === 'ok' ? 'text-green' : 'text-red' ?>"><?= esc($notice[1]) ?></div><?php endif ?>
        </div>
        <?php if (can_write()): ?><button class="btn btn-sm btn-outline-primary" hx-post="/projects/<?= $project['public_id'] ?>/config/sync" hx-target="<?= $send ? '#send' : '#access' ?>">Jetzt abgleichen</button><?php endif ?>
    </div>
</div>
