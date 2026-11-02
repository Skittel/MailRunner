<?php

namespace App\Commands;

use App\Modules\Receive\Libraries\RoleMails;

/**
 * mr:rolemail:list - mails to postmaster@/abuse@ of all projects (same view as the admin app).
 */
class RoleMailList extends MrCommand
{
    protected $name        = 'mr:rolemail:list';
    protected $description = 'Lists mails to postmaster@/abuse@ of all projects, newest first.';
    protected $usage       = 'mr:rolemail:list [--limit=50] [--open] [--selftests] [--json]';
    protected $options     = [
        '--limit'     => 'Number of mails (default 50, max 500)',
        '--open'      => 'Only mails not yet acknowledged',
        '--selftests' => 'Include the self test projects (customer system)',
        '--json'      => 'JSON output',
    ];

    protected function execute(): ?int
    {
        $rows = (new RoleMails())->list((int) ($this->optionString('limit') ?? 50), 0, $this->flag('open') ? false : null, $this->flag('selftests'));
        if ($this->json()) {
            $this->outputJson($rows);

            return null;
        }
        $this->outputTable(array_map(static fn ($r) => [
            'message_id' => $r['message_id'],
            'received'   => $r['received_at'],
            'role'       => $r['role'],
            'rcpt'       => $r['envelope']['rcpt'] ?? '',
            'from'       => mb_strimwidth((string) ($r['header']['from'] ?? $r['envelope']['mail_from'] ?? ''), 0, 40, '…'),
            'subject'    => mb_strimwidth((string) ($r['header']['subject'] ?? ''), 0, 50, '…'),
            'project'    => $r['project'],
            'status'     => $r['content_deleted_at'] ? 'deleted' : ($r['acked_at'] ? 'acked' : 'open'),
        ], $rows), ['message_id', 'received', 'role', 'rcpt', 'from', 'subject', 'project', 'status']);

        return null;
    }
}
