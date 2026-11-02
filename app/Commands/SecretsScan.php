<?php

namespace App\Commands;

use App\Core\Node\SecretScan;
use App\Core\Support\Transaction;
use CodeIgniter\CLI\CLI;

/**
 * mr:secrets:scan - looks for secret values (/etc/mailrunner/*.env, node key) in the node database and the
 * log/data files. Prints names and locations only, never values. Also part of the self test (base.secret_leak).
 */
class SecretsScan extends MrCommand
{
    protected $name        = 'mr:secrets:scan';
    protected $description = 'Searches the database and log files for secret values from /etc/mailrunner/*.env (names only).';
    protected $usage       = 'mr:secrets:scan [--accept] [--json]';
    protected $options     = [
        '--accept' => 'Accept the current findings (e.g. development node); new findings still fail',
        '--json'   => 'JSON output',
    ];

    protected function execute(): ?int
    {
        $scan = SecretScan::forNode(db_connect());
        $r    = $scan->run();
        if ($this->option('accept') !== null) {
            $n = $scan->accept($r['findings']);
            $details = ['findings' => array_map(static fn ($f) => ['secret' => $f['secret'], 'where' => $f['where'], 'count' => $f['count']], $r['findings'])];
            Transaction::run(db_connect(), fn () => service('journalWriter')->append('node.secrets_accepted', $this->context(), null, null, $details));
            $this->ok(sprintf('%d finding(s) accepted. New findings still fail the self test.', $n));

            return null;
        }
        if ($this->json()) {
            $this->outputJson($r);

            return array_filter($r['findings'], static fn ($f) => ! $f['accepted']) === [] ? null : 1;
        }
        foreach ($r['findings'] as $f) {
            CLI::write(sprintf('%-8s %-36s %s (%dx)', $f['accepted'] ? 'ACCEPTED' : 'FOUND', $f['secret'], $f['where'], $f['count']), $f['accepted'] ? 'yellow' : 'red');
        }
        foreach ($r['skipped'] as $s) {
            CLI::write('not readable: ' . $s, 'yellow');
        }
        $open    = count(array_filter($r['findings'], static fn ($f) => ! $f['accepted']));
        $summary = sprintf('%d secret values checked in %d sources: %d open, %d accepted', $r['checked'], $r['sources'], $open, count($r['findings']) - $open);
        $open === 0 ? $this->ok($summary) : CLI::error($summary);

        return $open === 0 ? null : 1;
    }
}
