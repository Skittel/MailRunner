<?php

namespace App\Commands;

use App\Libraries\Sync;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** admin:project:push <customer/project> | --all - pushes the stored configuration to the node(s) again. */
class ProjectPush extends BaseCommand
{
    protected $group       = 'MailRunner Admin';
    protected $name        = 'admin:project:push';
    protected $description = 'Pushes the stored project configuration to its node (or all projects with --all).';
    protected $usage       = 'admin:project:push <customer/project> | --all';

    public function run(array $params)
    {
        $b = db_connect()->table('projects p')->select('p.id, c.customer_key, p.project_key')->join('customers c', 'c.id = p.customer_id');
        if (! array_key_exists('all', $params) && ! in_array('--all', $_SERVER['argv'] ?? [], true)) {
            [$c, $p] = array_pad(explode('/', $params[0] ?? ''), 2, '');
            $b->where('c.customer_key', $c)->where('p.project_key', $p);
        }
        $rows = $b->get()->getResultArray();
        if ($rows === []) {
            CLI::error('No project. Usage: ' . $this->usage);

            return 1;
        }
        $failed = 0;
        foreach ($rows as $r) {
            $err = (new Sync(config('Admin')))->pushProject((int) $r['id']);
            CLI::write(sprintf('%-40s %s', $r['customer_key'] . '/' . $r['project_key'], $err ?? 'ok'), $err === null ? 'green' : 'red');
            $failed += $err === null ? 0 : 1;
        }

        return $failed > 0 ? 1 : 0;
    }
}
