<?php

namespace App\Commands;

class CredentialCreate extends MrCommand
{
    protected $name        = 'mr:credential:create';
    protected $description = 'Creates an SMTP credential (profile checked_auth; several per project allowed).';
    protected $usage       = 'mr:credential:create <customer/project> <username> [--label=".."] [--no-tls] [--networks=10.0.0.0/8,2001:db8::/32] [--password-stdin] [--force] [--json]';
    protected $options     = [
        '--label'          => 'Label, e.g. device name',
        '--no-tls'         => 'Allow login without TLS (old devices; credential use is journaled)',
        '--networks'       => 'Comma-separated CIDR list the credential may log in from',
        '--password-stdin' => 'Read the password from stdin instead of generating one',
        '--force'          => 'Allow changes to admin-managed projects',
        '--json'           => 'JSON output',
    ];

    protected function execute(): ?int
    {
        $networks = $this->optionString('networks');
        $result   = service('smtpCredentialService')->create(
            $this->requireArg(0, 'customer/project'),
            $this->requireArg(1, 'username'),
            $this->optionString('label') ?? '',
            $this->flag('password-stdin') ? rtrim((string) fgets(STDIN), "\r\n") : null,
            ! $this->flag('no-tls'),
            $networks === null ? null : explode(',', $networks),
            $this->context(),
        );
        $cred = $result['credential'];
        if ($this->json()) {
            $this->outputJson(['credential_id' => $cred['credential_id'], 'username' => $cred['username'], 'password' => $result['password']]);

            return null;
        }
        $this->outputSecret('SMTP credential', ['ID' => $cred['credential_id'], 'Username' => $cred['username'], 'Password' => $result['password']]);

        return null;
    }
}
