<?php

namespace App\Commands;

class TokenRevoke extends MrCommand
{
    protected $name        = 'mr:token:revoke';
    protected $description = 'Revokes an API token immediately.';
    protected $usage       = 'mr:token:revoke <token_id> [--force]';
    protected $options     = ['--force' => 'Allow changes to admin-managed projects', '--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $tokenId = $this->requireArg(0, 'token_id');
        service('apiTokenService')->revoke($tokenId, $this->context());
        $this->json() ? $this->outputJson(['revoked' => $tokenId]) : $this->ok('Token ' . $tokenId . ' revoked.');

        return null;
    }
}
