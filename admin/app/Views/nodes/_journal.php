<div class="table-responsive" style="max-height: 70vh"><table class="table table-sm card-table">
<thead><tr><th>Zeit</th><th>Ereignis</th><th>Projekt</th><th>Details</th></tr></thead><tbody>
<?php foreach ($events as $e): $d = $e['details'] ?? []; ?>
<tr><td class="text-nowrap small"><?= dt($e['created_at'], 'd.m. H:i:s') ?></td><td class="mono small"><?= esc($e['event']) ?></td>
    <td class="mono small"><?= esc($e['project'] ?? ($e['customer'] ?? '–')) ?></td>
    <td class="small"><?php
        if (str_starts_with($e['event'], 'smtp.')) {
            echo esc(($d['client']['name'] ?? '') . ' [' . ($d['client']['ip'] ?? '') . ']');
            if (isset($d['mail_from'])) echo ' · ' . esc($d['mail_from'] ?: '<>') . ' → ' . esc(implode(', ', array_column($d['recipients'] ?? [], 'rcpt')));
            echo ' · ' . esc($d['result']['status'] ?? $d['end'] ?? '') . (isset($d['duration_ms']) ? ' · ' . (int) $d['duration_ms'] . ' ms' : '');
        } elseif ($e['event'] === 'node.selftest') {
            echo esc(($d['status'] ?? '') . ' · ' . ($d['ok'] ?? 0) . ' ok' . (! empty($d['failed']) ? ' · Fehler: ' . implode(', ', array_column($d['failed'], 'name')) : ''));
        } else {
            echo '<span class="mono">' . esc(mb_strimwidth(json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', 0, 160, '…')) . '</span>';
        } ?></td></tr>
<?php endforeach ?>
<?php if ($events === []): ?><tr><td colspan="4" class="text-secondary">Keine Einträge.</td></tr><?php endif ?>
</tbody></table></div>
