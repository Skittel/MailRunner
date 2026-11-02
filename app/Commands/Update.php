<?php

namespace App\Commands;

use App\Core\Node\Updater;
use App\Core\Support\ChangeContext;
use App\Core\Support\Transaction;
use CodeIgniter\CLI\CLI;

/**
 * mr:update - software update from the git tags of the source checkout. Shows what it would fetch and change and
 * asks; --silent runs without asking (RMM, scripts). --from-admin is used by the mailrunner-update unit when
 * MailRunner Admin requests a version: it installs only with MAILRUNNER_ADMIN_UPDATES=auto and never downgrades.
 */
class Update extends MrCommand
{
    protected $name        = 'mr:update';
    protected $description = 'Updates the node from a git tag (shows the changes and asks; --silent without asking).';
    protected $usage       = 'mr:update [--version=n.n.n] [--silent] [--allow-downgrade] [--force] [--from-admin]';
    protected $options     = [
        '--version'         => 'Target version (default: requested by MailRunner Admin, else the newest tag)',
        '--silent'          => 'Do not ask (RMM, scripts)',
        '--allow-downgrade' => 'Allow an older version (CLI only)',
        '--force'           => 'Discard local changes in the source checkout',
        '--from-admin'      => 'Called by mailrunner-update.service after a request of MailRunner Admin',
    ];

    protected function execute(): ?int
    {
        $config    = config('Mailrunner');
        $updater   = Updater::forNode();
        $fromAdmin = $this->flag('from-admin');
        if ($fromAdmin && $config->adminUpdates === 'manual') {
            $req = $updater->requested();
            $this->journal('node.update_available', ['version' => $req['version'] ?? null, 'mode' => 'manual']);
            CLI::write('MAILRUNNER_ADMIN_UPDATES=manual: update to ' . ($req['version'] ?? '?') . ' noted, install with: sudo mailrunner mr:update');

            return null;
        }
        $plan = $updater->plan($this->optionString('version'), ! $fromAdmin && $this->flag('allow-downgrade'), ! $fromAdmin && $this->flag('force'));
        CLI::write(sprintf('Installed: %s   Target: %s   Source: %s', $plan['current'], $plan['target'] ?? '-', $plan['source']));
        if ($plan['files'] !== '') {
            CLI::write('Changes: ' . count($plan['commits']) . ' commits, ' . $plan['files']);
        }
        if ($plan['changelog'] !== '') {
            CLI::newLine();
            CLI::write($plan['changelog']);
        }
        if ($plan['problems'] !== []) {
            foreach ($plan['problems'] as $p) {
                CLI::error('Not possible: ' . $p);
            }
            if ($fromAdmin) {
                $this->journal('node.update_refused', ['version' => $plan['target'], 'problems' => $plan['problems']]);
            }

            return str_starts_with($plan['problems'][0], 'already at') ? null : 1;
        }
        if (! $this->flag('silent') && ! $fromAdmin && CLI::prompt('Install v' . $plan['target'] . '?', ['n', 'y']) !== 'y') {
            CLI::write('Cancelled.');

            return null;
        }
        $code = $updater->run((string) $plan['target'], ! $fromAdmin && $this->flag('force'), static fn (string $line) => CLI::write($line));
        $this->journal($code === 0 ? 'node.updated' : 'node.update_failed', ['from' => $plan['current'], 'to' => $plan['target'], 'exit' => $code, 'by' => $fromAdmin ? 'admin request' : 'cli']);
        $code === 0 ? $this->ok('Updated to ' . $plan['target'] . '.') : CLI::error('Installer failed (exit ' . $code . ').');

        return $code === 0 ? null : 1;
    }

    private function journal(string $event, array $details): void
    {
        $ctx = $this->flag('from-admin') ? new ChangeContext('system', 'mailrunner-update') : $this->context();
        Transaction::run(db_connect(), fn () => service('journalWriter')->append($event, $ctx, null, null, $details));
    }
}
