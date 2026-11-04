<?php

namespace App\Commands;

use App\Core\Webhooks\WebhookSender;

/**
 * mr:webhook:send - sends pending webhook pings (started by mailrunner-webhook.path as user mailrunner).
 */
class WebhookSend extends MrCommand
{
    protected $name        = 'mr:webhook:send';
    protected $description = 'Sends pending webhook pings (runs until nothing is pending).';
    protected $usage       = 'mr:webhook:send [--json]';
    protected $options     = ['--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $stats = (new WebhookSender(db_connect(), service('journalWriter'), service('webhookService'), null,
            config('Mailrunner')->dataDir . '/webhook.request'))->run();
        $this->json() ? $this->outputJson($stats) : $this->ok(sprintf('Webhook pings: sent %d, failed %d', $stats['sent'], $stats['failed']));

        return null;
    }
}
