<?php

namespace App\Core\Access;

use App\Core\Journal\JournalWriter;
use App\Core\Projects\ProjectService;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;
use App\Core\Support\RandomId;
use App\Core\Support\Time;
use App\Core\Support\Transaction;
use CodeIgniter\Database\BaseConnection;

/**
 * SMTP credentials: several per project (profile checked_auth only),
 * each with a public id, a unique username and an Argon2id password hash.
 *
 * Rotation keeps the previous password valid for a grace period, so devices
 * can be switched over without an outage.
 */
class SmtpCredentialService
{
    public const ID_PREFIX = 'mrc';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly JournalWriter $journal,
        private readonly ProjectService $projects,
    ) {
    }

    /**
     * @param list<string>|null $allowedNetworks CIDR list, null = any network
     *
     * @return array{credential: array<string, mixed>, password: string}
     */
    public function create(
        string $ref,
        string $username,
        string $label,
        ?string $password,
        bool $tlsRequired,
        ?array $allowedNetworks,
        ChangeContext $ctx,
    ): array {
        $username = self::normalizeUsername($username);
        $label    = trim($label) !== '' ? trim($label) : $username;
        $networks = self::normalizeNetworks($allowedNetworks);
        $password ??= RandomId::password();
        self::assertPasswordStrength($password);

        return Transaction::run($this->db, function () use ($ref, $username, $label, $password, $tlsRequired, $networks, $ctx): array {
            $project = $this->projects->getForChange($ref, $ctx);
            if ($project['profile'] !== 'checked_auth') {
                throw new ConfigException('PROFILE_NO_SMTP_AUTH', 'Project "' . $ref . '" has profile "' . $project['profile'] . '"; SMTP credentials need profile checked_auth');
            }
            if ($this->db->table('smtp_credentials')->where('username', $username)->countAllResults() > 0) {
                throw new ConfigException('USERNAME_EXISTS', 'SMTP username "' . $username . '" already exists on this node');
            }
            $credentialId = RandomId::publicId(self::ID_PREFIX);
            $now          = Time::now();
            $this->db->table('smtp_credentials')->insert([
                'credential_id'    => $credentialId,
                'project_id'       => $project['id'],
                'username'         => $username,
                'label'            => mb_substr($label, 0, 100),
                'password_hash'    => password_hash($password, PASSWORD_ARGON2ID),
                'tls_required'     => $tlsRequired ? 1 : 0,
                'allowed_networks' => $networks === null ? null : json_encode($networks),
                'enabled'          => 1,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
            $revision = $this->projects->bumpRevision((int) $project['id']);
            $this->journal->append('credential.created', $ctx, (int) $project['customer_id'], (int) $project['id'], [
                'credential_id' => $credentialId, 'username' => $username, 'tls_required' => $tlsRequired, 'revision' => $revision,
            ]);

            return ['credential' => $this->get($credentialId), 'password' => $password];
        });
    }

    /**
     * Sets a new password; the previous one stays valid for $graceSeconds (0 = invalid at once).
     *
     * @return array{credential: array<string, mixed>, password: string}
     */
    public function rotate(string $credentialIdOrUsername, int $graceSeconds, ?string $password, ChangeContext $ctx): array
    {
        $password ??= RandomId::password();
        self::assertPasswordStrength($password);

        return Transaction::run($this->db, function () use ($credentialIdOrUsername, $graceSeconds, $password, $ctx): array {
            $cred    = $this->getActive($credentialIdOrUsername);
            $project = $this->projects->getForChange(ProjectService::refOf($this->projects->getById((int) $cred['project_id'])), $ctx);
            $this->db->table('smtp_credentials')->where('id', $cred['id'])->update([
                'password_hash'            => password_hash($password, PASSWORD_ARGON2ID),
                'previous_password_hash'   => $graceSeconds > 0 ? $cred['password_hash'] : null,
                'previous_valid_until'     => $graceSeconds > 0 ? Time::inSeconds($graceSeconds) : null,
                'password_change_required' => 0,
                'updated_at'               => Time::now(),
            ]);
            $revision = $this->projects->bumpRevision((int) $project['id']);
            $this->journal->append('credential.rotated', $ctx, (int) $project['customer_id'], (int) $project['id'], [
                'credential_id' => $cred['credential_id'], 'grace_seconds' => $graceSeconds, 'revision' => $revision,
            ]);

            return ['credential' => $this->get($cred['credential_id']), 'password' => $password];
        });
    }

    /**
     * Changes label, enabled, tls_required or allowed_networks.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public function update(string $credentialIdOrUsername, array $changes, ChangeContext $ctx): array
    {
        $row = [];
        foreach ($changes as $field => $value) {
            $row[$field] = match ($field) {
                'label'            => mb_substr(trim((string) $value), 0, 100),
                'enabled'          => $value ? 1 : 0,
                'tls_required'     => $value ? 1 : 0,
                'allowed_networks' => ($n = self::normalizeNetworks($value)) === null ? null : json_encode($n),
                default            => throw new ConfigException('INVALID_SETTING', 'Unknown credential setting "' . $field . '"'),
            };
        }

        return Transaction::run($this->db, function () use ($credentialIdOrUsername, $row, $ctx): array {
            $cred    = $this->getActive($credentialIdOrUsername);
            $project = $this->projects->getForChange(ProjectService::refOf($this->projects->getById((int) $cred['project_id'])), $ctx);
            if ($row !== []) {
                $this->db->table('smtp_credentials')->where('id', $cred['id'])->update($row + ['updated_at' => Time::now()]);
            }
            $revision = $this->projects->bumpRevision((int) $project['id']);
            $this->journal->append('credential.updated', $ctx, (int) $project['customer_id'], (int) $project['id'], [
                'credential_id' => $cred['credential_id'], 'changed' => array_keys($row), 'revision' => $revision,
            ]);

            return $this->get($cred['credential_id']);
        });
    }

    public function revoke(string $credentialIdOrUsername, ChangeContext $ctx): void
    {
        Transaction::run($this->db, function () use ($credentialIdOrUsername, $ctx): void {
            $cred    = $this->getActive($credentialIdOrUsername);
            $project = $this->projects->getForChange(ProjectService::refOf($this->projects->getById((int) $cred['project_id'])), $ctx);
            $this->db->table('smtp_credentials')->where('id', $cred['id'])->update([
                'revoked_at' => Time::now(), 'enabled' => 0, 'updated_at' => Time::now(),
            ]);
            $revision = $this->projects->bumpRevision((int) $project['id']);
            $this->journal->append('credential.revoked', $ctx, (int) $project['customer_id'], (int) $project['id'], [
                'credential_id' => $cred['credential_id'], 'revision' => $revision,
            ]);
        });
    }

    /**
     * Password check as the mail path will do it (current, or previous within grace).
     */
    public function verifyPassword(string $username, string $password): bool
    {
        $cred = $this->db->table('smtp_credentials')->where('username', strtolower($username))->get()->getRowArray();
        if ($cred === null || $cred['revoked_at'] !== null || ! (bool) $cred['enabled']) {
            return false;
        }
        if (password_verify($password, $cred['password_hash'])) {
            return true;
        }

        return $cred['previous_password_hash'] !== null
            && $cred['previous_valid_until'] !== null
            && $cred['previous_valid_until'] > Time::now()
            && password_verify($password, $cred['previous_password_hash']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $ref): array
    {
        $project = $this->projects->get($ref);

        return $this->db->table('smtp_credentials')
            ->select('credential_id, username, label, tls_required, allowed_networks, enabled, password_change_required, previous_valid_until, created_at, revoked_at, last_used_at')
            ->where('project_id', $project['id'])->orderBy('id')->get()->getResultArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $credentialIdOrUsername): array
    {
        $field = str_starts_with($credentialIdOrUsername, self::ID_PREFIX . '_') ? 'credential_id' : 'username';

        return $this->db->table('smtp_credentials')->where($field, strtolower($credentialIdOrUsername))->get()->getRowArray()
            ?? throw new ConfigException('CREDENTIAL_NOT_FOUND', 'SMTP credential "' . $credentialIdOrUsername . '" not found');
    }

    /**
     * @return array<string, mixed>
     */
    private function getActive(string $credentialIdOrUsername): array
    {
        $cred = $this->get($credentialIdOrUsername);
        if ($cred['revoked_at'] !== null) {
            throw new ConfigException('CREDENTIAL_REVOKED', 'SMTP credential "' . $credentialIdOrUsername . '" is revoked');
        }

        return $cred;
    }

    private static function normalizeUsername(string $username): string
    {
        $username = strtolower(trim($username));
        if (preg_match('/^[a-z0-9][a-z0-9._@+-]{2,99}$/', $username) !== 1) {
            throw new ConfigException('INVALID_USERNAME', 'Username must be 3-100 chars: a-z, 0-9, . _ @ + -');
        }

        return $username;
    }

    private static function assertPasswordStrength(string $password): void
    {
        if (strlen($password) < 16 || strlen($password) > 128) {
            throw new ConfigException('WEAK_PASSWORD', 'Password must be 16-128 characters');
        }
    }

    /**
     * @return list<string>|null
     */
    public static function normalizeNetworks(mixed $networks): ?array
    {
        if ($networks === null || $networks === []) {
            return null;
        }
        $result = [];
        foreach ((array) $networks as $cidr) {
            $cidr = trim((string) $cidr);
            if ($cidr === '') {
                continue;
            }
            [$ip, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
            $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $max  = $isV6 ? 128 : 32;
            if ((! $isV4 && ! $isV6) || ($prefix !== null && (! ctype_digit($prefix) || (int) $prefix > $max))) {
                throw new ConfigException('INVALID_NETWORK', 'Invalid network "' . $cidr . '"');
            }
            $result[] = inet_ntop(inet_pton($ip)) . '/' . ($prefix ?? $max);
        }

        return $result === [] ? null : array_values(array_unique($result));
    }
}
