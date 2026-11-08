<?php

namespace App\Core\Send;

use App\Core\Access\ApiTokenService;
use App\Core\Access\SmtpCredentialService;
use App\Core\Journal\JournalWriter;
use App\Core\Projects\ProjectService;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;
use App\Core\Support\RandomId;
use App\Core\Support\Time;
use App\Core\Support\Transaction;
use CodeIgniter\Database\BaseConnection;

/**
 * Accesses of a send profile (decision E-27), several per profile and of mixed types:
 *   api   a bearer token with scope send (only submits mail, cannot manage the project),
 *   smtp  a login (username/password) for ports 25/587/465, unique on the node across both roles,
 *   ip    client networks that may relay without login (IPv4 /29 and IPv6 /64 at most, never overlapping another
 *         ip access on the node, so a client address always maps to one profile).
 * Secrets (bearer, generated password) are returned once. Deleting an access revokes its token or login at once.
 */
class SendAccessService
{
    public const ID_PREFIX      = 'mra';
    public const TYPES          = ['api', 'smtp', 'ip'];
    public const MAX_ACCESSES   = 100;
    public const MAX_NETWORKS   = 16;
    public const MIN_PREFIX     = [4 => 29, 6 => 64];
    public const MAX_GRACE      = 604800;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly JournalWriter $journal,
        private readonly SendDomainService $domains,
        private readonly SendProfileService $profiles,
        private readonly ApiTokenService $tokens,
        private readonly SmtpCredentialService $credentials,
    ) {
    }

    /**
     * Accesses with their token / login details.
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $profileRowId): array
    {
        return $this->query()->where('a.profile_id', $profileRowId)->orderBy('a.id')->get()->getResultArray();
    }

    /** @return array<string, mixed> */
    public function get(int $projectId, string $accessId): array
    {
        return $this->query()->where('a.project_id', $projectId)->where('a.access_id', $accessId)->get()->getRowArray()
            ?? throw new ConfigException('ACCESS_NOT_FOUND', 'Send access "' . $accessId . '" not found');
    }

    /**
     * Number of accesses per profile and type.
     *
     * @return array<int, array<string, int>> profile row id => [type => count]
     */
    public function counts(int $projectId): array
    {
        $out = [];
        foreach ($this->db->query('SELECT profile_id, type, COUNT(*) AS n FROM send_accesses WHERE project_id = ? GROUP BY profile_id, type', [$projectId])->getResultArray() as $r) {
            $out[(int) $r['profile_id']][$r['type']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * The access (with profile name and enabled flags) a send token belongs to; also during a rotation grace period.
     *
     * @return array<string, mixed>|null
     */
    public function forToken(int $tokenRowId): ?array
    {
        return $this->db->query(
            'SELECT a.*, p.name AS profile_name, p.enabled AS profile_enabled FROM send_accesses a JOIN send_profiles p ON p.id = a.profile_id
             WHERE a.api_token_id = ? OR a.api_token_prev_id = ? LIMIT 1',
            [$tokenRowId, $tokenRowId],
        )->getRowArray();
    }

    /**
     * Creates an access.
     *
     * @param array<string, mixed> $req {type, label?, ...} api: {expires_in?}; smtp: {username, password?, plaintext_port25?, allowed_networks?}; ip: {networks}
     *
     * @return array{access: array<string, mixed>, bearer?: string, password?: string}
     */
    public function create(int $projectId, string $profileName, array $req, ChangeContext $ctx): array
    {
        $type = $req['type'] ?? null;
        if (! in_array($type, self::TYPES, true)) {
            throw new ConfigException('INVALID_ACCESS', 'type must be one of: ' . implode(', ', self::TYPES));
        }
        $allowed = array_merge(['type', 'label'], match ($type) {
            'api' => ['expires_in'], 'smtp' => ['username', 'password', 'plaintext_port25', 'allowed_networks'], 'ip' => ['networks'],
        });
        $unknown = array_diff(array_keys($req), $allowed);
        if ($unknown !== []) {
            throw new ConfigException('INVALID_ACCESS', 'Unknown field(s) for type ' . $type . ': ' . implode(', ', $unknown));
        }
        $label = $req['label'] ?? null;
        if ($label !== null && (! is_string($label) || mb_strlen(trim($label)) > 100)) {
            throw new ConfigException('INVALID_ACCESS', 'label must be a string of at most 100 characters');
        }

        return Transaction::run($this->db, function () use ($projectId, $profileName, $type, $req, $label, $ctx): array {
            $project = $this->domains->sendProject($projectId);
            $profile = $this->profiles->get($projectId, $profileName);
            if ($this->db->table('send_accesses')->where('profile_id', $profile['id'])->countAllResults() >= self::MAX_ACCESSES) {
                throw new ConfigException('TOO_MANY_ACCESSES', 'At most ' . self::MAX_ACCESSES . ' accesses per profile');
            }
            $label = trim((string) $label);
            $row   = ['type' => $type];
            $extra = [];
            switch ($type) {
                case 'api':
                    $exp = $req['expires_in'] ?? null;
                    if ($exp !== null && (! is_int($exp) || $exp < 60)) {
                        throw new ConfigException('INVALID_ACCESS', 'expires_in must be seconds (60 or more) or null');
                    }
                    $label = $label !== '' ? $label : 'send ' . $profile['name'];
                    $t     = $this->tokens->createSendToken($project, $label, $exp, $ctx);
                    $row['api_token_id'] = $t['token']['id'];
                    $extra['bearer']     = $t['bearer'];
                    break;
                case 'smtp':
                    if (! is_string($req['username'] ?? null) || (isset($req['password']) && ! is_string($req['password']))
                        || (isset($req['plaintext_port25']) && ! is_bool($req['plaintext_port25'])) || (isset($req['allowed_networks']) && ! is_array($req['allowed_networks']))) {
                        throw new ConfigException('INVALID_ACCESS', 'smtp needs {"username": "...", "password"?: "16-128 chars", "plaintext_port25"?: bool, "allowed_networks"?: ["cidr", ...]}');
                    }
                    $c = $this->credentials->createSend($project, $req['username'], $label, $req['password'] ?? null,
                        ! ($req['plaintext_port25'] ?? false), $req['allowed_networks'] ?? null, $ctx);
                    $label = $label !== '' ? $label : $c['credential']['username'];
                    $row['smtp_credential_id'] = $c['credential']['id'];
                    if (! isset($req['password'])) {
                        $extra['password'] = $c['password']; // a password set by the application is never echoed
                    }
                    break;
                case 'ip':
                    $nets            = $this->checkNetworks($req['networks'] ?? null, null);
                    $label           = $label !== '' ? $label : implode(', ', $nets);
                    $row['networks'] = json_encode($nets);
                    break;
            }
            $accessId = RandomId::publicId(self::ID_PREFIX);
            $now      = Time::now();
            $this->db->table('send_accesses')->insert($row + [
                'access_id' => $accessId, 'project_id' => $projectId, 'profile_id' => $profile['id'], 'label' => mb_substr($label, 0, 100),
                'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->journal->append('send.access.created', $ctx, (int) $project['customer_id'], $projectId, [
                'access_id' => $accessId, 'profile' => $profile['name'], 'type' => $type,
            ]);
            ProjectService::requestApply(); // mail path (credentials.json, send.json, send_clients.cidr)

            return ['access' => $this->get($projectId, $accessId)] + $extra;
        });
    }

    /**
     * Changes label, enabled; ip: networks; smtp: plaintext_port25, allowed_networks.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public function update(int $projectId, string $accessId, array $changes, ChangeContext $ctx): array
    {
        return Transaction::run($this->db, function () use ($projectId, $accessId, $changes, $ctx): array {
            $project = $this->domains->sendProject($projectId);
            $a       = $this->get($projectId, $accessId);
            $allowed = array_merge(['label', 'enabled'], match ($a['type']) { 'smtp' => ['plaintext_port25', 'allowed_networks'], 'ip' => ['networks'], default => [] });
            $unknown = array_diff(array_keys($changes), $allowed);
            if ($unknown !== [] || $changes === []) {
                throw new ConfigException('INVALID_ACCESS', 'Changeable for type ' . $a['type'] . ': ' . implode(', ', $allowed));
            }
            $row  = [];
            $cred = [];
            foreach ($changes as $k => $v) {
                $ok = match ($k) {
                    'label'            => is_string($v) && trim($v) !== '' && mb_strlen(trim($v)) <= 100,
                    'enabled', 'plaintext_port25' => is_bool($v),
                    'allowed_networks' => $v === null || is_array($v),
                    default            => true,
                };
                if (! $ok) {
                    throw new ConfigException('INVALID_ACCESS', $k . ' has the wrong type');
                }
                switch ($k) {
                    case 'label':            $row['label'] = trim($v); break;
                    case 'enabled':          $row['enabled'] = $v ? 1 : 0; break;
                    case 'networks':         $row['networks'] = json_encode($this->checkNetworks($v, (int) $a['id'])); break;
                    case 'plaintext_port25': $cred['tls_required'] = ! $v; break;
                    case 'allowed_networks': $cred['allowed_networks'] = $v; break;
                }
            }
            if ($a['type'] === 'smtp' && isset($row['enabled'])) {
                $cred['enabled'] = (bool) $row['enabled'];
            }
            if ($cred !== [] && $a['credential_id'] !== null) {
                $this->credentials->update($a['credential_id'], $cred, $ctx);
            }
            if ($row !== []) {
                $this->db->table('send_accesses')->where('id', $a['id'])->update($row + ['updated_at' => Time::now()]);
            }
            $this->journal->append('send.access.updated', $ctx, (int) $project['customer_id'], $projectId, [
                'access_id' => $accessId, 'profile' => $a['profile_name'], 'changed' => array_keys($changes),
            ]);
            ProjectService::requestApply(); // mail path (credentials.json, send.json, send_clients.cidr)

            return $this->get($projectId, $accessId);
        });
    }

    /**
     * New secret: api a new token (the old one stays valid for $grace seconds), smtp a new password.
     *
     * @return array{access: array<string, mixed>, bearer?: string, password?: string}
     */
    public function rotate(int $projectId, string $accessId, int $grace, ?string $password, ChangeContext $ctx): array
    {
        if ($grace < 0 || $grace > self::MAX_GRACE) {
            throw new ConfigException('INVALID_ACCESS', 'grace_seconds must be 0-' . self::MAX_GRACE);
        }

        return Transaction::run($this->db, function () use ($projectId, $accessId, $grace, $password, $ctx): array {
            $project = $this->domains->sendProject($projectId);
            $a       = $this->get($projectId, $accessId);
            $extra   = [];
            if ($a['type'] === 'api') {
                if ($password !== null) {
                    throw new ConfigException('INVALID_ACCESS', 'password only applies to smtp accesses');
                }
                $prev = $a['api_token_prev_id'];
                if ($prev !== null) { // a second rotation ends the previous grace period
                    $this->db->table('api_tokens')->where('id', $prev)->where('revoked_at', null)->update(['revoked_at' => Time::now()]);
                }
                $r = $this->tokens->rotate($a['token_id'], $grace, $ctx, true);
                $this->db->table('send_accesses')->where('id', $a['id'])->update([
                    'api_token_id' => $r['token']['id'], 'api_token_prev_id' => $grace > 0 ? $a['api_token_id'] : null, 'updated_at' => Time::now(),
                ]);
                $extra['bearer'] = $r['bearer'];
            } elseif ($a['type'] === 'smtp') {
                $r = $this->credentials->rotate($a['credential_id'], $grace, $password, $ctx);
                if ($password === null) {
                    $extra['password'] = $r['password'];
                }
            } else {
                throw new ConfigException('INVALID_ACCESS', 'An ip access has no secret to rotate');
            }
            $this->journal->append('send.access.rotated', $ctx, (int) $project['customer_id'], $projectId, [
                'access_id' => $accessId, 'profile' => $a['profile_name'], 'type' => $a['type'], 'grace_seconds' => $grace,
            ]);
            ProjectService::requestApply(); // mail path (credentials.json, send.json, send_clients.cidr)

            return ['access' => $this->get($projectId, $accessId)] + $extra;
        });
    }

    public function revoke(int $projectId, string $accessId, ChangeContext $ctx): void
    {
        Transaction::run($this->db, function () use ($projectId, $accessId, $ctx): void {
            $project = $this->domains->sendProject($projectId);
            $a       = $this->get($projectId, $accessId);
            $this->revokeRow($a);
            $this->journal->append('send.access.revoked', $ctx, (int) $project['customer_id'], $projectId, [
                'access_id' => $accessId, 'profile' => $a['profile_name'], 'type' => $a['type'],
            ]);
            ProjectService::requestApply(); // mail path (credentials.json, send.json, send_clients.cidr)
        });
    }

    /**
     * Revokes every access of a profile (profile deletion). Call inside the caller's transaction.
     *
     * @param array<string, mixed> $profile
     */
    public function revokeAllOfProfile(array $profile, ChangeContext $ctx): int
    {
        $rows = $this->list((int) $profile['id']);
        foreach ($rows as $a) {
            $this->revokeRow($a);
        }

        return count($rows);
    }

    /**
     * API view: never a secret or hash.
     *
     * @param array<string, mixed> $a row from query()
     *
     * @return array<string, mixed>
     */
    public static function present(array $a): array
    {
        $out = [
            'access_id' => $a['access_id'], 'profile' => $a['profile_name'], 'type' => $a['type'], 'label' => $a['label'], 'enabled' => (bool) $a['enabled'],
            'created_at' => Time::toIso($a['created_at']), 'updated_at' => Time::toIso($a['updated_at']),
        ];

        return $out + match ($a['type']) {
            'api' => ['token_id' => $a['token_id'], 'expires_at' => Time::toIso($a['token_expires_at']), 'last_used_at' => Time::toIso($a['token_last_used_at']),
                'previous_token_valid_until' => Time::toIso($a['prev_token_expires_at'])],
            'smtp' => ['username' => $a['username'], 'plaintext_port25' => ! (bool) $a['tls_required'],
                'allowed_networks' => $a['allowed_networks'] !== null ? json_decode($a['allowed_networks'], true) : null,
                'last_used_at' => Time::toIso($a['credential_last_used_at']), 'previous_password_valid_until' => Time::toIso($a['previous_valid_until'])],
            'ip' => ['networks' => json_decode((string) $a['networks'], true)],
        };
    }

    /**
     * Validates client networks of an ip access: single addresses or networks up to /29 (IPv4) and /64 (IPv6), only
     * public unicast space, no overlap with another ip access on the node.
     *
     * @return list<string>
     */
    public function checkNetworks(mixed $networks, ?int $exceptAccessRowId): array
    {
        if (! is_array($networks) || $networks === [] || ! array_is_list($networks) || count($networks) > self::MAX_NETWORKS) {
            throw new ConfigException('INVALID_NETWORK', 'networks must be a list of 1-' . self::MAX_NETWORKS . ' addresses or networks');
        }
        $out = [];
        foreach ($networks as $n) {
            $out[] = self::normalizeNetwork($n);
        }
        $out = array_values(array_unique($out));
        foreach ($out as $i => $a) {
            foreach (array_slice($out, $i + 1) as $b) {
                if (self::overlap($a, $b)) {
                    throw new ConfigException('INVALID_NETWORK', $a . ' and ' . $b . ' overlap');
                }
            }
        }
        $q = $this->db->table('send_accesses')->select('access_id, networks')->where('type', 'ip');
        if ($exceptAccessRowId !== null) {
            $q->where('id !=', $exceptAccessRowId);
        }
        foreach ($q->get()->getResultArray() as $other) {
            foreach (json_decode((string) $other['networks'], true) ?: [] as $b) {
                foreach ($out as $a) {
                    if (self::overlap($a, $b)) {
                        throw new ConfigException('NETWORK_IN_USE', $a . ' overlaps a network of another ip access on this node');
                    }
                }
            }
        }

        return $out;
    }

    public static function normalizeNetwork(mixed $cidr): string
    {
        $cidr = is_string($cidr) ? trim($cidr) : '';
        [$ip, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        $bin = filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;
        if ($bin === false) {
            throw new ConfigException('INVALID_NETWORK', 'Invalid address "' . $cidr . '"');
        }
        $family = strlen($bin) === 4 ? 4 : 6;
        $max    = $family === 4 ? 32 : 128;
        if ($prefix !== null && (! ctype_digit($prefix) || (int) $prefix > $max)) {
            throw new ConfigException('INVALID_NETWORK', 'Invalid network "' . $cidr . '"');
        }
        $len = $prefix === null ? $max : (int) $prefix;
        if ($len < self::MIN_PREFIX[$family]) {
            throw new ConfigException('INVALID_NETWORK', '"' . $cidr . '" is too large: IPv4 /' . self::MIN_PREFIX[4] . ' and IPv6 /' . self::MIN_PREFIX[6] . ' at most');
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false || self::isSpecial($bin)) {
            throw new ConfigException('INVALID_NETWORK', '"' . $cidr . '" is not a unicast address');
        }
        $net = self::mask($bin, $len);
        if ($net !== $bin && $prefix !== null) {
            throw new ConfigException('INVALID_NETWORK', '"' . $cidr . '" has host bits set (network is ' . inet_ntop($net) . '/' . $len . ')');
        }

        return inet_ntop($net) . '/' . $len;
    }

    /** Whether an address belongs to a network "addr/len". */
    public static function contains(string $network, string $ip): bool
    {
        [$n, $len] = explode('/', $network);
        $a = @inet_pton($ip);
        $b = inet_pton($n);

        return $a !== false && strlen($a) === strlen($b) && self::mask($a, (int) $len) === $b;
    }

    private static function overlap(string $a, string $b): bool
    {
        [$na, $la] = explode('/', $a);
        [$nb, $lb] = explode('/', $b);
        $ba = inet_pton($na);
        $bb = inet_pton($nb);
        if (strlen($ba) !== strlen($bb)) {
            return false;
        }
        $len = min((int) $la, (int) $lb);

        return self::mask($ba, $len) === self::mask($bb, $len);
    }

    private static function mask(string $bin, int $len): string
    {
        $out = '';
        foreach (str_split($bin) as $i => $byte) {
            $bits = max(0, min(8, $len - $i * 8));
            $out .= chr(ord($byte) & (0xFF << (8 - $bits)) & 0xFF);
        }

        return $out;
    }

    /** Loopback, unspecified, multicast and link-local (FILTER_FLAG_NO_RES_RANGE misses some IPv6 cases). */
    private static function isSpecial(string $bin): bool
    {
        if (strlen($bin) === 4) {
            $first = ord($bin[0]);

            return $first === 0 || $first === 127 || $first >= 224 || ($first === 169 && ord($bin[1]) === 254);
        }

        return $bin === str_repeat("\0", 16) || $bin === str_repeat("\0", 15) . "\1" || ord($bin[0]) === 0xFF
            || (ord($bin[0]) === 0xFE && (ord($bin[1]) & 0xC0) === 0x80);
    }

    /** @param array<string, mixed> $a row from query() */
    public function revokeRow(array $a): void
    {
        $now = Time::now();
        $ids = array_values(array_filter([$a['api_token_id'], $a['api_token_prev_id']]));
        if ($ids !== []) {
            $this->db->table('api_tokens')->whereIn('id', $ids)->where('revoked_at', null)->update(['revoked_at' => $now]);
        }
        if ($a['smtp_credential_id'] !== null) {
            $this->db->table('smtp_credentials')->where('id', $a['smtp_credential_id'])->where('revoked_at', null)->update(['revoked_at' => $now, 'enabled' => 0, 'updated_at' => $now]);
        }
        $this->db->table('send_accesses')->where('id', $a['id'])->delete();
    }

    private function query(): \CodeIgniter\Database\BaseBuilder
    {
        return $this->db->table('send_accesses a')
            ->select('a.*, p.name AS profile_name, t.token_id, t.expires_at AS token_expires_at, t.last_used_at AS token_last_used_at,
                pt.expires_at AS prev_token_expires_at, c.credential_id, c.username, c.tls_required, c.allowed_networks, c.last_used_at AS credential_last_used_at,
                c.previous_valid_until', false)
            ->join('send_profiles p', 'p.id = a.profile_id')
            ->join('api_tokens t', 't.id = a.api_token_id', 'left')
            ->join('api_tokens pt', 'pt.id = a.api_token_prev_id', 'left')
            ->join('smtp_credentials c', 'c.id = a.smtp_credential_id', 'left');
    }
}
