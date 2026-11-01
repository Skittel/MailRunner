<?php

namespace App\Commands;

use CodeIgniter\CLI\CLI;

/**
 * mr:dns:flush - empties the node resolver cache (unbound). Run as root.
 */
class DnsFlush extends MrCommand
{
    protected $name        = 'mr:dns:flush';
    protected $description = 'Empties the DNS cache of the node resolver (unbound), all or one domain.';
    protected $usage       = 'mr:dns:flush [<domain>]';

    protected function execute(): ?int
    {
        $domain   = $this->arg(0);
        $commands = $domain === null
            ? ['flush_zone .', 'flush_negative', 'flush_bogus', 'flush_infra all']
            : ['flush_zone ' . $domain];
        if ($domain !== null && preg_match('/^(?=.{1,253}$)([a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9])?\.)*[a-z0-9-]{1,63}\.?$/i', $domain) !== 1) {
            throw new \InvalidArgumentException('Invalid domain "' . $domain . '"');
        }
        foreach ($commands as $command) {
            exec('/usr/sbin/unbound-control ' . $command . ' 2>&1', $out, $code);
            if ($code !== 0) {
                throw new \RuntimeException('unbound-control ' . $command . ' failed: ' . implode(' ', $out) . ' (run as root)');
            }
            $out = [];
        }
        $this->ok($domain === null ? 'DNS cache emptied.' : 'DNS cache emptied for ' . $domain . ' and below.');

        return null;
    }
}
