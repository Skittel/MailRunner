<?php

namespace App\Commands;

use App\Core\Support\Duration;

class TokenCreate extends MrCommand
{
    protected $name        = 'mr:token:create';
    protected $description = 'Creates an API token for a project (several per project allowed).';
    protected $usage       = 'mr:token:create <customer/project> --label="..." [--expires=90d] [--force] [--json]';
    protected $options     = ['--label' => 'Label to tell tokens apart', '--expires' => 'Lifetime, e.g. 90d (default: none)', '--force' => 'Allow changes to admin-managed projects', '--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $expires = $this->optionString('expires');
        $result  = service('apiTokenService')->create(
            $this->requireArg(0, 'customer/project'),
            $this->optionString('label') ?? throw new \InvalidArgumentException('Missing --label'),
            $expires === null ? null : Duration::toSeconds($expires),
            $this->context(),
        );
        if ($this->json()) {
            $this->outputJson(['token_id' => $result['token']['token_id'], 'secret' => $result['secret'], 'bearer' => $result['bearer'], 'expires_at' => $result['token']['expires_at']]);

            return null;
        }
        $this->outputSecret('API token', [
            'ID'     => $result['token']['token_id'],
            'Secret' => $result['secret'],
            'Header' => 'Authorization: Bearer ' . $result['bearer'],
        ]);

        return null;
    }
}
