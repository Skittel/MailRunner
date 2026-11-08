<?php

namespace App\Core\Send;

use App\Core\Journal\JournalWriter;
use App\Core\Projects\ProjectService;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;
use App\Core\Support\Time;
use App\Core\Support\Transaction;
use CodeIgniter\Database\BaseConnection;

/**
 * Central distribution of the send configuration (decision E-27, after step 7): sender domains with their DKIM key,
 * send profiles and accesses. The customer's application changes them on any node of the project; MailRunner Admin
 * notices the change by the configuration hash, takes the full set over (export, with secrets, only over the mTLS
 * admin API) and puts it on the other nodes of the project (apply).
 *
 * The set is canonical: no row ids, no timestamps that differ between nodes, sorted. Applying the set of node A on
 * node B yields the same hash on B. Not part of the set (stays per node): domain test state, last use, the queue.
 */
class SendConfigSync
{
    private const TOKEN_RE = '/^mrt_[a-z0-9]{16}$/';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly JournalWriter $journal,
        private readonly SendDomainService $domains,
        private readonly SendProfileService $profiles,
        private readonly SendAccessService $accesses,
    ) {
    }

    /**
     * The canonical set. Secrets (private keys, token and password hashes) only with $secrets.
     *
     * @return array{domains: list<array<string, mixed>>, profiles: list<array<string, mixed>>, accesses: list<array<string, mixed>>}
     */
    public function snapshot(int $projectId, bool $secrets = false): array
    {
        $domains = [];
        foreach ($this->domains->list($projectId) as $d) {
            $domains[] = ['domain' => $d['domain'], 'selector' => $d['dkim_selector'], 'algorithm' => $d['dkim_algorithm'], 'bits' => (int) $d['dkim_bits'],
                'key_sha256' => $d['key_sha256']] + ($secrets ? ['private_key' => $this->domains->privateKey($d)] : []);
        }
        $profiles = [];
        $names    = [];
        foreach ($this->profiles->list($projectId) as $p) {
            $names[(int) $p['id']] = $p['name'];
            $profiles[] = ['name' => $p['name'], 'profile_id' => $p['profile_id'], 'label' => $p['label'], 'enabled' => (bool) $p['enabled'],
                'senders' => json_decode($p['senders'], true), 'recipients' => $p['recipients'] === null ? null : json_decode($p['recipients'], true),
                'limit_hour' => (int) $p['limit_hour'], 'limit_day' => (int) $p['limit_day'], 'retry_attempts' => (int) $p['retry_attempts'],
                'retry_seconds' => (int) $p['retry_seconds'], 'return_path_domain' => $p['return_path_domain'], 'test_interval_hours' => (int) $p['test_interval_hours']];
        }
        usort($profiles, static fn ($a, $b) => strcmp($a['name'], $b['name']));

        $tokens = [];
        $creds  = [];
        $rows   = $this->db->table('send_accesses')->where('project_id', $projectId)->orderBy('access_id')->get()->getResultArray();
        $tids   = array_values(array_filter(array_merge(array_column($rows, 'api_token_id'), array_column($rows, 'api_token_prev_id'))));
        $cids   = array_values(array_filter(array_column($rows, 'smtp_credential_id')));
        if ($tids !== []) {
            foreach ($this->db->table('api_tokens')->whereIn('id', $tids)->get()->getResultArray() as $t) {
                $tokens[(int) $t['id']] = $t;
            }
        }
        if ($cids !== []) {
            foreach ($this->db->table('smtp_credentials')->whereIn('id', $cids)->get()->getResultArray() as $c) {
                $creds[(int) $c['id']] = $c;
            }
        }
        $token = static fn (array $t): array => ['token_id' => $t['token_id'], 'label' => $t['label'], 'secret_hash' => $t['secret_hash'],
            'expires_at' => Time::toIso($t['expires_at']), 'revoked_at' => Time::toIso($t['revoked_at'])];
        $accesses = [];
        foreach ($rows as $a) {
            $out = ['access_id' => $a['access_id'], 'profile' => $names[(int) $a['profile_id']] ?? '', 'type' => $a['type'], 'label' => $a['label'], 'enabled' => (bool) $a['enabled']];
            if ($a['type'] === 'api') {
                $out['token']      = isset($tokens[(int) $a['api_token_id']]) ? $token($tokens[(int) $a['api_token_id']]) : null;
                $out['token_prev'] = $a['api_token_prev_id'] !== null && isset($tokens[(int) $a['api_token_prev_id']]) ? $token($tokens[(int) $a['api_token_prev_id']]) : null;
            } elseif ($a['type'] === 'smtp' && isset($creds[(int) $a['smtp_credential_id']])) {
                $c            = $creds[(int) $a['smtp_credential_id']];
                $out['smtp'] = ['credential_id' => $c['credential_id'], 'username' => $c['username'], 'label' => $c['label'], 'password_hash' => $c['password_hash'],
                    'previous_password_hash' => $c['previous_password_hash'], 'previous_valid_until' => Time::toIso($c['previous_valid_until']),
                    'tls_required' => (bool) $c['tls_required'], 'allowed_networks' => $c['allowed_networks'] === null ? null : json_decode($c['allowed_networks'], true)];
            } elseif ($a['type'] === 'ip') {
                $out['networks'] = json_decode((string) $a['networks'], true);
            }
            $accesses[] = $out;
        }

        return ['domains' => $domains, 'profiles' => $profiles, 'accesses' => $accesses];
    }

    /** SHA-256 of the canonical set (with hashes, without private keys: key_sha256 stands for the key). */
    public function hash(int $projectId): string
    {
        return self::hashOf($this->snapshot($projectId));
    }

    /** @param array<string, mixed> $set */
    public static function hashOf(array $set): string
    {
        foreach ($set['domains'] ?? [] as $i => $d) {
            unset($set['domains'][$i]['private_key']);
        }

        return hash('sha256', json_encode($set, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Latest local change of the set (tie-break when two nodes changed at once); deletions are not seen. */
    public function changedAt(int $projectId): ?string
    {
        $t = $this->db->query(
            'SELECT GREATEST(COALESCE((SELECT MAX(updated_at) FROM send_domains WHERE project_id = ?), 0),
                             COALESCE((SELECT MAX(updated_at) FROM send_profiles WHERE project_id = ?), 0),
                             COALESCE((SELECT MAX(updated_at) FROM send_accesses WHERE project_id = ?), 0)) AS t',
            [$projectId, $projectId, $projectId],
        )->getRow('t');

        return $t === null || (string) $t === '0' ? null : Time::toIso((string) $t);
    }

    /**
     * Makes the node's set equal to $set. With $ifHash the node refuses when its set changed in the meantime
     * (SEND_CONFIG_CHANGED: the admin takes the local change over first). Returns the new hash and counts.
     *
     * @param array<string, mixed> $set
     *
     * @return array{hash: string, changed: array<string, int>}
     */
    public function apply(int $projectId, array $set, ?string $ifHash, ChangeContext $ctx): array
    {
        foreach (['domains', 'profiles', 'accesses'] as $k) {
            if (! is_array($set[$k] ?? null) || ! array_is_list($set[$k])) {
                throw new ConfigException('INVALID_SEND_CONFIG', 'domains, profiles and accesses must be lists');
            }
        }

        return Transaction::run($this->db, function () use ($projectId, $set, $ifHash, $ctx): array {
            $project = $this->domains->sendProject($projectId); // locks the project row: one apply at a time
            if ($ifHash !== null && ! hash_equals($ifHash, $this->hash($projectId))) {
                throw new ConfigException('SEND_CONFIG_CHANGED', 'The send configuration on this node changed since it was read; take it over first');
            }
            $n = ['domains' => 0, 'profiles' => 0, 'accesses' => 0, 'removed' => 0];

            // domains: same selector and key change nothing (the domain test of this node stays valid)
            $want = [];
            foreach ($set['domains'] as $d) {
                if (! is_string($d['domain'] ?? null) || ! is_string($d['selector'] ?? null) || ! is_string($d['private_key'] ?? null)) {
                    throw new ConfigException('INVALID_SEND_CONFIG', 'Every domain needs domain, selector and private_key');
                }
                $want[SendDomainService::normalize($d['domain'])] = true;
                [$row, $changed] = $this->domains->set($projectId, $d['domain'], $d['selector'], $d['private_key'], $d['algorithm'] ?? null, $ctx);
                if (isset($d['key_sha256']) && $row['key_sha256'] !== $d['key_sha256']) {
                    throw new ConfigException('INVALID_SEND_CONFIG', 'Private key of ' . $row['domain'] . ' does not match key_sha256');
                }
                $n['domains'] += $changed ? 1 : 0;
            }

            // accesses of profiles that go away, then the profiles, then the domains
            $wantAccess = array_column($set['accesses'], null, 'access_id');
            foreach ($this->db->table('send_accesses a')->select('a.*')->where('a.project_id', $projectId)->get()->getResultArray() as $a) {
                if (! isset($wantAccess[$a['access_id']])) {
                    $this->accesses->revokeRow($a);
                    $n['removed']++;
                }
            }
            $wantProfiles = array_column($set['profiles'], null, 'name');
            foreach ($this->profiles->list($projectId) as $p) {
                if (! isset($wantProfiles[$p['name']])) {
                    $this->profiles->delete($projectId, $p['name'], $ctx, $this->accesses);
                    $n['removed']++;
                }
            }
            foreach ($this->domains->list($projectId) as $d) {
                if (! isset($want[$d['domain']])) {
                    $this->domains->delete($projectId, $d['domain'], $ctx);
                    $n['removed']++;
                }
            }

            foreach ($set['profiles'] as $p) {
                if (! is_string($p['name'] ?? null) || ! is_string($p['profile_id'] ?? null) || preg_match('/^mrp_[a-z0-9]{16}$/', $p['profile_id']) !== 1) {
                    throw new ConfigException('INVALID_SEND_CONFIG', 'Every profile needs name and profile_id');
                }
                [$row, $changed] = $this->profiles->put($projectId, $p['name'], [
                    'label' => $p['label'] ?? $p['name'], 'enabled' => (bool) ($p['enabled'] ?? true), 'senders' => $p['senders'] ?? null, 'recipients' => $p['recipients'] ?? null,
                    'limit_hour' => $p['limit_hour'] ?? null, 'limit_day' => $p['limit_day'] ?? null,
                    'retry' => ['attempts' => (int) ($p['retry_attempts'] ?? 0), 'max_minutes' => intdiv((int) ($p['retry_seconds'] ?? 0), 60)],
                    'return_path_domain' => $p['return_path_domain'] ?? null, 'test_interval_hours' => $p['test_interval_hours'] ?? null,
                ], $ctx);
                if ($row['profile_id'] !== $p['profile_id']) { // same public id on every node
                    if ($this->db->table('send_profiles')->where('profile_id', $p['profile_id'])->where('id !=', $row['id'])->countAllResults() > 0) {
                        throw new ConfigException('SEND_CONFIG_CONFLICT', 'Profile id ' . $p['profile_id'] . ' belongs to another project on this node');
                    }
                    $this->db->table('send_profiles')->where('id', $row['id'])->update(['profile_id' => $p['profile_id']]);
                    $changed = true;
                }
                $n['profiles'] += $changed ? 1 : 0;
            }

            foreach ($set['accesses'] as $a) {
                $n['accesses'] += $this->putAccess($project, $a, $ctx) ? 1 : 0;
            }

            $hash = $this->hash($projectId);
            if ($n !== ['domains' => 0, 'profiles' => 0, 'accesses' => 0, 'removed' => 0]) {
                $this->journal->append('send.config.synced', $ctx, (int) $project['customer_id'], $projectId, $n + ['hash' => $hash]);
                ProjectService::requestApply(); // mail path (credentials.json, send.json, send_clients.cidr)
            }

            return ['hash' => $hash, 'changed' => $n];
        });
    }

    /**
     * Creates or updates one access with the given ids and hashes. True if something changed.
     *
     * @param array<string, mixed> $project
     * @param array<string, mixed> $a
     */
    private function putAccess(array $project, array $a, ChangeContext $ctx): bool
    {
        $pid = (int) $project['id'];
        if (preg_match('/^mra_[a-z0-9]{16}$/', (string) ($a['access_id'] ?? '')) !== 1 || ! in_array($a['type'] ?? null, SendAccessService::TYPES, true)) {
            throw new ConfigException('INVALID_SEND_CONFIG', 'Every access needs access_id (mra_ + 16 chars) and type');
        }
        $profile = $this->profiles->get($pid, (string) ($a['profile'] ?? ''));
        $cur     = $this->db->table('send_accesses')->where('access_id', $a['access_id'])->get()->getRowArray();
        if ($cur !== null && ((int) $cur['project_id'] !== $pid)) {
            throw new ConfigException('SEND_CONFIG_CONFLICT', 'Access ' . $a['access_id'] . ' belongs to another project on this node');
        }
        if ($cur !== null && $cur['type'] !== $a['type']) {
            $this->accesses->revokeRow($cur);
            $cur = null;
        }
        $row = ['profile_id' => (int) $profile['id'], 'type' => $a['type'], 'label' => mb_substr((string) ($a['label'] ?? ''), 0, 100), 'enabled' => ! empty($a['enabled']) ? 1 : 0,
            'api_token_id' => null, 'api_token_prev_id' => null, 'smtp_credential_id' => null, 'networks' => null];
        switch ($a['type']) {
            case 'api':
                if (! is_array($a['token'] ?? null)) {
                    throw new ConfigException('INVALID_SEND_CONFIG', 'Access ' . $a['access_id'] . ': api needs token');
                }
                $row['api_token_id']      = $this->putToken($pid, $a['token'], $ctx);
                $row['api_token_prev_id'] = is_array($a['token_prev'] ?? null) ? $this->putToken($pid, $a['token_prev'], $ctx) : null;
                break;
            case 'smtp':
                if (! is_array($a['smtp'] ?? null)) {
                    throw new ConfigException('INVALID_SEND_CONFIG', 'Access ' . $a['access_id'] . ': smtp needs smtp');
                }
                $row['smtp_credential_id'] = $this->putCredential($pid, $a['smtp'], (bool) $row['enabled']);
                break;
            case 'ip':
                $row['networks'] = json_encode($this->accesses->checkNetworks($a['networks'] ?? null, $cur === null ? null : (int) $cur['id']));
                break;
        }
        $now = Time::now();
        if ($cur === null) {
            $this->db->table('send_accesses')->insert($row + ['access_id' => $a['access_id'], 'project_id' => $pid, 'created_at' => $now, 'updated_at' => $now]);

            return true;
        }
        // tokens / logins this access no longer uses stop working
        $gone = array_diff(array_filter([(int) $cur['api_token_id'], (int) $cur['api_token_prev_id']]), array_filter([(int) $row['api_token_id'], (int) $row['api_token_prev_id']]));
        if ($gone !== []) {
            $this->db->table('api_tokens')->whereIn('id', array_values($gone))->where('revoked_at', null)->update(['revoked_at' => $now]);
        }
        if ($cur['smtp_credential_id'] !== null && (int) $cur['smtp_credential_id'] !== (int) $row['smtp_credential_id']) {
            $this->db->table('smtp_credentials')->where('id', $cur['smtp_credential_id'])->where('revoked_at', null)->update(['revoked_at' => $now, 'enabled' => 0, 'updated_at' => $now]);
        }
        $same = true;
        foreach ($row as $k => $v) {
            $same = $same && (string) ($cur[$k] ?? '') === (string) ($v ?? '');
        }
        if ($same) {
            return false;
        }
        $this->db->table('send_accesses')->where('id', $cur['id'])->update($row + ['updated_at' => $now]);

        return true;
    }

    /** Token row of a send access with the given id and hash; returns the row id. @param array<string, mixed> $t */
    private function putToken(int $projectId, array $t, ChangeContext $ctx): int
    {
        if (preg_match(self::TOKEN_RE, (string) ($t['token_id'] ?? '')) !== 1 || preg_match('/^[0-9a-f]{64}$/', (string) ($t['secret_hash'] ?? '')) !== 1) {
            throw new ConfigException('INVALID_SEND_CONFIG', 'Every token needs token_id (mrt_ + 16 chars) and secret_hash (sha256 hex)');
        }
        $want = ['label' => mb_substr((string) ($t['label'] ?? ''), 0, 100), 'secret_hash' => $t['secret_hash'],
            'expires_at' => self::time($t['expires_at'] ?? null), 'revoked_at' => self::time($t['revoked_at'] ?? null)];
        $cur = $this->db->table('api_tokens')->where('token_id', $t['token_id'])->get()->getRowArray();
        if ($cur === null) {
            $this->db->table('api_tokens')->insert($want + ['token_id' => $t['token_id'], 'project_id' => $projectId, 'scope' => 'send',
                'created_at' => Time::now(), 'created_source' => $ctx->source, 'last_used_at' => null, 'rotated_from_id' => null]);

            return (int) $this->db->insertID();
        }
        if ((int) $cur['project_id'] !== $projectId || $cur['scope'] !== 'send') {
            throw new ConfigException('SEND_CONFIG_CONFLICT', 'Token ' . $t['token_id'] . ' belongs to another project or is a project token on this node');
        }
        $want['revoked_at'] = $cur['revoked_at'] ?? $want['revoked_at']; // a revocation is never undone
        if (array_map('strval', array_intersect_key($cur, $want)) !== array_map('strval', $want)) {
            $this->db->table('api_tokens')->where('id', $cur['id'])->update($want);
        }

        return (int) $cur['id'];
    }

    /** SMTP login of a send access with the given id and password hash; returns the row id. @param array<string, mixed> $c */
    private function putCredential(int $projectId, array $c, bool $enabled): int
    {
        if (preg_match('/^mrc_[a-z0-9]{16}$/', (string) ($c['credential_id'] ?? '')) !== 1 || ! is_string($c['username'] ?? null) || $c['username'] === ''
            || ! is_string($c['password_hash'] ?? null) || ! str_starts_with($c['password_hash'], '$argon2id$')) {
            throw new ConfigException('INVALID_SEND_CONFIG', 'Every SMTP login needs credential_id, username and an Argon2id password_hash');
        }
        $networks = $c['allowed_networks'] ?? null;
        $want     = ['username' => $c['username'], 'label' => mb_substr((string) ($c['label'] ?? $c['username']), 0, 100), 'password_hash' => $c['password_hash'],
            'previous_password_hash' => is_string($c['previous_password_hash'] ?? null) ? $c['previous_password_hash'] : null,
            'previous_valid_until' => self::time($c['previous_valid_until'] ?? null), 'tls_required' => ! empty($c['tls_required']) ? 1 : 0,
            'allowed_networks' => is_array($networks) ? json_encode(array_values($networks)) : null, 'enabled' => $enabled ? 1 : 0];
        $cur = $this->db->table('smtp_credentials')->where('credential_id', $c['credential_id'])->get()->getRowArray();
        $now = Time::now();
        if ($cur !== null && ((int) $cur['project_id'] !== $projectId || $cur['scope'] !== 'send')) {
            throw new ConfigException('SEND_CONFIG_CONFLICT', 'SMTP login ' . $c['credential_id'] . ' belongs to another project on this node');
        }
        $this->db->table('smtp_credentials')->where('username', $want['username'])->where('revoked_at IS NOT NULL')->where('credential_id !=', $c['credential_id'])->delete();
        if ($this->db->table('smtp_credentials')->where('username', $want['username'])->where('credential_id !=', $c['credential_id'])->countAllResults() > 0) {
            throw new ConfigException('USERNAME_EXISTS', 'SMTP username "' . $want['username'] . '" already exists on this node');
        }
        if ($cur === null) {
            $this->db->table('smtp_credentials')->insert($want + ['credential_id' => $c['credential_id'], 'project_id' => $projectId, 'scope' => 'send',
                'created_at' => $now, 'updated_at' => $now]);

            return (int) $this->db->insertID();
        }
        if ($cur['revoked_at'] === null && array_map('strval', array_intersect_key($cur, $want)) !== array_map('strval', $want)) {
            $this->db->table('smtp_credentials')->where('id', $cur['id'])->update($want + ['updated_at' => $now]);
        }

        return (int) $cur['id'];
    }

    private static function time(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $ts = strtotime((string) $v);
        if ($ts === false) {
            throw new ConfigException('INVALID_SEND_CONFIG', 'Invalid time ' . (string) $v);
        }
        $micro = preg_match('/\.(\d{1,6})/', (string) $v, $m) === 1 ? str_pad($m[1], 6, '0') : '000000';

        return gmdate('Y-m-d H:i:s', $ts) . '.' . $micro;
    }
}
