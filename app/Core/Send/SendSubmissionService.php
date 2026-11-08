<?php

namespace App\Core\Send;

use App\Core\Journal\JournalWriter;
use App\Core\Projects\ProjectService;
use App\Core\Secrets\NodeCrypto;
use App\Core\Support\ChangeContext;
use App\Core\Support\RandomId;
use App\Core\Support\Time;
use App\Core\Support\Transaction;
use Closure;
use CodeIgniter\Database\BaseConnection;

/**
 * Accepts mail for sending (decision E-27), the same way for the API and for SMTP (mr-milter calls
 * `spark mr:send:accept`). Checks, in this order:
 *   access and profile enabled, project send-type and enabled;
 *   envelope sender and header From allowed by the profile (exact address or exact domain), the From domain allowed
 *   by the project and set up with a DKIM key;
 *   1..MAX_RECIPIENTS recipients, each allowed by the profile;
 *   size;
 *   limits per hour and day of profile and project (rolling 60 min / 24 h, counted per recipient).
 * Accepted: one submission, one message per recipient (status queued), the mail stored once encrypted with the node
 * key. Nothing is sent before the delivery worker and the domain test exist (steps 4 and 5).
 */
class SendSubmissionService
{
    public const MAX_RECIPIENTS = 100;
    public const MAX_SIZE       = 20 * 1024 * 1024;

