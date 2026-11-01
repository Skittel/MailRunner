<?php

namespace App\Commands;

use App\Core\Support\Duration;

class CredentialRotate extends MrCommand
{
    protected $name        = 'mr:credential:rotate';
    protected $description = 'Sets a new SMTP password; the old one stays valid for the grace period.';
    protected $usage       = 'mr:credential:rotate <credential_id|username> [--grace=24h] [--password-stdin] [--force] [--json]';
    protected $options     = ['--grace' => 'How long the old password stays valid (default 24h, 0 = invalid now)', '--password-stdin' => 'Read the new password from stdin', '--force' => 'Allow changes to admin-managed projects', '--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $grace  = Duration::toSeconds($this->optionString('grace') ?? '24h');
        $result = service('smtpCredentialService')->rotate(
            $this->requireArg(0, 'credential_id|username'),
            $grace,
            $this->flag('password-stdin') ? rtrim((string) fgets(STDIN), "\r\n") : null,
            $this->context(),
        );
        $cred = $result['credential'];
        if ($this->json()) {
            $this->outputJson(['credential_id' => $cred['credential_id'], 'username' => $cred['username'], 'password' => $result['password'], 'previous_valid_until' => $cred['previous_valid_until']]);

            return null;
        }
        $this->outputSecret('New SMTP password', ['ID' => $cred['credential_id'], 'Username' => $cred['username'], 'Password' => $result['password']]);
        $this->ok($grace === 0 ? 'Old password is invalid now.' : 'Old password valid until ' . substr($cred['previous_valid_until'], 0, 19) . ' UTC.');

        return null;
    }
}
