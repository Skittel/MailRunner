<?php

namespace App\Commands;

class CredentialRevoke extends MrCommand
{
    protected $name        = 'mr:credential:revoke';
    protected $description = 'Revokes an SMTP credential immediately.';
    protected $usage       = 'mr:credential:revoke <credential_id|username> [--force]';
    protected $options     = ['--force' => 'Allow changes to admin-managed projects', '--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $id = $this->requireArg(0, 'credential_id|username');
        service('smtpCredentialService')->revoke($id, $this->context());
        $this->json() ? $this->outputJson(['revoked' => $id]) : $this->ok('SMTP credential ' . $id . ' revoked.');

        return null;
    }
}
