<?php

namespace App\Commands;

class TokenList extends MrCommand
{
    protected $name        = 'mr:token:list';
    protected $description = 'Lists the API tokens of a project (never shows secrets).';
    protected $usage       = 'mr:token:list <customer/project> [--json]';
    protected $options     = ['--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $rows = service('apiTokenService')->list($this->requireArg(0, 'customer/project'));
        $this->json() ? $this->outputJson($rows) : $this->outputTable($rows, ['token_id', 'label', 'created_at', 'expires_at', 'revoked_at', 'last_used_at']);

        return null;
    }
}
