<?php

namespace App\Commands;

use App\Core\Node\SelfTest;

/**
 * mr:selftest - self test of the node (hourly timer and after each install), result in the journal.
 */
class SelfTestCommand extends MrCommand
{
    protected $name        = 'mr:selftest';
    protected $description = 'Runs the node self test; the result is written to the journal (node.selftest).';
    protected $usage       = 'mr:selftest [--base-only] [--json]';
    protected $options     = [
        '--base-only' => 'Only the base checks, no test mails',
        '--json'      => 'JSON output (full result)',
    ];

    protected function execute(): ?int
    {
        $config   = config('Mailrunner');
        $hostname = (string) parse_url((string) config('App')->baseURL, PHP_URL_HOST);
        if ($hostname === '') {
            throw new \RuntimeException('app.baseURL has no host name');
        }
        $selftest = new SelfTest(db_connect(), service('journalWriter'), ROOTPATH . 'mailpath/receive/bin/mr-selftest', $config->dataDir . '/selftest');
        $result   = $selftest->run($hostname, $this->option('base-only') !== null, $config->mxNames);

        if ($this->json()) {
            $this->outputJson($result);
        } else {
            foreach ($result['checks'] ?? [] as $c) {
                \CodeIgniter\CLI\CLI::write(sprintf('%-5s %-36s %s', strtoupper($c['status']), $c['name'], $c['detail']));
            }
            $summary = sprintf('Self test %s: %d ok, %d failed, %d warnings, %.1f s', strtoupper($result['status']), $result['ok'] ?? 0,
                count($result['failed'] ?? []), count($result['warnings'] ?? []), ($result['duration_ms'] ?? 0) / 1000);
            $result['status'] === 'pass' ? $this->ok($summary) : \CodeIgniter\CLI\CLI::error($summary . (isset($result['error']) ? ' - ' . $result['error'] : ''));
        }

        return match ($result['status']) {
            'pass'  => null,
            'fail'  => 1,
            default => 2,
        };
    }
}
