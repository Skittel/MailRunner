<?php

namespace App\Commands;

use App\Core\Projects\ProjectService;

class ProjectCreate extends MrCommand
{
    protected $name        = 'mr:project:create';
    protected $description = 'Creates a project for a customer.';
    protected $usage       = 'mr:project:create <customer/project> --profile=analysis|checked|checked_auth | --type=send [--name="Display name"] [--json]';
    protected $arguments   = ['customer/project' => 'Project reference'];
    protected $options     = [
        '--profile' => 'analysis (accept all, analyse), checked (checks per project), checked_auth (checked + SMTP AUTH)',
        '--type'    => 'receive (default) or send; fixed at creation. A send project has no profile option (smarthost).',
        '--name'    => 'Display name',
        '--json'    => 'JSON output',
    ];

    protected function execute(): ?int
    {
        $type    = $this->optionString('type') ?? 'receive';
        $profile = $this->optionString('profile') ?? ($type === 'send' ? ProjectService::SEND_PROFILE
            : throw new \InvalidArgumentException('Missing --profile=' . implode('|', ProjectService::PROFILES)));
        $project = service('projectService')->create($this->requireArg(0, 'customer/project'), $profile, $this->optionString('name') ?? '', $this->context(), $type);
        $this->json() ? $this->outputJson($project) : $this->ok('Project "' . ProjectService::refOf($project) . '" created (type ' . $project['type'] . ', profile ' . $project['profile'] . ', revision ' . $project['revision'] . ').');

        return null;
    }
}
