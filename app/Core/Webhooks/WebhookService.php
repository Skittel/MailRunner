<?php

namespace App\Core\Webhooks;

use App\Core\Journal\JournalWriter;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;
use App\Core\Support\Time;
use App\Core\Support\Transaction;
use Closure;
use CodeIgniter\Database\BaseConnection;

/**
 * Webhook of a project: the customer sets a URL and a cooldown over the API; MailRunner sends an empty POST when
 * new mail can be fetched (WebhookSender). A ping is only a hint: no content, no retries, the application keeps
 * polling. Targets: https on port 443, host names that resolve only to public addresses (checked again at send time).
 *
 * The URL may carry a secret (random path): journal and API show it shortened (mask()), the API adds its SHA-256.
 */
class WebhookService
{
    public const MIN_COOLDOWN     = 1;
    public const MAX_COOLDOWN     = 3600;
    public const DEFAULT_COOLDOWN = 2;
    public const MAX_URL          = 2048;

    /** @var Closure(string): list<string> host -> IP addresses */
    private readonly Closure $resolver;

    /**
     * @param (callable(string): list<string>)|null $resolver
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly JournalWriter $journal,
        private readonly ?string $requestFile = null,
        ?callable $resolver = null,
    ) {
        $this->resolver = $resolver !== null ? Closure::fromCallable($resolver) : self::dnsResolve(...);
    }

    /** @return array<string, mixed>|null */
    public function get(int $projectId): ?array
    {
        return $this->db->table('project_webhooks')->where('project_id', $projectId)->get()->getRowArray();
    }

    /**
     * @return array{0: array<string, mixed>, 1: bool} [webhook, changed]
     */
    public function set(int $projectId, string $url, ?int $cooldown, ChangeContext $ctx): array
    {
        $url      = trim($url);
        $cooldown ??= self::DEFAULT_COOLDOWN;
        if ($cooldown < self::MIN_COOLDOWN || $cooldown > self::MAX_COOLDOWN) {
            throw new ConfigException('INVALID_REQUEST', sprintf('cooldown_seconds must be %d-%d', self::MIN_COOLDOWN, self::MAX_COOLDOWN));
        }
        $this->resolveTarget($url); // throws INVALID_WEBHOOK_URL

        return Transaction::run($this->db, function () use ($projectId, $url, $cooldown, $ctx): array {
            $old = $this->db->table('project_webhooks')->where('project_id', $projectId)->get()->getRowArray();
            if ($old !== null && $old['url'] === $url && (int) $old['cooldown_seconds'] === $cooldown) {
                return [$old, false];
            }
            $now  = Time::now();
            $data = ['url' => $url, 'cooldown_seconds' => $cooldown, 'updated_at' => $now, 'updated_by' => $ctx->actor];
            if ($old === null) {
                $this->db->table('project_webhooks')->insert($data + ['project_id' => $projectId, 'created_at' => $now]);
            } else {
                $this->db->table('project_webhooks')->where('project_id', $projectId)->update($data + ['last_status' => null,
                    'last_http_code' => null, 'last_error' => null]);
            }
            $this->journal->append('webhook.updated', $ctx, $this->customerId($projectId), $projectId, [
                'url' => self::mask($url), 'cooldown_seconds' => $cooldown, 'created' => $old === null,
            ]);

            return [$this->get($projectId), true];
        });
    }

    public function delete(int $projectId, ChangeContext $ctx): bool
    {
        return Transaction::run($this->db, function () use ($projectId, $ctx): bool {
            $old = $this->get($projectId);
            if ($old === null) {
                return false;
            }
            $this->db->table('project_webhooks')->where('project_id', $projectId)->delete();
            $this->journal->append('webhook.deleted', $ctx, $this->customerId($projectId), $projectId, ['url' => self::mask($old['url'])]);

            return true;
        });
    }

