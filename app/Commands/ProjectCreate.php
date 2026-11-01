<?php

namespace App\Commands;

use App\Core\Projects\ProjectService;

class ProjectCreate extends MrCommand
{
    protected $name        = 'mr:project:create';
    protected $description = 'Creates a project for a customer.';
    protected $usage       = 'mr:project:create <customer/project> --profile=analysis|checked|checked_auth [--name="Display name"] [--json]';
    protected $arguments   = ['customer/project' => 'Project reference'];
    protected $options     = [
        '--profile' => 'analysis (accept all, analyse), checked (checks per project), checked_auth (checked + SMTP AUTH)',
        '--name'    => 'Display name',
        '--json'    => 'JSON output',
    ];

    protected function execute(): ?int
    {
        $profile = $this->optionString('profile')
            ?? throw new \InvalidArgumentException('Missing --profile=' . implode('|', ProjectService::PROFILES));
        $project = service('projectService')->create($this->requireArg(0, 'customer/project'), $profile, $this->optionString('name') ?? '', $this->context());
        $this->json() ? $this->outputJson($project) : $this->ok('Project "' . ProjectService::refOf($project) . '" created (profile ' . $project['profile'] . ', revision ' . $project['revision'] . ').');

        return null;
    }
}
