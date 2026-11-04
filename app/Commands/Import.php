<?php

namespace App\Commands;

use App\Core\Secrets\NodeCrypto;
use App\Modules\Receive\Libraries\Importer;
use App\Modules\Receive\Libraries\SmtpSessionImporter;

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
        $crypto = NodeCrypto::forNode();
        // messages first, so that smtp.attempt events can name their message ids
        $importer = new Importer(db_connect(), service('journalWriter'), $crypto, $config->receiveSpoolDir());
        $stats    = $importer->run();
        // after commit: the mail can be fetched now, so the webhook ping cannot arrive too early
        service('webhookService')->markPending($importer->importedProjectIds());
        $smtp  = (new SmtpSessionImporter(db_connect(), service('journalWriter'), $crypto, $config->receiveSpoolDir()))->run();
        $this->json() ? $this->outputJson($stats + ['smtp_sessions' => $smtp]) : $this->ok(sprintf(
            'Messages: imported %d, duplicates %d, failed %d. SMTP sessions: imported %d, duplicates %d, failed %d',
            $stats['imported'], $stats['duplicates'], $stats['failed'], $smtp['imported'], $smtp['duplicates'], $smtp['failed'],
        ));

        return ($stats['failed'] + $smtp['failed']) > 0 ? 1 : null;
    }
}
