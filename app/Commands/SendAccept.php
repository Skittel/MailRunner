<?php

namespace App\Commands;

use App\Core\Send\SendRejected;
use CodeIgniter\CLI\BaseCommand;

/**
 * Hand-off from mr-milter (SMTP submission with a send access): reads one JSON object from stdin
 *   {"access_id", "mail_from", "rcpts": [...], "raw_b64", "smtp_service"?, "client_ip"?, "username"?}
 * and prints one JSON line: {"ok": true, "submission_id", "messages"} or {"ok": false, "code", "enhanced", "error", "message"}.
 * Runs as user mailrunner (the milter's user); not meant for interactive use.
 */
class SendAccept extends BaseCommand
{
    protected $group       = 'MailRunner';
    protected $name        = 'mr:send:accept';
    protected $description = 'Accepts one SMTP submission from mr-milter (JSON on stdin, JSON on stdout).';
    protected $usage       = 'mr:send:accept < submission.json';

    public function run(array $params)
    {
        $in  = json_decode((string) stream_get_contents(STDIN), true);
        $out = static fn (array $r) => fwrite(STDOUT, json_encode($r, JSON_UNESCAPED_SLASHES) . "\n");
        if (! is_array($in) || ! is_string($in['access_id'] ?? null) || ! is_string($in['mail_from'] ?? null) || ! is_array($in['rcpts'] ?? null)
            || ! is_string($in['raw_b64'] ?? null) || ($raw = base64_decode($in['raw_b64'], true)) === false) {
            $out(['ok' => false, 'code' => '451', 'enhanced' => '4.3.0', 'error' => 'BAD_HANDOFF', 'message' => 'Temporary local problem']);

            return 1;
        }
        if (! config('Mailrunner')->sendEnabled) {
            $out(['ok' => false, 'code' => '550', 'enhanced' => '5.7.1', 'error' => 'SEND_DISABLED', 'message' => 'Relaying is not enabled on this node']);

            return 0;
        }
        $svc    = service('sendSubmissionService');
        $access = $svc->access($in['access_id']);
        if ($access === null) {
            $out(['ok' => false, 'code' => '550', 'enhanced' => '5.7.1', 'error' => 'ACCESS_DISABLED', 'message' => 'The send access no longer exists']);

            return 0;
        }
        try {
            $r = $svc->submit($access, $in['mail_from'], array_values(array_filter($in['rcpts'], 'is_string')), $raw, [
                'via' => 'smtp', 'smtp_service' => is_string($in['smtp_service'] ?? null) ? substr($in['smtp_service'], 0, 16) : null,
                'client_ip' => is_string($in['client_ip'] ?? null) ? substr($in['client_ip'], 0, 45) : null,
                'actor' => is_string($in['username'] ?? null) && $in['username'] !== '' ? 'smtp:' . substr($in['username'], 0, 90) : 'ip:' . $in['access_id'],
            ]);
            $out(['ok' => true] + $r);
        } catch (SendRejected $e) {
            $out(['ok' => false, 'code' => $e->smtpCode, 'enhanced' => $e->smtpEnhanced, 'error' => $e->errorCode, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            log_message('error', 'mr:send:accept: ' . $e::class . ': ' . $e->getMessage());
            $out(['ok' => false, 'code' => '451', 'enhanced' => '4.3.0', 'error' => 'INTERNAL_ERROR', 'message' => 'Temporary local problem, please try again later']);

            return 1;
        }

        return 0;
    }
}
