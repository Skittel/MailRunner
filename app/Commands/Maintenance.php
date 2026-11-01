<?php

namespace App\Commands;

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
        $stats = (new Retention(db_connect(), service('journalWriter')))->run();
        $this->json() ? $this->outputJson($stats) : $this->ok(sprintf('Content deleted %d, expired %d, metadata purged %d', $stats['deleted'], $stats['expired'], $stats['purged']));

        return null;
    }
}
