<?php if ($error): ?><div class="card-body"><div class="alert alert-danger mb-0">Node nicht abrufbar: <?= esc($error) ?></div></div><?php elseif ($mails === []): ?>
<div class="empty"><p class="empty-title">Keine Datensätze</p><p class="empty-subtitle text-secondary">Keine Mails an postmaster@ oder abuse@<?= service('request')->getGet('selftests') === '1' ? '' : ' (Selbsttests ausgeblendet)' ?>.</p></div>
<?php else: ?>
<div class="table-responsive"><table class="table table-vcenter card-table table-hover">
<thead><tr><th>Eingang</th><th>An</th><th>Von</th><th>Betreff</th><th>Projekt</th><th>Status</th></tr></thead><tbody>
<?php foreach ($mails as $m): ?>
<tr>
    <td class="text-nowrap small"><?= dt($m['received_at']) ?></td><td><span class="badge bg-purple-lt"><?= esc($m['role']) ?></span> <span class="small mono"><?= esc($m['envelope']['rcpt']) ?></span></td>
    <td class="small"><?= esc($m['header']['from'] ?? $m['envelope']['mail_from']) ?></td><td><a href="/role-mails/<?= $node['public_id'] ?>/<?= esc($m['message_id'], 'url') ?>"><?= esc($m['header']['subject'] ?? '(kein Betreff)') ?></a></td>
    <td class="mono small"><?= esc($m['project']) ?></td>
    <td><?= $m['content_deleted_at'] ? '<span class="badge bg-secondary-lt">gelöscht</span>' : ($m['acked_at'] ? '<span class="badge bg-green-lt">abgeholt</span>' : '<span class="badge bg-yellow-lt">offen</span>') ?></td></tr>
<?php endforeach ?>
</tbody></table></div><?php endif ?>
