<?php

namespace App\Commands;

use App\Modules\Receive\Libraries\MessageStore;
use App\Modules\Receive\Libraries\Retention;

/**
 * mr:maintenance - applies deletion periods (systemd timer).
 */
class Maintenance extends MrCommand
{
    protected $name        = 'mr:maintenance';
    protected $description = 'Applies deletion periods (content after ack, expiry, metadata).';
    protected $usage       = 'mr:maintenance [--json]';
    protected $options     = ['--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $stats = (new Retention(db_connect(), service('journalWriter'), MessageStore::forNode(), config('Mailrunner')->smtpJournalDays))->run();
        $send  = (new \App\Core\Send\SendRetention(db_connect(), config('Mailrunner')->sendStoreDir()))->run();
        $stats += ['send_raw_deleted' => $send['raw_deleted'], 'send_purged' => $send['purged']];
        $this->json() ? $this->outputJson($stats) : $this->ok(sprintf('Content deleted %d, expired %d, metadata purged %d, smtp journal entries purged %d; send: mails deleted %d, submissions purged %d',
            $stats['deleted'], $stats['expired'], $stats['purged'], $stats['smtp_journal'], $stats['send_raw_deleted'], $stats['send_purged']));

        return null;
    }
}
