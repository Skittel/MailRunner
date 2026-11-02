<?php

namespace App\Commands;

use App\Libraries\Clock;
use App\Libraries\Sync;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** admin:node:add <node_key> <https://host> [name] - registers a node (bootstrap without the GUI). */
class NodeAdd extends BaseCommand
{
    protected $group       = 'MailRunner Admin';
    protected $name        = 'admin:node:add';
    protected $description = 'Registers a node and polls it once.';
    protected $usage       = 'admin:node:add <node_key> <https://host> [name]';

    public function run(array $params)
    {
        [$key, $url, $name] = [$params[0] ?? '', rtrim($params[1] ?? '', '/'), $params[2] ?? ''];
        if (! preg_match('/^[a-z0-9][a-z0-9.-]{1,62}$/', $key) || ! preg_match('#^https://[a-z0-9.-]+(:\d+)?$#', $url)) {
            CLI::error('Usage: ' . $this->usage);

            return 1;
        }
        $db = db_connect();
        if ($db->table('nodes')->where('node_key', $key)->countAllResults() > 0) {
            CLI::write('Node ' . $key . ' exists.');
        } else {
            $now = Clock::now();
            $db->table('nodes')->insert(['public_id' => \App\Libraries\PublicId::generate('nodes'), 'node_key' => $key, 'name' => $name ?: $key, 'base_url' => $url, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now]);
            CLI::write('Node ' . $key . ' added.', 'green');
        }
        $node = $db->table('nodes')->where('node_key', $key)->get()->getRowArray();
        (new Sync(config('Admin')))->poll($node);
        $node = $db->table('nodes')->where('node_key', $key)->get()->getRowArray();
        CLI::write($node['last_error'] === null ? 'Poll ok.' : 'Poll failed: ' . $node['last_error'], $node['last_error'] === null ? 'green' : 'red');

        return $node['last_error'] === null ? 0 : 1;
    }
}
