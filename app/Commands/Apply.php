<?php

namespace App\Commands;

use App\Core\Projects\ProjectSecretService;
use App\Core\Secrets\NodeCrypto;
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
        $crypto = NodeCrypto::forNode();
        $files  = (new ConfigBuilder($db, new ProjectSecretService($db, service('journalWriter'), service('projectService'), $crypto)))->build();
        $result = (new ConfigApplier(config('Mailrunner')->generatedDir(), $crypto, ConfigBuilder::ENCRYPTED_FILES))
            ->apply($files, ! $this->flag('no-reload'));

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
