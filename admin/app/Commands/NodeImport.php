<?php

namespace App\Commands;

use App\Libraries\Sync;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** admin:node:import <node_key> - takes over customers/projects that exist only on the node. */
class NodeImport extends BaseCommand
{
    protected $group       = 'MailRunner Admin';
    protected $name        = 'admin:node:import';
    protected $description = 'Takes over customers and projects that exist only on the node.';
    protected $usage       = 'admin:node:import <node_key>';

    public function run(array $params)
    {
        $node = db_connect()->table('nodes')->where('node_key', $params[0] ?? '')->get()->getRowArray();
        if ($node === null) {
            CLI::error('Usage: ' . $this->usage);

            return 1;
        }
        $stats = (new Sync(config('Admin')))->import($node);
        CLI::write(sprintf('Imported %d customers, %d projects.', $stats['customers'], $stats['projects']), 'green');

        return 0;
    }
}
