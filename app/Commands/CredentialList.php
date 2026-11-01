<?php

namespace App\Commands;

class CredentialList extends MrCommand
{
    protected $name        = 'mr:credential:list';
    protected $description = 'Lists the SMTP credentials of a project (never shows passwords).';
    protected $usage       = 'mr:credential:list <customer/project> [--json]';
    protected $options     = ['--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $rows = service('smtpCredentialService')->list($this->requireArg(0, 'customer/project'));
        $this->json() ? $this->outputJson($rows) : $this->outputTable($rows, ['credential_id', 'username', 'label', 'tls_required', 'allowed_networks', 'enabled', 'previous_valid_until', 'revoked_at', 'last_used_at']);

        return null;
    }
}
