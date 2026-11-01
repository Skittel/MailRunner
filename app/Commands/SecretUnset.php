<?php

namespace App\Commands;

class SecretUnset extends MrCommand
{
    protected $name        = 'mr:secret:unset';
    protected $description = 'Removes a project secret.';
    protected $usage       = 'mr:secret:unset <customer/project> <name> [--force]';
    protected $options     = ['--force' => 'Allow changes to admin-managed projects'];

    protected function execute(): ?int
    {
        service('projectSecretService')->unset($this->requireArg(0, 'customer/project'), $this->requireArg(1, 'name'), $this->context());
        $this->ok('Secret removed.');

        return null;
    }
}
