<?php

namespace App\Core\Webhooks;

use App\Core\Journal\JournalWriter;
use App\Core\Node\NodeInfo;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;
use App\Core\Support\Time;
use App\Core\Support\Transaction;
use Closure;
use CodeIgniter\Database\BaseConnection;

/**
 * Sends pending webhook pings (mr:webhook:send, started by mailrunner-webhook.path after an import).
 *
 * Per project at most one ping per cooldown: the first mail is announced at once, mail arriving during the
 * cooldown gets one more ping when it ends (so no mail waits for the application's polling interval).
 * A ping is an empty POST (timeout 5 s, no redirects, address pinned to the checked DNS answer). It is never
 * repeated; the result is journaled as webhook.sent / webhook.failed with the HTTP code.
 */
class WebhookSender
{
    public const TIMEOUT         = 5;
    public const CONNECT_TIMEOUT = 3;

    /** @var Closure(string, string, list<string>): array{code: ?int, error: ?string} */
    private readonly Closure $transport;

    /**
     * @param (callable(string, string, list<string>): array{code: ?int, error: ?string})|null $transport url, host, pinned ips
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly JournalWriter $journal,
        private readonly WebhookService $webhooks,
        ?callable $transport = null,
        private readonly ?string $requestFile = null,
    ) {
        $this->transport = $transport !== null ? Closure::fromCallable($transport) : self::curl(...);
    }

    /**
     * Sends due pings and waits for pings still in their cooldown, until nothing is pending or $maxSeconds passed.
     *
     * @return array{sent: int, failed: int}
     */
    public function run(int $maxSeconds = 3700): array
    {
        $stats    = ['sent' => 0, 'failed' => 0];
        $deadline = microtime(true) + $maxSeconds;
        do {
            if ($this->requestFile !== null) {
                @unlink($this->requestFile); // requests from now on start a new run after this one
            }
            $r = $this->sendDue();
            $stats['sent'] += $r['sent'];
            $stats['failed'] += $r['failed'];
            if ($r['next_in'] === null) {
                break;
            }
            usleep((int) (min(1.0, max(0.05, $r['next_in'])) * 1_000_000)); // re-check every second at most
        } while (microtime(true) < $deadline);

        return $stats;
    }

    /**
     * One pass: sends every ping whose cooldown has ended.
     *
     * @return array{sent: int, failed: int, next_in: ?float} next_in = seconds until the next pending ping is due
     */
    public function sendDue(): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'next_in' => null];
        $rows  = $this->db->table('project_webhooks')->where('pending_at IS NOT NULL')->get()->getResultArray();
        $now   = microtime(true);
        foreach ($rows as $row) {
            $due = $row['last_attempt_at'] !== null ? self::ts($row['last_attempt_at']) + (int) $row['cooldown_seconds'] : $now;
            if ($due > $now) {
                $stats['next_in'] = min($stats['next_in'] ?? PHP_FLOAT_MAX, $due - $now);

                continue;
            }
            $ok = $this->sendOne((int) $row['project_id']);
            if ($ok !== null) {
                $stats[$ok ? 'sent' : 'failed']++;
            }
        }

        return $stats;
    }

    /** @return bool|null null = nothing to send any more (deleted or taken by another run) */
    private function sendOne(int $projectId): ?bool
    {
        $row = Transaction::run($this->db, function () use ($projectId): ?array {
            $row = $this->db->query('SELECT * FROM project_webhooks WHERE project_id = ? AND pending_at IS NOT NULL FOR UPDATE', [$projectId])->getRowArray();
            if ($row !== null) {
                // cleared before sending: mail imported while the request runs gets the next ping
                $this->db->table('project_webhooks')->where('project_id', $projectId)->update(['pending_at' => null, 'last_attempt_at' => Time::now()]);
            }

            return $row;
        });
        if ($row === null) {
            return null;
        }

        $t0 = microtime(true);
        try {
            $target = $this->webhooks->resolveTarget($row['url']); // again at send time: DNS may have changed
            $result = ($this->transport)($row['url'], $target['host'], $target['ips']);
        } catch (ConfigException $e) {
            $result = ['code' => null, 'error' => str_contains($e->getMessage(), 'resolve') && ! str_contains($e->getMessage(), 'non-public') ? 'dns' : 'blocked_address'];
        }
        $ms   = (int) round((microtime(true) - $t0) * 1000);
        $code = $result['code'];
        $ok   = $result['error'] === null && $code !== null && $code >= 200 && $code < 300;
        $err  = $ok ? null : ($result['error'] ?? 'http');

        Transaction::run($this->db, function () use ($row, $projectId, $ok, $code, $err, $ms): void {
            $now = Time::now();
            $this->db->table('project_webhooks')->where('project_id', $projectId)->update(['last_status' => $ok ? 'sent' : 'failed',
                'last_http_code' => $code, 'last_error' => $err] + ($ok ? ['last_sent_at' => $now] : ['last_failed_at' => $now]));
            $customer = $this->db->table('projects')->select('customer_id')->where('id', $projectId)->get()->getRow('customer_id');
            $this->journal->append($ok ? 'webhook.sent' : 'webhook.failed', new ChangeContext('system', 'mr:webhook:send'),
                $customer !== null ? (int) $customer : null, $projectId, array_filter([
                    'url' => WebhookService::mask($row['url']), 'http_code' => $code, 'error' => $err, 'ms' => $ms,
                    'pending_since' => Time::toIso($row['pending_at']),
                ], static fn ($v) => $v !== null));
        });

        return $ok;
    }

    private static function ts(string $dbTime): float
    {
        return (float) (new \DateTimeImmutable($dbTime, new \DateTimeZone('UTC')))->format('U.u');
    }

    /**
     * @param list<string> $ips
     *
     * @return array{code: ?int, error: ?string}
     */
    private static function curl(string $url, string $host, array $ips): array
    {
        $received = 0;
        $ch       = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_HTTPHEADER     => ['Content-Type:', 'Expect:', 'User-Agent: MailRunner/' . (new NodeInfo(config('Mailrunner')))->version(), 'X-MailRunner-Event: messages'],
            CURLOPT_RESOLVE        => [$host . ':443:' . implode(',', array_map(static fn ($ip) => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $ips))],
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY          => '',
            CURLOPT_NOPROXY        => '*',
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // the answer is not needed: read at most 64 KiB, then stop
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $data) use (&$received): int {
                $received += strlen($data);

                return $received > 65536 ? 0 : strlen($data);
            },
        ]);
        $okExec = curl_exec($ch);
        $code   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno  = curl_errno($ch);
        if ($okExec === false && ! ($errno === CURLE_WRITE_ERROR && $code > 0)) {
            $error = match ($errno) {
                CURLE_OPERATION_TIMEDOUT => 'timeout',
                CURLE_COULDNT_CONNECT    => 'connect',
                CURLE_COULDNT_RESOLVE_HOST => 'dns',
                CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CACERT_BADFILE => 'tls',
                default                  => 'curl_' . $errno,
            };

            return ['code' => $code > 0 ? $code : null, 'error' => $error];
        }

        return ['code' => $code, 'error' => null];
    }
}
