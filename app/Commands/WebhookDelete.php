<?php

namespace App\Commands;

/**
 * mr:webhook:delete <customer/project> - operator removes a project's webhook (e.g. a target that misbehaves).
 */
class WebhookDelete extends MrCommand
{
    protected $name        = 'mr:webhook:delete';
    protected $description = 'Removes the webhook of a project.';
    protected $usage       = 'mr:webhook:delete <customer/project>';

    protected function execute(): ?int
    {
        $p = service('projectService')->get($this->requireArg(0, 'customer/project'));
        $this->ok(service('webhookService')->delete((int) $p['id'], $this->context()) ? 'Webhook removed' : 'No webhook set');

        return null;
    }
}
