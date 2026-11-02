<?php

namespace App\Commands;

use App\Core\Node\SecretScan;
use CodeIgniter\CLI\CLI;

/**
 * mr:secrets:scan - looks for secret values (/etc/mailrunner/*.env, node key) in the node database and the
 * log/data files. Prints names and locations only, never values. Also part of the self test (base.secret_leak).
 */
class SecretsScan extends MrCommand
{
    protected $name        = 'mr:secrets:scan';
    protected $description = 'Searches the database and log files for secret values from /etc/mailrunner/*.env (names only).';
    protected $usage       = 'mr:secrets:scan [--json]';
    protected $options     = ['--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $r = SecretScan::forNode(db_connect())->run();
        if ($this->json()) {
            $this->outputJson($r);

            return $r['findings'] === [] ? null : 1;
        }
        foreach ($r['findings'] as $f) {
            CLI::write(sprintf('FOUND %-36s %s (%dx)', $f['secret'], $f['where'], $f['count']), 'red');
        }
        foreach ($r['skipped'] as $s) {
            CLI::write('not readable: ' . $s, 'yellow');
        }
        $summary = sprintf('%d secret values checked in %d sources: %d findings', $r['checked'], $r['sources'], count($r['findings']));
        $r['findings'] === [] ? $this->ok($summary) : CLI::error($summary);

        return $r['findings'] === [] ? null : 1;
    }
}
