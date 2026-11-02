<?php if ($rows === []): ?><div class="text-secondary small">Hostname eingeben.</div><?php else: ?>
<table class="table table-sm table-vcenter mb-0"><thead><tr><th>Name</th><th>A</th><th>AAAA</th><th>PTR</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
<tr><td class="mono small"><?= esc($r['name']) ?> <span class="text-secondary">(IPv<?= esc($r['fams']) ?>)</span></td>
    <td class="mono small"><?= esc(implode(', ', $r['v4']) ?: '–') ?></td><td class="mono small"><?= esc(implode(', ', $r['v6']) ?: '–') ?></td>
    <td class="small"><?php foreach ($r['ptr'] as $ip => $h): ?><div class="mono"><?= esc($h) ?></div><?php endforeach ?><?= $r['ptr'] === [] ? '–' : '' ?></td>
    <td><?= $r['problems'] === [] ? '<span class="badge bg-green-lt">ok</span>' : '<span class="badge bg-red-lt">' . esc(implode(', ', $r['problems'])) . '</span>' ?></td></tr>
<?php endforeach ?>
</tbody></table>
<div class="form-hint mt-1">Sicht der Admin-Instanz. Der Installer prüft auf der Node zusätzlich, ob die Adressen zur Node gehören.</div>
<?php endif ?>
