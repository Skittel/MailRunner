<?php

namespace App\Commands;

class RouteSet extends MrCommand
{
    protected $name        = 'mr:route:set';
    protected $description = 'Replaces all receive routes of a project (domains and/or addresses).';
    protected $usage       = 'mr:route:set <customer/project> <route>... | --clear [--force] [--json]';
    protected $options     = ['--clear' => 'Remove all routes', '--force' => 'Allow changes to admin-managed projects', '--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $ref    = $this->requireArg(0, 'customer/project');
        $routes = array_slice($this->positional(), 1);
        if ($routes === [] && ! $this->flag('clear')) {
            throw new \InvalidArgumentException('Give at least one route, or --clear to remove all routes');
        }
        $result = service('routeService')->set($ref, $routes, $this->context());
        $this->json() ? $this->outputJson($result) : $this->outputTable($result, ['route_type', 'route']);

        return null;
    }
}
