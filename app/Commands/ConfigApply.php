<?php

namespace App\Commands;

use App\Core\Admin\AdminChanges;
use App\Core\Admin\PendingChanges;
use CodeIgniter\CLI\CLI;

/**
 * mr:config:apply - applies configuration from MailRunner Admin that waits on the node
 * (MAILRUNNER_ADMIN_CONFIG=manual). Shows every change and asks; --silent applies without asking, --discard drops.
 */
class ConfigApply extends MrCommand
{
    protected $name        = 'mr:config:apply';
    protected $description = 'Applies configuration changes from MailRunner Admin waiting for approval (shows them and asks).';
    protected $usage       = 'mr:config:apply [--silent] [--discard] [--json]';
    protected $options     = [
        '--silent'  => 'Apply all without asking (RMM, scripts)',
        '--discard' => 'Drop all waiting changes',
        '--json'    => 'Only list the waiting changes as JSON',
    ];

    protected function execute(): ?int
    {
        $queue   = new PendingChanges();
        $changes = new AdminChanges();
        $open    = $queue->open();
        if ($this->json()) {
            $this->outputJson(array_map(static fn ($c) => ['id' => (int) $c['id'], 'kind' => $c['kind'], 'ref' => $c['ref'], 'actor' => $c['actor'],
                'received_at' => $c['received_at'], 'changes' => $changes->preview($c['kind'], $c['ref'], $c['payload'])], $open));

            return null;
        }
        if ($open === []) {
            $this->ok('No configuration changes waiting.');

            return null;
        }
        $by     = (string) ($this->context()->actor ?? 'cli');
        $failed = 0;
        foreach ($open as $c) {
            CLI::write(sprintf('#%d %s %s  (from %s, %s)', $c['id'], $c['kind'], $c['ref'], $c['actor'], $c['received_at']), 'yellow');
            $diff = $changes->preview($c['kind'], $c['ref'], $c['payload']);
            foreach ($diff as $field => [$old, $new]) {
                CLI::write(sprintf('    %-24s %s -> %s', $field, $old === null ? '(new)' : self::show($old), self::show($new)));
            }
            if ($diff === []) {
                CLI::write('    no change');
            }
            if ($this->flag('discard')) {
                $queue->discard($c, $by);
                CLI::write('    discarded');

                continue;
            }
            $answer = $this->flag('silent') ? 'y' : CLI::prompt('    Apply?', ['y', 'n', 'd']);
            if ($answer === 'd') {
                $queue->discard($c, $by);
                CLI::write('    discarded');
            } elseif ($answer === 'y') {
                $err = $queue->apply($c, $by);
                $err === null ? CLI::write('    applied', 'green') : CLI::error('    failed: ' . $err);
                $failed += $err === null ? 0 : 1;
            } else {
                CLI::write('    left waiting');
            }
        }

        return $failed === 0 ? null : 1;
    }

    private static function show(mixed $v): string
    {
        return is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
