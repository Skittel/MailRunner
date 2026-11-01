<?php

namespace App\Commands;

use App\Core\Projects\ProjectSecretService;
use CodeIgniter\CLI\CLI;

class SecretList extends MrCommand
{
    protected $name        = 'mr:secret:list';
    protected $description = 'Lists the secrets of a project (names only, never values).';
    protected $usage       = 'mr:secret:list <customer/project> [--json]';
    protected $options     = ['--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $rows = service('projectSecretService')->list($this->requireArg(0, 'customer/project'));
        if ($this->json()) {
            $this->outputJson($rows);

            return null;
        }
        $this->outputTable($rows, ['name', 'updated_at', 'updated_source']);
        CLI::write('Allowed names:');
        foreach (ProjectSecretService::ALLOWED as $name => [, $description]) {
            CLI::write(sprintf('  %-22s %s', $name, $description));
        }

        return null;
    }
}
