<?php

namespace App\Commands;

use App\Core\Projects\ProjectSecretService;
use CodeIgniter\CLI\CLI;

class SecretSet extends MrCommand
{
    protected $name        = 'mr:secret:set';
    protected $description = 'Sets an encrypted project secret. The value is read from stdin (prompt without echo on a terminal).';
    protected $usage       = 'mr:secret:set <customer/project> <name> [--force]';
    protected $options     = ['--force' => 'Allow changes to admin-managed projects'];

    protected function execute(): ?int
    {
        $ref  = $this->requireArg(0, 'customer/project');
        $name = $this->arg(1) ?? throw new \InvalidArgumentException('Missing <name>. Allowed: ' . implode(', ', array_keys(ProjectSecretService::ALLOWED)));

        if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
            CLI::print('Value for ' . $name . ' (input hidden): ');
            shell_exec('stty -echo');
            $value = (string) fgets(STDIN);
            shell_exec('stty echo');
            CLI::newLine();
        } else {
            $value = (string) fgets(STDIN);
        }
        service('projectSecretService')->set($ref, $name, rtrim($value, "\r\n"), $this->context());
        $this->ok('Secret "' . $name . '" set for ' . $ref . '.');

        return null;
    }
}