    /**
     * New mail was imported for these projects: projects with a webhook get a pending ping, the sender is woken up.
     *
     * @param list<int> $projectIds
     */
    public function markPending(array $projectIds): int
    {
        $projectIds = array_values(array_unique(array_map('intval', $projectIds)));
        if ($projectIds === []) {
            return 0;
        }
        $this->db->table('project_webhooks')->whereIn('project_id', $projectIds)->where('pending_at', null)->update(['pending_at' => Time::now()]);
        $n = $this->db->table('project_webhooks')->whereIn('project_id', $projectIds)->where('pending_at IS NOT NULL')->countAllResults();
        if ($n > 0 && $this->requestFile !== null) {
            @touch($this->requestFile); // mailrunner-webhook.path starts mr:webhook:send
        }

        return $n;
    }

    /**
     * Checks a webhook URL and resolves its host. Every address must be public (no loopback, private, link-local,
     * CGNAT, documentation or reserved ranges), otherwise the target is refused.
     *
     * @return array{host: string, port: int, ips: list<string>}
     */
    public function resolveTarget(string $url): array
    {
        $fail = static fn (string $why) => new ConfigException('INVALID_WEBHOOK_URL', $why);
        if ($url === '' || strlen($url) > self::MAX_URL || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw $fail('url must be 1-' . self::MAX_URL . ' characters without spaces or control characters');
        }
        $p = parse_url($url);
        if ($p === false || strtolower($p['scheme'] ?? '') !== 'https' || ($p['host'] ?? '') === '') {
            throw $fail('only https:// URLs are allowed');
        }
        if (isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) {
            throw $fail('url must not contain user info or a fragment');
        }
        if (isset($p['port']) && (int) $p['port'] !== 443) {
            throw $fail('only port 443 is allowed');
        }
        $host = strtolower(rtrim(trim($p['host'], '[]'), '.'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw $fail('local host names are not allowed');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);
        if ($ips === []) {
            throw $fail('host name does not resolve');
        }
        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                throw $fail('host resolves to a non-public address');
            }
        }

        return ['host' => $host, 'port' => 443, 'ips' => array_values($ips)];
    }

    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }
        // IPv4-mapped/compatible/NAT64/6to4 forms could reach private IPv4 addresses
        $bin = inet_pton($ip);

        return ! (strlen($bin) === 16 && (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff") || str_starts_with($bin, str_repeat("\0", 12))
            || str_starts_with($bin, "\x00\x64\xff\x9b") || str_starts_with($bin, "\x20\x02")));
    }

    /** "https://host/…wxyz": host and the last 4 characters of a long path, never the whole URL. */
    public static function mask(string $url): string
    {
        $p    = parse_url($url) ?: [];
        $rest = ($p['path'] ?? '') . (isset($p['query']) ? '?' . $p['query'] : '');
        $tail = strlen($rest) > 1 ? '/…' . (strlen($rest) >= 16 ? substr($rest, -4) : '') : '';

        return 'https://' . ($p['host'] ?? '?') . $tail;
    }

    /**
     * API/admin view of a webhook (never the full URL).
     *
     * @param array<string, mixed>|null $w
     *
     * @return array<string, mixed>|null
     */
    public static function present(?array $w): ?array
    {
        if ($w === null) {
            return null;
        }

        return [
            'url' => self::mask($w['url']), 'url_sha256' => hash('sha256', $w['url']), 'cooldown_seconds' => (int) $w['cooldown_seconds'],
            'created_at' => Time::toIso($w['created_at']), 'updated_at' => Time::toIso($w['updated_at']),
            'pending' => $w['pending_at'] !== null, 'last_attempt_at' => Time::toIso($w['last_attempt_at']),
            'last_status' => $w['last_status'], 'last_http_code' => $w['last_http_code'] !== null ? (int) $w['last_http_code'] : null,
            'last_error' => $w['last_error'], 'last_sent_at' => Time::toIso($w['last_sent_at']), 'last_failed_at' => Time::toIso($w['last_failed_at']),
        ];
    }

    private function customerId(int $projectId): ?int
    {
        $id = $this->db->table('projects')->select('customer_id')->where('id', $projectId)->get()->getRow('customer_id');

        return $id !== null ? (int) $id : null;
    }

    /** @return list<string> */
    private static function dnsResolve(string $host): array
    {
        $ips = [];
        foreach ([DNS_A, DNS_AAAA] as $type) {
            foreach (@dns_get_record($host, $type) ?: [] as $r) {
                if (isset($r['ip']) || isset($r['ipv6'])) {
                    $ips[] = $r['ip'] ?? $r['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
