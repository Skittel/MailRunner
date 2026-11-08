<?php

namespace App\Core\Send;

use App\Core\Journal\JournalWriter;
use App\Core\Projects\ProjectService;
use App\Core\Secrets\NodeCrypto;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;
use App\Core\Support\Time;
use App\Core\Support\Transaction;
use Closure;
use CodeIgniter\Database\BaseConnection;

/**
 * Sender domains of a send project with their DKIM key (decision E-27). Without a domain entry and a valid key a
 * domain cannot send. The private key is stored encrypted with the node key and never returned; journal and API
 * show selector, algorithm, size and the SHA-256 of the public key.
 */
class SendDomainService
{
    public const MAX_DOMAINS = 500;

    /** @var Closure(): NodeCrypto */
    private readonly Closure $crypto;

    /**
     * @param (callable(): NodeCrypto)|null $crypto
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly JournalWriter $journal,
        ?callable $crypto = null,
    ) {
        $this->crypto = $crypto !== null ? Closure::fromCallable($crypto) : static fn (): NodeCrypto => NodeCrypto::forNode();
    }

    /** @return list<array<string, mixed>> */
    public function list(int $projectId): array
    {
        return $this->db->table('send_domains')->where('project_id', $projectId)->orderBy('domain')->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function get(int $projectId, string $domain): ?array
    {
        return $this->db->table('send_domains')->where('project_id', $projectId)->where('domain', self::normalize($domain))->get()->getRowArray();
    }

    /**
     * Sets or replaces the DKIM key of a domain (idempotent: the same key and selector change nothing).
     *
     * @return array{0: array<string, mixed>, 1: bool} [domain row, changed]
     */
    public function set(int $projectId, string $domain, string $selector, string $privateKey, ?string $algorithm, ChangeContext $ctx): array
    {
        $domain   = self::normalize($domain);
        $selector = strtolower(trim($selector));
        if (! ProjectService::isDomainName($domain)) {
            throw new ConfigException('INVALID_DOMAIN', 'Not a valid domain name');
        }
        if (! DkimKeys::validSelector($selector)) {
            throw new ConfigException('INVALID_DKIM_SELECTOR', 'selector must be a DNS label (a-z, 0-9, "-", dots between labels), 63 characters at most');
        }
        $key = DkimKeys::parse($privateKey, $algorithm);

        return Transaction::run($this->db, function () use ($projectId, $domain, $selector, $key, $ctx): array {
            $project = $this->sendProject($projectId);
            if (! ProjectService::sendDomainAllowed($project, $domain)) {
                throw new ConfigException('DOMAIN_NOT_ALLOWED', 'Domain "' . $domain . '" is not allowed for this project');
            }
            $old = $this->get($projectId, $domain);
            $sha = hash('sha256', $key['public']);
            if ($old !== null && $old['dkim_selector'] === $selector && $old['key_sha256'] === $sha) {
                return [$old, false];
            }
            if ($old === null && $this->db->table('send_domains')->where('project_id', $projectId)->countAllResults() >= self::MAX_DOMAINS) {
                throw new ConfigException('TOO_MANY_DOMAINS', 'At most ' . self::MAX_DOMAINS . ' domains per project');
            }
            $now  = Time::now();
            $data = [
                'dkim_selector' => $selector, 'dkim_algorithm' => $key['algorithm'], 'dkim_bits' => $key['bits'],
                'dkim_private_enc' => ($this->crypto)()->encrypt($key['private_key'], self::context($projectId, $domain)),
                'dkim_public' => $key['public'], 'key_sha256' => $sha, 'updated_at' => $now, 'updated_by' => $ctx->actor,
                'test_requested_at' => $now, // a new key needs a new domain test (mr-sender starts it)
            ];
            if ($old === null) {
                $this->db->table('send_domains')->insert($data + ['project_id' => $projectId, 'domain' => $domain, 'created_at' => $now]);
            } else {
                $this->db->table('send_domains')->where('id', $old['id'])->update($data);
            }
            $this->journal->append('send.domain.updated', $ctx, (int) $project['customer_id'], $projectId, [
                'domain' => $domain, 'selector' => $selector, 'algorithm' => $key['algorithm'], 'bits' => $key['bits'],
                'key_sha256' => $sha, 'created' => $old === null,
            ]);

            return [$this->get($projectId, $domain), true];
        });
    }

    public function delete(int $projectId, string $domain, ChangeContext $ctx): bool
    {
        $domain = self::normalize($domain);

        return Transaction::run($this->db, function () use ($projectId, $domain, $ctx): bool {
            $project = $this->sendProject($projectId);
            $old     = $this->get($projectId, $domain);
            if ($old === null) {
                return false;
            }
            $this->db->table('send_domains')->where('id', $old['id'])->delete();
            $this->journal->append('send.domain.deleted', $ctx, (int) $project['customer_id'], $projectId, [
                'domain' => $domain, 'selector' => $old['dkim_selector'],
            ]);

            return true;
        });
    }

    /**
     * Asks mr-sender for a domain test now (E-27 step 5). Returns the domain row.
     *
     * @return array<string, mixed>
     */
    public function requestTest(int $projectId, string $domain, ChangeContext $ctx): array
    {
        return Transaction::run($this->db, function () use ($projectId, $domain, $ctx): array {
            $project = $this->sendProject($projectId);
            $row     = $this->get($projectId, $domain) ?? throw new ConfigException('DOMAIN_NOT_FOUND', 'Domain not set');
            if ($row['test_requested_at'] === null) {
                $this->db->table('send_domains')->where('id', $row['id'])->update(['test_requested_at' => Time::now()]);
                $this->journal->append('send.domain.test_requested', $ctx, (int) $project['customer_id'], $projectId, ['domain' => $row['domain']]);
            }

            return $this->get($projectId, $domain);
        });
    }

    /**
     * The last test runs of a domain, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function tests(array $row, int $limit = 20): array
    {
        return array_map(static fn ($t) => [
            'started_at' => Time::toIso($t['started_at']), 'finished_at' => Time::toIso($t['finished_at']), 'trigger' => $t['trigger_reason'],
            'outcome' => $t['outcome'], 'key_sha256' => $t['key_sha256'], 'details' => $t['details'] === null ? null : json_decode($t['details'], true),
        ], $this->db->table('send_domain_tests')->where('domain_row_id', $row['id'])->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray());
    }

    /** Decrypted private key, for the delivery worker and the domain test (never for the API). */
    public function privateKey(array $row): string
    {
        return ($this->crypto)()->decrypt($row['dkim_private_enc'], self::context((int) $row['project_id'], $row['domain']));
    }

    /**
     * API view: never the private key.
     *
     * @return array<string, mixed>
     */
    public static function present(array $row, ?array $project = null): array
    {
        return [
            'domain' => $row['domain'],
            'dkim' => ['selector' => $row['dkim_selector'], 'algorithm' => $row['dkim_algorithm'], 'bits' => (int) $row['dkim_bits'], 'key_sha256' => $row['key_sha256']],
            'dns' => DkimKeys::dnsRecord($row['domain'], $row['dkim_selector'], $row['dkim_algorithm'], $row['dkim_public']),
            'allowed' => $project === null ? null : ProjectService::sendDomainAllowed($project, $row['domain']),
            'test' => self::presentTest($row),
            'created_at' => Time::toIso($row['created_at']), 'updated_at' => Time::toIso($row['updated_at']),
        ];
    }

    /**
     * Domain test state (E-27 step 5). "valid": the domain may send (passed with exactly the current key). A test
     * made with an older key counts as untested. "error": the last run could not finish (e.g. SmartMailFlow
     * unavailable); the last valid result stays.
     *
     * @return array<string, mixed>
     */
    public static function presentTest(array $row): array
    {
        $current = ($row['test_key_sha256'] ?? null) === $row['key_sha256'];
        $status  = $row['test_status'] ?? 'untested';

        return [
            'status' => $current ? $status : 'untested', 'valid' => $current && $status === 'passed',
            'passed_at' => Time::toIso($row['test_passed_at'] ?? null), 'checked_at' => Time::toIso($row['test_checked_at'] ?? null),
            'next_at' => Time::toIso($row['test_next_at'] ?? null), 'requested' => ($row['test_requested_at'] ?? null) !== null,
            'running' => ($row['test_running_until'] ?? null) !== null && $row['test_running_until'] > Time::now(),
            'error' => $row['test_error'] ?? null, 'return_path_domain' => $row['test_return_path_domain'] ?? null,
            'details' => $current && ($row['test_details'] ?? null) !== null ? json_decode($row['test_details'], true) : null,
        ];
    }

    public static function normalize(string $domain): string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $domain) === 1) {
            $domain = (string) idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        }

        return $domain;
    }

    /**
     * The project row (locked), only if it is a send project.
     *
     * @return array<string, mixed>
     */
    public function sendProject(int $projectId, bool $lock = true): array
    {
        $p = $this->db->query('SELECT * FROM projects WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$projectId])->getRowArray()
            ?? throw new ConfigException('PROJECT_NOT_FOUND', 'Project not found');
        if ($p['type'] !== 'send') {
            throw new ConfigException('PROJECT_TYPE', 'This endpoint needs a project of type send');
        }

        return $p;
    }

    private static function context(int $projectId, string $domain): string
    {
        return 'dkim:' . $projectId . ':' . $domain;
    }
}
