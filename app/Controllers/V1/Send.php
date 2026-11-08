<?php

namespace App\Controllers\V1;

use App\Controllers\BaseController;
use App\Core\Api\ApiResponse;
use App\Core\Auth\CurrentToken;
use App\Core\Send\Mime;
use App\Core\Send\SendRejected;
use App\Core\Send\SendSubmissionService;
use App\Core\Support\ConfigException;
use App\Core\Support\Time;

/**
 * Submission of mail for sending (decision E-27). POST needs the token of an api access (scope send); the profile of
 * the access decides senders, recipients and limits. Reading the status works with send and project tokens.
 *
 * POST /v1/send                              structured {"from", "to"?, "cc"?, "bcc"?, "reply_to"?, "subject", "text"?, "html"?,
 *                                            "headers"?, "attachments"?} or raw {"raw": base64, "recipients"?: [...]}
 *                                            -> 202 {submission_id, messages: [{message_id, recipient, status}]}
 * GET  /v1/send/submissions/{submission_id}  submission with its messages
 * GET  /v1/send/messages?after=&limit=&status= messages changed after the cursor (change_seq), oldest change first
 * GET  /v1/send/messages/{message_id}        one message (one recipient) with all delivery attempts
 */
class Send extends BaseController
{
    public function submit()
    {
        $access = CurrentToken::get()['send_access'] ?? null;
        if ($access === null) {
            return ApiResponse::error(403, 'SEND_ACCESS_REQUIRED', 'Submitting needs the token of an api access of a send profile (POST /v1/send-profiles/{name}/accesses)');
        }
        $b = $this->request->getJSON(true);
        if (! is_array($b) || array_is_list($b)) {
            return ApiResponse::error(400, 'INVALID_REQUEST', 'Body must be a JSON object: structured message or {"raw": base64, "recipients"?: [...]}');
        }
        $svc = service('sendSubmissionService');
        $acc = $svc->access($access['access_id']);
        if ($acc === null) {
            return ApiResponse::error(403, 'ACCESS_DISABLED', 'The send access no longer exists');
        }

        try {
            [$from, $recipients, $raw] = isset($b['raw']) ? self::fromRaw($b) : self::fromStructured($b);
            $r = $svc->submit($acc, $from, $recipients, $raw, ['via' => 'api', 'client_ip' => $this->request->getIPAddress(), 'actor' => CurrentToken::get()['token_id']]);
        } catch (ConfigException $e) {
            return ApiResponse::error(422, $e->errorCode, $e->getMessage());
        } catch (SendRejected $e) {
            return ApiResponse::error($e->httpStatus, $e->errorCode, $e->getMessage());
        }

        return ApiResponse::ok(['data' => $r], 202);
    }

    public function submission(string $id)
    {
        $s = db_connect()->table('send_submissions')->where('submission_id', $id)->where('project_id', CurrentToken::projectId())->get()->getRowArray();
        if ($s === null) {
            return ApiResponse::error(404, 'SUBMISSION_NOT_FOUND', 'Submission "' . $id . '" not found');
        }
        $msgs = db_connect()->table('send_messages')->where('submission_row_id', $s['id'])->orderBy('id')->get()->getResultArray();

        return ApiResponse::ok(['data' => self::presentSubmission($s) + ['messages' => array_map([self::class, 'presentMessage'], $msgs)]]);
    }

    public function messages()
    {
        $after  = $this->request->getGet('after') ?? '0';
        $limit  = $this->request->getGet('limit') ?? '100';
        $status = $this->request->getGet('status');
        if (! ctype_digit((string) $after) || ! ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 1000
            || ($status !== null && ! in_array($status, self::STATUSES, true))) {
            return ApiResponse::error(400, 'INVALID_REQUEST', 'after: cursor (integer), limit: 1-1000, status: one of ' . implode(', ', self::STATUSES));
        }
        $q = db_connect()->table('send_messages m')->select('m.*, s.submission_id')->join('send_submissions s', 's.id = m.submission_row_id')
            ->where('m.project_id', CurrentToken::projectId())->where('m.change_seq >', (int) $after);
        if ($status !== null) {
            $q->where('m.status', $status);
        }
        $rows = $q->orderBy('m.change_seq')->limit((int) $limit)->get()->getResultArray();

        return ApiResponse::ok([
            'data' => array_map(static fn ($m) => self::presentMessage($m) + ['submission_id' => $m['submission_id']], $rows),
            'next_cursor' => $rows === [] ? (int) $after : (int) end($rows)['change_seq'],
            'has_more' => count($rows) === (int) $limit,
        ]);
    }

    public function message(string $id)
    {
        $m = db_connect()->table('send_messages')->where('message_id', $id)->where('project_id', CurrentToken::projectId())->get()->getRowArray();
        if ($m === null) {
            return ApiResponse::error(404, 'MESSAGE_NOT_FOUND', 'Message "' . $id . '" not found');
        }
        $s = db_connect()->table('send_submissions')->where('id', $m['submission_row_id'])->get()->getRowArray();

        $attempts = db_connect()->table('send_attempts')->where('message_row_id', $m['id'])->orderBy('id')->get()->getResultArray();

        return ApiResponse::ok(['data' => self::presentMessage($m) + ['submission' => self::presentSubmission($s),
            'delivery_attempts' => array_map([self::class, 'presentAttempt'], $attempts)]]);
    }

