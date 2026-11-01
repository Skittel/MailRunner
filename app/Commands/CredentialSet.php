<?php

namespace App\Commands;

class CredentialSet extends MrCommand
{
    protected $name        = 'mr:credential:set';
    protected $description = 'Changes an SMTP credential (label, enabled, TLS requirement, networks).';
    protected $usage       = 'mr:credential:set <credential_id|username> [--label=..] [--enabled=1|0] [--tls-required=1|0] [--networks=CIDR,..|""] [--force] [--json]';
    protected $options     = ['--label' => 'Label', '--enabled' => '1 or 0', '--tls-required' => '1 or 0', '--networks' => 'CIDR list ("" = any)', '--force' => 'Allow changes to admin-managed projects', '--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $changes = [];
        foreach (['label' => 'label', 'enabled' => 'enabled', 'tls-required' => 'tls_required', 'networks' => 'allowed_networks'] as $option => $field) {
            $value = $this->option($option);
            if ($value === null) {
                continue;
            }
            $value           = $value === true ? '' : $value;
            $changes[$field] = match ($field) {
                'enabled', 'tls_required' => filter_var($value, FILTER_VALIDATE_BOOL),
                'allowed_networks'        => $value === '' ? null : explode(',', $value),
                default                   => $value,
            };
        }
        if ($changes === []) {
            throw new \InvalidArgumentException('Nothing to change. Usage: ' . $this->usage);
        }
        $cred = service('smtpCredentialService')->update($this->requireArg(0, 'credential_id|username'), $changes, $this->context());
        unset($cred['password_hash'], $cred['previous_password_hash']);
        $this->json() ? $this->outputJson($cred) : $this->ok('SMTP credential ' . $cred['credential_id'] . ' updated.');

        return null;
    }
}
