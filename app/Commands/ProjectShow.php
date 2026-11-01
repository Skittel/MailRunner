<?php

namespace App\Commands;

use App\Core\Projects\ProjectService;
use CodeIgniter\CLI\CLI;

class ProjectShow extends MrCommand
{
    protected $name        = 'mr:project:show';
    protected $description = 'Shows a project with routes, tokens and SMTP credentials.';
    protected $usage       = 'mr:project:show <customer/project> [--json]';
    protected $options     = ['--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $ref         = $this->requireArg(0, 'customer/project');
        $project     = service('projectService')->get($ref);
        $routes      = service('routeService')->list($ref);
        $tokens      = service('apiTokenService')->list($ref);
        $credentials = service('smtpCredentialService')->list($ref);

        if ($this->json()) {
            $this->outputJson(['project' => $project, 'routes' => $routes, 'tokens' => $tokens, 'smtp_credentials' => $credentials]);

            return null;
        }

        foreach (['name', 'profile', 'enabled', 'managed_by', 'revision', 'recipient_pattern', 'recipient_always_allow',
            'delete_after_ack_hours', 'unacked_days', 'metadata_days', 'updated_at'] as $field) {
            CLI::write(sprintf('%-24s %s', $field, $project[$field] ?? '-'));
        }
        CLI::newLine();
        CLI::write('Routes', 'yellow');
        $this->outputTable($routes, ['route_type', 'route']);
        CLI::write('API tokens', 'yellow');
        $this->outputTable($tokens, ['token_id', 'label', 'created_at', 'expires_at', 'revoked_at', 'last_used_at']);
        CLI::write('SMTP credentials', 'yellow');
        $this->outputTable($credentials, ['credential_id', 'username', 'label', 'tls_required', 'enabled', 'revoked_at', 'last_used_at']);
        CLI::write('Reference: ' . ProjectService::refOf($project));

        return null;
    }
}
