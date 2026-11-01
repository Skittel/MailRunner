<?php

namespace App\Commands;

use App\Core\Support\ChangeContext;
use App\Core\Support\Transaction;
use App\Modules\Receive\Libraries\ConfigApplier;
use App\Modules\Receive\Libraries\ConfigBuilder;
use CodeIgniter\CLI\CLI;

/**
 * mr:apply - generates the mail path files from the database and activates them.
 * Run as root on a node (writes /etc/mailrunner/generated, reloads Postfix).
 */
class Apply extends MrCommand
{
    protected $name        = 'mr:apply';
    protected $description = 'Generates Postfix maps and routes.json from the database and activates them.';
    protected $usage       = 'mr:apply [--no-reload] [--json]';
    protected $options     = ['--no-reload' => 'Do not reload Postfix', '--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $db     = db_connect();
        $files  = (new ConfigBuilder($db))->build();
        $result = (new ConfigApplier(config('Mailrunner')->generatedDir()))->apply($files, ! $this->flag('no-reload'));

        if ($result['changed']) {
            Transaction::run($db, static function () use ($result): void {
                service('journalWriter')->append('config.applied', ChangeContext::cli(), null, null, [
                    'hash' => $result['hash'], 'reloaded' => $result['reloaded'],
                ]);
            });
        }

        if ($this->json()) {
            $this->outputJson($result);
        } else {
            foreach ($result['messages'] as $message) {
                CLI::write($message);
            }
        }

        return null;
    }
}
