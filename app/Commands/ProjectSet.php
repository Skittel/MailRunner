<?php

namespace App\Commands;

class ProjectSet extends MrCommand
{
    protected $name        = 'mr:project:set';
    protected $description = 'Changes project settings (increments the revision).';
    protected $usage       = 'mr:project:set <customer/project> [--recipient-pattern=REGEX|""] [--always-allow=postmaster,abuse] [--enabled=1|0] [--delete-after-ack-hours=N] [--unacked-days=N] [--metadata-days=N] [--journal-scope=own|connections|unassigned] [--name=..] [--force] [--json]';
    protected $options     = [
        '--recipient-pattern'      => 'Regex for the local part checked at RCPT TO ("" = none)',
        '--always-allow'           => 'Comma-separated local parts always accepted ("" = none)',
        '--enabled'                => '1 or 0',
        '--delete-after-ack-hours' => 'Delete content N hours after ack',
        '--unacked-days'           => 'Delete unacknowledged content after N days',
        '--metadata-days'          => 'Delete metadata after N days',
        '--journal-scope'          => 'Journal entries without project the token sees: own (none), connections (no MAIL FROM: IP, EHLO, TLS), unassigned (all)',
        '--name'                   => 'Display name',
        '--force'                  => 'Allow changes to admin-managed projects',
        '--json'                   => 'JSON output',
    ];

    private const MAP = [
        'recipient-pattern'      => 'recipient_pattern',
        'always-allow'           => 'recipient_always_allow',
        'enabled'                => 'enabled',
        'delete-after-ack-hours' => 'delete_after_ack_hours',
        'unacked-days'           => 'unacked_days',
        'metadata-days'          => 'metadata_days',
        'journal-scope'          => 'journal_scope',
        'name'                   => 'name',
    ];

    protected function execute(): ?int
    {
        $changes = [];
        foreach (self::MAP as $option => $field) {
            $value = $this->option($option);
            if ($value === null) {
                continue;
            }
            $value           = $value === true ? '' : $value;
            $changes[$field] = match ($field) {
                'recipient_always_allow' => $value === '' ? null : explode(',', $value),
                'enabled'                => filter_var($value, FILTER_VALIDATE_BOOL),
                default                  => $value,
            };
        }
        if ($changes === []) {
            throw new \InvalidArgumentException('Nothing to change. Usage: ' . $this->usage);
        }
        $project = service('projectService')->update($this->requireArg(0, 'customer/project'), $changes, $this->context());
        $this->json() ? $this->outputJson($project) : $this->ok('Project updated (revision ' . $project['revision'] . ').');

        return null;
    }
}
