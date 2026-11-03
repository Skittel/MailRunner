<?php

namespace App\Commands;

use App\Modules\Receive\Controllers\Customer\Messages;
use App\Modules\Receive\Libraries\RoleMails;
use CodeIgniter\CLI\CLI;

/**
 * mr:rolemail:show - one mail to postmaster@/abuse@: metadata and analysis, or the original with --raw.
 */
class RoleMailShow extends MrCommand
{
    protected $name        = 'mr:rolemail:show';
    protected $description = 'Shows a mail to postmaster@/abuse@ (metadata, analysis) or prints the original with --raw.';
    protected $usage       = 'mr:rolemail:show <message_id> [--raw] [--json]';
    protected $arguments   = ['message_id' => 'Message id (mrm_...)'];
    protected $options     = ['--raw' => 'Print the original mail (RFC 822)', '--json' => 'JSON output'];

    protected function execute(): ?int
    {
        $service = new RoleMails();
        $row     = $service->find($this->requireArg(0, 'message_id'));
        if ($row === null) {
            CLI::error('Role mail not found (only mails to postmaster@/abuse@).');

            return 1;
        }
        if ($this->flag('raw')) {
            $raw = $service->raw($row);
            if ($raw === null) {
                CLI::error('Original not available.');

                return 1;
            }
            fwrite(STDOUT, $raw);

            return null;
        }
        $data = ['project' => $row['customer_key'] . '/' . $row['project_key']] + Messages::present($row) + Messages::content($row);
        if ($this->json()) {
            $this->outputJson($data);

            return null;
        }
        foreach (['message_id' => $data['message_id'], 'project' => $data['project'], 'role' => $data['role'], 'received' => $data['received_at'],
            'rcpt' => $data['envelope']['rcpt'] ?? '', 'mail_from' => $data['envelope']['mail_from'] ?? '', 'client' => ($data['envelope']['client_ip'] ?? '') . ' (' . ($data['envelope']['helo'] ?? '') . ')',
            'spf/dkim' => ($data['spf_status'] ?? '-') . ' / ' . ($data['dkim_status'] ?? '-'),
            'from' => $data['header']['from'] ?? '', 'subject' => $data['header']['subject'] ?? ''] as $k => $v) {
            CLI::write(sprintf('%-12s %s', $k, is_scalar($v) ? (string) $v : json_encode($v)));
        }
        CLI::newLine();
        CLI::write('Original: sudo mailrunner mr:rolemail:show ' . $data['message_id'] . ' --raw', 'yellow');

        return null;
    }
}
