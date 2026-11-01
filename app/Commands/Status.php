<?php

namespace App\Commands;

use App\Core\Node\NodeInfo;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * mr:status - node status for humans and monitoring (ArgusControl).
 *
 * Exit codes: 0 = ok, 1 = warning, 2 = error.
 */
class Status extends BaseCommand
{
    public const EXIT_OK      = 0;
    public const EXIT_WARNING = 1;
    public const EXIT_ERROR   = 2;

    protected $group       = 'MailRunner';
    protected $name        = 'mr:status';
    protected $description = 'Shows node status. Exit code 0 = ok, 1 = warning, 2 = error.';
    protected $usage       = 'mr:status [--json]';
    protected $options     = ['--json' => 'Machine-readable output'];

    public function run(array $params)
    {
        $json = array_key_exists('json', $params) || CLI::getOption('json') !== null;

        try {
            $info   = (new NodeInfo(config('Mailrunner')))->toArray();
            $checks = [];
            $status = self::EXIT_OK;

            if ($info['node_id'] === '') {
                $checks[] = ['check' => 'node_id', 'status' => 'warning', 'message' => 'MAILRUNNER_NODE_ID not set'];
                $status   = self::EXIT_WARNING;
            }
            if ($info['roles'] === []) {
                $checks[] = ['check' => 'roles', 'status' => 'warning', 'message' => 'No role enabled'];
                $status   = self::EXIT_WARNING;
            }
        } catch (Throwable $e) {
            $info   = [];
            $checks = [['check' => 'node', 'status' => 'error', 'message' => $e->getMessage()]];
            $status = self::EXIT_ERROR;
        }

        $result = ['status' => ['ok', 'warning', 'error'][$status]] + $info + ['checks' => $checks];

        if ($json) {
            CLI::write(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            CLI::write('Status:  ' . $result['status']);
            CLI::write('Node:    ' . ($info['node_id'] ?? '-'));
            CLI::write('Version: ' . ($info['version'] ?? '-'));
            CLI::write('Roles:   ' . implode(', ', $info['roles'] ?? []));
            foreach ($checks as $check) {
                CLI::write(sprintf('  [%s] %s: %s', $check['status'], $check['check'], $check['message']));
            }
        }

        return $status;
    }
}