    /** @return array{0: string, 1: list<string>, 2: string} */
    private static function fromRaw(array $b): array
    {
        $raw = is_string($b['raw']) ? base64_decode($b['raw'], true) : false;
        if ($raw === false || $raw === '') {
            throw new ConfigException('INVALID_MESSAGE', 'raw must be the message as base64');
        }
        if (array_diff(array_keys($b), ['raw', 'recipients']) !== []) {
            throw new ConfigException('INVALID_REQUEST', 'A raw submission has only raw and recipients');
        }
        [$fields] = Mime::split(preg_replace("/\r?\n|\r/", "\r\n", substr($raw, 0, 262144)) ?? '');
        $from     = Mime::count($fields, 'from') === 1 ? Mime::parseMailbox((string) Mime::header($fields, 'from')) : null;
        if ($from === null) {
            throw new ConfigException('INVALID_FROM', 'The message needs exactly one From header with one address');
        }
        if (isset($b['recipients'])) {
            if (! is_array($b['recipients']) || ! array_is_list($b['recipients'])) {
                throw new ConfigException('INVALID_RECIPIENT', 'recipients must be a list of addresses');
            }
            $rcpts = $b['recipients'];
        } else { // from To, Cc and Bcc of the message (Bcc is removed before queueing)
            $rcpts = [];
            foreach ($fields as $f) {
                if (in_array($f['name'], ['to', 'cc', 'bcc'], true)) {
                    $list = Mime::parseAddressList(substr($f['raw'], strpos($f['raw'], ':') + 1));
                    if ($list === [] && trim(substr($f['raw'], strpos($f['raw'], ':') + 1)) !== '') {
                        throw new ConfigException('INVALID_RECIPIENT', 'Cannot read the ' . ucfirst($f['name']) . ' header; pass recipients explicitly');
                    }
                    array_push($rcpts, ...array_column($list, 1));
                }
            }
        }

        return [$from[1], $rcpts, $raw];
    }

    /** @return array{0: string, 1: list<string>, 2: string} */
    private static function fromStructured(array $b): array
    {
        $known = ['from', 'to', 'cc', 'bcc', 'reply_to', 'subject', 'text', 'html', 'headers', 'attachments'];
        if (($unknown = array_diff(array_keys($b), $known)) !== []) {
            throw new ConfigException('INVALID_REQUEST', 'Unknown field(s): ' . implode(', ', $unknown));
        }
        $from = is_string($b['from'] ?? null) ? Mime::parseMailbox($b['from']) : null;
        if ($from === null) {
            throw new ConfigException('INVALID_FROM', 'from must be one address, e.g. "Shop <info@shop.example>"');
        }
        $lists = [];
        foreach (['to', 'cc', 'bcc'] as $k) {
            $v = $b[$k] ?? [];
            $v = is_string($v) ? [$v] : $v;
            if (! is_array($v) || ! array_is_list($v)) {
                throw new ConfigException('INVALID_RECIPIENT', $k . ' must be an address or a list of addresses');
            }
            $lists[$k] = [];
            foreach ($v as $a) {
                $p = is_string($a) ? Mime::parseMailbox($a) : null;
                if ($p === null) {
                    throw new ConfigException('INVALID_RECIPIENT', $k . ': invalid address "' . (is_string($a) ? $a : '?') . '"');
                }
                $lists[$k][] = $p;
            }
        }
        $raw = Mime::build($b, $from[0], $from[1], $lists['to'], $lists['cc']);

        return [$from[1], array_column([...$lists['to'], ...$lists['cc'], ...$lists['bcc']], 1), $raw];
    }

    /** @param array<string, mixed> $s */
    public static function presentSubmission(array $s): array
    {
        return [
            'submission_id' => $s['submission_id'], 'profile' => $s['profile_name'], 'access_id' => $s['access_id'], 'via' => $s['via'],
            'smtp_service' => $s['smtp_service'], 'envelope_from' => $s['envelope_from'], 'from' => $s['header_from'], 'subject' => $s['subject'],
            'message_id_header' => $s['message_id_header'], 'recipients' => (int) $s['recipients'], 'size' => (int) $s['size'],
            'created_at' => Time::toIso($s['created_at']),
        ];
    }

    public const STATUSES = ['queued', 'waiting_test', 'sending', 'deferred', 'sent', 'bounced', 'failed'];

    /** @param array<string, mixed> $m */
    public static function presentMessage(array $m): array
    {
        $final = in_array($m['status'], ['sent', 'bounced', 'failed'], true);

        return [
            'message_id' => $m['message_id'], 'recipient' => $m['recipient'], 'status' => $m['status'], 'status_reason' => $m['status_reason'],
            'attempts' => (int) $m['attempts'], 'next_attempt_at' => $final ? null : Time::toIso($m['next_attempt_at']), 'expires_at' => Time::toIso($m['expires_at']),
            'last_response' => $m['last_code'] === null ? null : ['code' => (int) $m['last_code'], 'enhanced' => $m['last_enhanced'], 'message' => $m['last_response']],
            'sent_at' => Time::toIso($m['sent_at']), 'cursor' => (int) $m['change_seq'],
            'created_at' => Time::toIso($m['created_at']), 'updated_at' => Time::toIso($m['updated_at']),
        ];
    }

    /** @param array<string, mixed> $a */
    public static function presentAttempt(array $a): array
    {
        return [
            'attempt' => (int) $a['attempt_no'], 'started_at' => Time::toIso($a['started_at']), 'duration_ms' => (int) $a['duration_ms'],
            'local_ip' => $a['local_ip'], 'mx_host' => $a['mx_host'], 'mx_ip' => $a['mx_ip'], 'stage' => $a['stage'], 'result' => $a['result'],
            'smtp_code' => $a['smtp_code'] === null ? null : (int) $a['smtp_code'], 'enhanced_code' => $a['enhanced_code'], 'response' => $a['response'],
            'tls_version' => $a['tls_version'], 'tls_cipher' => $a['tls_cipher'],
        ];
    }
}
