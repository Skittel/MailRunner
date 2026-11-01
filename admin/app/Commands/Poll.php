<?php

namespace App\Commands;

use App\Libraries\Sync;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** admin:poll - polls every enabled node (systemd timer every minute). */
class Poll extends BaseCommand
{
    protected $group       = 'MailRunner Admin';
    protected $name        = 'admin:poll';
    protected $description = 'Polls the status of all enabled nodes.';

    public function run(array $params)
    {
        $sync = new Sync(config('Admin'));
        foreach (db_connect()->table('nodes')->where('enabled', 1)->get()->getResultArray() as $node) {
            $sync->poll($node);
            $row = db_connect()->table('nodes')->select('last_error')->where('id', $node['id'])->get()->getRow();
            CLI::write(sprintf('%-24s %s', $node['node_key'], $row->last_error === null ? 'ok' : 'error: ' . $row->last_error));
        }
    }
}
