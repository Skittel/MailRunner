<?php

namespace App\Commands;

use App\Core\Secrets\NodeCrypto;
use App\Modules\Receive\Libraries\Importer;

/**
 * mr:import - spool/analyzed/ -> database (started by systemd path unit as user mailrunner).
 */
class Import extends MrCommand
{
    protected $name        = 'mr:import';
    protected $description = 'Imports analysed messages from the spool into the database.';
    protected $usage       = 'mr:import [--json]';
    protected $options     = ['--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $config = config('Mailrunner');
        $stats  = (new Importer(db_connect(), service('journalWriter'), NodeCrypto::forNode(), $config->receiveSpoolDir()))->run();
        $this->json() ? $this->outputJson($stats) : $this->ok(sprintf('Imported %d, duplicates %d, failed %d', $stats['imported'], $stats['duplicates'], $stats['failed']));

        return $stats['failed'] > 0 ? 1 : null;
    }
}