    /** @var Closure(): NodeCrypto */
    private readonly Closure $crypto;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly JournalWriter $journal,
        private readonly SendDomainService $domains,
        private readonly string $storeDir,
        ?callable $crypto = null,
    ) {
        $this->crypto = $crypto !== null ? Closure::fromCallable($crypto) : static fn (): NodeCrypto => NodeCrypto::forNode();
    }

    /**
     * The access a submission comes through, with its profile and project; null if unknown.
     *
     * @return array<string, mixed>|null
     */
    public function access(string $accessId): ?array
    {
        return $this->db->query(
            'SELECT a.access_id, a.type, a.enabled AS access_enabled, a.project_id, p.id AS profile_row_id, p.name AS profile_name, p.enabled AS profile_enabled,
                    p.senders, p.recipients, p.limit_hour, p.limit_day, p.retry_attempts, p.retry_seconds
             FROM send_accesses a JOIN send_profiles p ON p.id = a.profile_id WHERE a.access_id = ?',
            [$accessId],
        )->getRowArray();
    }

    /**
     * Submits a raw message.
     *
     * @param array<string, mixed> $access     row from access()
     * @param list<string>         $recipients envelope recipients
     * @param array<string, mixed> $meta       {via: api|smtp, smtp_service?, client_ip?, actor?}
     *
     * @return array{submission_id: string, messages: list<array{message_id: string, recipient: string, status: string}>}
     *
     * @throws SendRejected
     */
    public function submit(array $access, string $envelopeFrom, array $recipients, string $raw, array $meta): array
    {
        $via = $meta['via'];
        $ctx = new ChangeContext($via === 'api' ? 'api' : 'system', (string) ($meta['actor'] ?? $access['access_id']));
        try {
            return $this->doSubmit($access, $envelopeFrom, $recipients, $raw, $meta, $ctx);
        } catch (SendRejected $e) {
            $this->journalRejected($access, $e, $meta, $ctx);

            throw $e;
        }
    }

    /** @param array<string, mixed> $access @param list<string> $recipients @param array<string, mixed> $meta */
    private function doSubmit(array $access, string $envelopeFrom, array $recipients, string $raw, array $meta, ChangeContext $ctx): array
    {
        if (! (bool) $access['access_enabled'] || ! (bool) $access['profile_enabled']) {
            throw new SendRejected('ACCESS_DISABLED', 'The send access or its profile is disabled', 403, '550', '5.7.1');
        }
        if (strlen($raw) > self::MAX_SIZE) {
            throw new SendRejected('MESSAGE_TOO_LARGE', 'The message is larger than ' . (self::MAX_SIZE >> 20) . ' MB', 413, '552', '5.3.4');
        }
        $senders = json_decode($access['senders'], true);
        $allowedRcpt = $access['recipients'] === null ? null : json_decode($access['recipients'], true);

        // envelope sender
        $env = Mime::normalizeAddress($envelopeFrom);
        if ($env === null || ! self::matches($senders, $env)) {
            throw new SendRejected('SENDER_NOT_ALLOWED', 'Envelope sender "' . $envelopeFrom . '" is not allowed by profile "' . $access['profile_name'] . '"', 422, '550', '5.7.1');
        }
        // header From: exactly one mailbox, allowed, domain set up with DKIM
        $prepared = Mime::prepare($raw, SendProfileService::domainOf($env));
        $fields   = $prepared['fields'];
        $fromHdr  = Mime::count($fields, 'from') === 1 ? Mime::parseMailbox((string) Mime::header($fields, 'from')) : null;
        if ($fromHdr === null) {
            throw new SendRejected('INVALID_FROM', 'The message needs exactly one From header with one address', 422, '550', '5.6.0');
        }
        $from = $fromHdr[1];
        if (! self::matches($senders, $from)) {
            throw new SendRejected('SENDER_NOT_ALLOWED', 'From "' . $from . '" is not allowed by profile "' . $access['profile_name'] . '"', 422, '550', '5.7.1');
        }
        $fromDomain = SendProfileService::domainOf($from);

        // recipients
        $rcpts = [];
        foreach ($recipients as $r) {
            $n = is_string($r) ? Mime::normalizeAddress($r) : null;
            if ($n === null) {
                throw new SendRejected('INVALID_RECIPIENT', 'Invalid recipient "' . (is_string($r) ? $r : '?') . '"', 422, '550', '5.1.3');
            }
            if ($allowedRcpt !== null && ! self::matches($allowedRcpt, $n)) {
                throw new SendRejected('RECIPIENT_NOT_ALLOWED', 'Recipient "' . $n . '" is not allowed by profile "' . $access['profile_name'] . '"', 422, '550', '5.7.1');
            }
            $rcpts[strtolower($n)] ??= $n; // one message per mailbox, first spelling wins
        }
        $rcpts = array_values($rcpts);
        if ($rcpts === [] || count($rcpts) > self::MAX_RECIPIENTS) {
            throw new SendRejected('INVALID_RECIPIENT', 'A submission needs 1-' . self::MAX_RECIPIENTS . ' recipients', 422, '554', '5.5.3');
        }

        return Transaction::run($this->db, function () use ($access, $env, $from, $fromDomain, $rcpts, $prepared, $meta, $ctx): array {
            $project = $this->domains->sendProject((int) $access['project_id']); // locked: serializes the limit check
            $owner   = $this->db->table('customers')->select('enabled')->where('id', $project['customer_id'])->get()->getRowArray();
            if (! (bool) $project['enabled'] || ! (bool) ($owner['enabled'] ?? false)) {
                throw new SendRejected('PROJECT_DISABLED', 'Project is disabled', 403, '550', '5.7.1');
            }
            if (! ProjectService::sendDomainAllowed($project, $fromDomain)) {
                throw new SendRejected('SENDER_NOT_ALLOWED', 'Domain "' . $fromDomain . '" is not allowed for this project', 422, '550', '5.7.1');
            }
            if ($this->domains->get((int) $project['id'], $fromDomain) === null) {
                throw new SendRejected('DKIM_MISSING', 'Domain "' . $fromDomain . '" has no DKIM key (PUT /v1/domains/' . $fromDomain . ')', 422, '550', '5.7.1');
            }
            $this->checkLimits($project, $access, count($rcpts));

            $submissionId = RandomId::publicId('mrs');
            $now          = Time::now();
            $rel          = gmdate('Y/m/d') . '/' . $submissionId . '.eml.enc';
            $this->writeStore($rel, ($this->crypto)()->encrypt($prepared['raw'], 'send:' . $submissionId . ':eml'));
            try {
                $this->db->table('send_submissions')->insert([
                    'submission_id' => $submissionId, 'project_id' => $project['id'], 'profile_id' => $access['profile_row_id'],
                    'profile_name' => $access['profile_name'], 'access_id' => $access['access_id'], 'via' => $meta['via'],
                    'smtp_service' => $meta['smtp_service'] ?? null, 'client_ip' => $meta['client_ip'] ?? null,
                    'envelope_from' => $env, 'header_from' => $from, 'from_domain' => $fromDomain,
                    'subject' => mb_substr(Mime::decodeHeader((string) Mime::header($prepared['fields'], 'subject')), 0, 998) ?: null,
                    'message_id_header' => mb_substr($prepared['message_id'], 0, 998),
                    'recipients' => count($rcpts), 'size' => strlen($prepared['raw']), 'sha256' => hash('sha256', $prepared['raw']),
                    'raw_path' => $rel, 'created_at' => $now,
                ]);
                $rowId    = (int) $this->db->insertID();
                $expires  = Time::inSeconds((int) $access['retry_seconds']);
                $messages = [];
                $seq      = SendSequence::take($this->db, count($rcpts));
                foreach ($rcpts as $r) {
                    $mid = RandomId::publicId('mrm');
                    $this->db->table('send_messages')->insert([
                        'message_id' => $mid, 'submission_row_id' => $rowId, 'project_id' => $project['id'], 'profile_id' => $access['profile_row_id'],
                        'recipient' => $r, 'recipient_domain' => SendProfileService::domainOf($r), 'status' => 'queued', 'change_seq' => $seq++,
                        'retry_attempts' => $access['retry_attempts'], 'next_attempt_at' => $now, 'expires_at' => $expires,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $messages[] = ['message_id' => $mid, 'recipient' => $r, 'status' => 'queued'];
                }
                $this->journal->append('send.submitted', $ctx, (int) $project['customer_id'], (int) $project['id'], [
                    'submission_id' => $submissionId, 'profile' => $access['profile_name'], 'access_id' => $access['access_id'],
                    'via' => $meta['via'], 'recipients' => count($rcpts), 'size' => strlen($prepared['raw']), 'from_domain' => $fromDomain,
                ]);
            } catch (\Throwable $e) {
                @unlink($this->storeDir . '/' . $rel); // the transaction rolls back; no orphan file

                throw $e;
            }

            return ['submission_id' => $submissionId, 'messages' => $messages];
        });
    }

    /**
     * Whether an address matches a list of addresses and domains (exact address or exact domain, case-insensitive).
     *
     * @param list<string> $list
     */
    public static function matches(array $list, string $address): bool
    {
        $address = strtolower($address);

        return in_array($address, $list, true) || in_array(SendProfileService::domainOf($address), $list, true);
    }

    /**
     * Mails counted against the limits: per recipient, rolling 60 minutes and 24 hours.
     *
     * @param array<string, mixed> $project
     * @param array<string, mixed> $access
     */
    private function checkLimits(array $project, array $access, int $n): void
    {
        $now   = Time::nowObject();
        $since = ['hour' => $now->sub(new \DateInterval('PT1H'))->format(Time::DB_FORMAT), 'day' => $now->sub(new \DateInterval('P1D'))->format(Time::DB_FORMAT)];
        foreach (['hour', 'day'] as $w) {
            $projectLimit = (int) ($project['send_limit_' . $w] ?? 0);
            $profileLimit = (int) $access['limit_' . $w];
            $usedProject  = $this->db->table('send_messages')->where('project_id', $project['id'])->where('created_at >=', $since[$w])->countAllResults();
            $usedProfile  = $this->db->table('send_messages')->where('profile_id', $access['profile_row_id'])->where('created_at >=', $since[$w])->countAllResults();
            if ($usedProfile + $n > $profileLimit) {
                throw new SendRejected('LIMIT_REACHED', 'Limit of profile "' . $access['profile_name'] . '" reached: ' . $profileLimit . ' mails per ' . $w, 429, '451', '4.7.1');
            }
            if ($usedProject + $n > $projectLimit) {
                throw new SendRejected('LIMIT_REACHED', 'Project limit reached: ' . $projectLimit . ' mails per ' . $w, 429, '451', '4.7.1');
            }
        }
    }

    private function writeStore(string $rel, string $blob): void
    {
        $path = $this->storeDir . '/' . $rel;
        $dir  = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o750, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        $fh  = fopen($tmp, 'xb') ?: throw new \RuntimeException('Cannot write ' . $tmp);
        try {
            fwrite($fh, $blob);
            fflush($fh);
            if (function_exists('fsync')) {
                fsync($fh);
            }
        } finally {
            fclose($fh);
        }
        chmod($tmp, 0o640);
        rename($tmp, $path);
    }

    /** @param array<string, mixed> $access @param array<string, mixed> $meta */
    private function journalRejected(array $access, SendRejected $e, array $meta, ChangeContext $ctx): void
    {
        try {
            $customer = $this->db->table('projects')->select('customer_id')->where('id', $access['project_id'])->get()->getRowArray();
            Transaction::run($this->db, fn () => $this->journal->append('send.rejected', $ctx, isset($customer['customer_id']) ? (int) $customer['customer_id'] : null, (int) $access['project_id'], [
                'reason' => $e->errorCode, 'profile' => $access['profile_name'], 'access_id' => $access['access_id'], 'via' => $meta['via'],
            ]));
        } catch (\Throwable) {
            // the rejection itself is what matters
        }
    }
}
