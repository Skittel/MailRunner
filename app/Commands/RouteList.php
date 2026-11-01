<?php

namespace App\Commands;

class RouteList extends MrCommand
{
    protected $name        = 'mr:route:list';
    protected $description = 'Lists the receive routes of a project.';
    protected $usage       = 'mr:route:list <customer/project> [--json]';
    protected $options     = ['--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $rows = service('routeService')->list($this->requireArg(0, 'customer/project'));
        $this->json() ? $this->outputJson($rows) : $this->outputTable($rows, ['route_type', 'route']);

        return null;
    }
}
