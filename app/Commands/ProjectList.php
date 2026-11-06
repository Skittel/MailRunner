<?php

namespace App\Commands;

use App\Core\Projects\ProjectService;

class ProjectList extends MrCommand
{
    protected $name        = 'mr:project:list';
    protected $description = 'Lists projects, optionally of one customer.';
    protected $usage       = 'mr:project:list [<customer>] [--json]';
    protected $options     = ['--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $rows = service('projectService')->list($this->arg(0));
        foreach ($rows as &$row) {
            $row['project'] = ProjectService::refOf($row);
        }
        $this->json() ? $this->outputJson($rows) : $this->outputTable($rows, ['project', 'type', 'profile', 'enabled', 'managed_by', 'revision', 'updated_at']);

        return null;
    }
}
