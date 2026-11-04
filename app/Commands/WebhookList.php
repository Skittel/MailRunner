<?php

namespace App\Commands;

use App\Core\Webhooks\WebhookService;

/**
 * mr:webhook:list - webhooks of all projects (URL shortened). The customer sets them over the API (PUT /v1/webhook).
 */
class WebhookList extends MrCommand
{
    protected $name        = 'mr:webhook:list';
    protected $description = 'Lists the webhooks of all projects (URL shortened).';
    protected $usage       = 'mr:webhook:list [--json]';
    protected $options     = ['--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $rows = db_connect()->table('project_webhooks w')->select('w.*, c.customer_key, p.project_key')
            ->join('projects p', 'p.id = w.project_id')->join('customers c', 'c.id = p.customer_id')
            ->orderBy('c.customer_key')->orderBy('p.project_key')->get()->getResultArray();
        $out = array_map(static fn ($r) => ['project' => $r['customer_key'] . '/' . $r['project_key']] + WebhookService::present($r), $rows);
        $this->json() ? $this->outputJson($out) : $this->outputTable($out, ['project', 'url', 'cooldown_seconds', 'pending', 'last_status', 'last_http_code', 'last_error', 'last_attempt_at']);

        return null;
    }
}
