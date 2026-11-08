<?php

namespace App\Core\Send;

use App\Core\Journal\JournalWriter;
use App\Core\Projects\ProjectService;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;
use App\Core\Support\RandomId;
use App\Core\Support\Time;
use App\Core\Support\Transaction;
use CodeIgniter\Database\BaseConnection;

/**
 * Send profiles of a send project (decision E-27): the rules every access of the profile sends under. Allowed
 * senders and limits are mandatory and checked against the project settings from MailRunner Admin; a project
 * without limits cannot have profiles (fail closed). The application defines a profile completely with one
 * idempotent PUT.
 */
class SendProfileService
{
    public const ID_PREFIX       = 'mrp';
    public const MAX_PROFILES    = 100;
    public const MAX_LIST        = 100;
    public const RETRY_DEFAULT   = ['attempts' => 16, 'max_minutes' => 480];
    public const RETRY_ATTEMPTS  = [5, 32];
    public const RETRY_MINUTES   = [10, 2880];
    public const TEST_INTERVAL   = [1, 168];
    public const TEST_DEFAULT    = 24;
    public const FIRST_RETRY_SEC = 60;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly JournalWriter $journal,
        private readonly SendDomainService $domains,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(int $projectId): array
    {
        return $this->db->table('send_profiles')->where('project_id', $projectId)->orderBy('name')->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function find(int $projectId, string $name): ?array
    {
        return $this->db->table('send_profiles')->where('project_id', $projectId)->where('name', strtolower(trim($name)))->get()->getRowArray();
    }

    /** @return array<string, mixed> */
    public function get(int $projectId, string $name): array
    {
        return $this->find($projectId, $name) ?? throw new ConfigException('PROFILE_NOT_FOUND', 'Send profile "' . $name . '" not found');
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->table('send_profiles')->where('id', $id)->get()->getRowArray();
    }

    /**
     * Creates or replaces a profile (idempotent: the same definition changes nothing).
     *
     * @param array<string, mixed> $def {label?, enabled?, senders, recipients?, limit_hour, limit_day, retry?, return_path_domain?, test_interval_hours?}
     *
     * @return array{0: array<string, mixed>, 1: bool, 2: bool} [row, changed, created]
     */
    public function put(int $projectId, string $name, array $def, ChangeContext $ctx): array
    {
        $name = strtolower(trim($name));
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $name) !== 1) {
            throw new ConfigException('INVALID_PROFILE_NAME', 'Profile name: 1-63 characters a-z, 0-9 and "-", not starting with "-"');
        }
        $unknown = array_diff(array_keys($def), ['label', 'enabled', 'senders', 'recipients', 'limit_hour', 'limit_day', 'retry', 'return_path_domain', 'test_interval_hours']);
        if ($unknown !== []) {
            throw new ConfigException('INVALID_PROFILE', 'Unknown field(s): ' . implode(', ', $unknown));
        }
        $retry = self::normalizeRetry($def['retry'] ?? 'default');
        $want  = [
            'label'               => self::label($def['label'] ?? $name),
            'enabled'             => self::bool($def['enabled'] ?? true, 'enabled') ? 1 : 0,
            'senders'             => self::normalizeAddressList($def['senders'] ?? null, 'senders', false),
            'recipients'          => self::normalizeAddressList($def['recipients'] ?? null, 'recipients', true),
            'limit_hour'          => self::int($def['limit_hour'] ?? null, 'limit_hour', 1, ProjectService::MAX_SEND_LIMIT),
            'limit_day'           => self::int($def['limit_day'] ?? null, 'limit_day', 1, ProjectService::MAX_SEND_LIMIT),
            'retry_attempts'      => $retry['attempts'],
            'retry_seconds'       => $retry['max_minutes'] * 60,
            'return_path_domain'  => self::optionalDomain($def['return_path_domain'] ?? null),
            'test_interval_hours' => self::int($def['test_interval_hours'] ?? self::TEST_DEFAULT, 'test_interval_hours', ...self::TEST_INTERVAL),
        ];
        if ($want['limit_hour'] > $want['limit_day']) {
            throw new ConfigException('INVALID_PROFILE', 'limit_hour must not be greater than limit_day');
        }

        return Transaction::run($this->db, function () use ($projectId, $name, $want, $ctx): array {
            $project = $this->domains->sendProject($projectId);
            $this->assertWithinProject($project, $want);
            $old = $this->find($projectId, $name);
            $row = ['senders' => json_encode($want['senders']), 'recipients' => $want['recipients'] === null ? null : json_encode($want['recipients'])] + $want;
            if ($old !== null && self::same($old, $row)) {
                return [$old, false, false];
            }
            if ($old === null && $this->db->table('send_profiles')->where('project_id', $projectId)->countAllResults() >= self::MAX_PROFILES) {
                throw new ConfigException('TOO_MANY_PROFILES', 'At most ' . self::MAX_PROFILES . ' send profiles per project');
            }
            $now = Time::now();
            if ($old === null) {
                $this->db->table('send_profiles')->insert($row + ['profile_id' => RandomId::publicId(self::ID_PREFIX), 'project_id' => $projectId, 'name' => $name,
                    'created_at' => $now, 'updated_at' => $now, 'updated_by' => $ctx->actor]);
            } else {
                $this->db->table('send_profiles')->where('id', $old['id'])->update($row + ['updated_at' => $now, 'updated_by' => $ctx->actor]);
            }
            $new = $this->get($projectId, $name);
            $this->journal->append('send.profile.updated', $ctx, (int) $project['customer_id'], $projectId, [
                'profile_id' => $new['profile_id'], 'name' => $name, 'created' => $old === null,
                'changed' => $old === null ? null : array_keys(array_diff_assoc(array_map('strval', array_intersect_key($row, $old)), array_map('strval', array_intersect_key($old, $row)))),
            ]);

            return [$new, true, $old === null];
        });
    }

    /** Deletes a profile; its accesses are revoked (tokens and logins stop working at once). */
    public function delete(int $projectId, string $name, ChangeContext $ctx, SendAccessService $accesses): bool
    {
        return Transaction::run($this->db, function () use ($projectId, $name, $ctx, $accesses): bool {
            $project = $this->domains->sendProject($projectId);
            $old     = $this->find($projectId, $name);
            if ($old === null) {
                return false;
            }
            $revoked = $accesses->revokeAllOfProfile($old, $ctx);
            $this->db->table('send_profiles')->where('id', $old['id'])->delete();
            $this->journal->append('send.profile.deleted', $ctx, (int) $project['customer_id'], $projectId, [
                'profile_id' => $old['profile_id'], 'name' => $old['name'], 'accesses_revoked' => $revoked,
            ]);

            return true;
        });
    }

    /**
     * API view.
     *
     * @param array<string, mixed>      $row
     * @param array<string, mixed>|null $project for the "allowed" flags of the senders and the effective limits
     * @param array<string, int>|null   $accessCounts type => count
     *
     * @return array<string, mixed>
     */
    public static function present(array $row, ?array $project = null, ?array $accessCounts = null): array
    {
        $senders = json_decode($row['senders'], true);
        $out     = [
            'profile_id' => $row['profile_id'], 'name' => $row['name'], 'label' => $row['label'], 'enabled' => (bool) $row['enabled'],
            'senders' => $senders,
            'recipients' => $row['recipients'] === null ? null : json_decode($row['recipients'], true),
            'limit_hour' => (int) $row['limit_hour'], 'limit_day' => (int) $row['limit_day'],
            'retry' => ['attempts' => (int) $row['retry_attempts'], 'max_minutes' => intdiv((int) $row['retry_seconds'], 60),
                'schedule_minutes' => array_map(static fn ($s) => round($s / 60, 1), self::retrySchedule((int) $row['retry_attempts'], (int) $row['retry_seconds']))],
            'return_path_domain' => $row['return_path_domain'],
            'test_interval_hours' => (int) $row['test_interval_hours'],
            'created_at' => Time::toIso($row['created_at']), 'updated_at' => Time::toIso($row['updated_at']),
        ];
        if ($project !== null) {
            $out['senders_not_allowed'] = array_values(array_filter($senders, static fn ($s) => ! ProjectService::sendDomainAllowed($project, self::domainOf($s))));
            $out['effective_limit_hour'] = min((int) $row['limit_hour'], (int) ($project['send_limit_hour'] ?? 0));
            $out['effective_limit_day']  = min((int) $row['limit_day'], (int) ($project['send_limit_day'] ?? 0));
        }
        if ($accessCounts !== null) {
            $out['accesses'] = $accessCounts + ['api' => 0, 'smtp' => 0, 'ip' => 0];
        }

        return $out;
    }

    /**
     * Seconds after the first attempt at which the retries happen: the first after one minute, then growing by a
     * fixed factor so that the last attempt is at the give-up time (E-27).
     *
     * @return list<int>
     */
    public static function retrySchedule(int $attempts, int $seconds): array
    {
        $gaps = $attempts - 1;
        $sum  = static fn (float $f): float => abs($f - 1.0) < 1e-9 ? self::FIRST_RETRY_SEC * $gaps : self::FIRST_RETRY_SEC * ($f ** $gaps - 1) / ($f - 1);
        [$lo, $hi] = [1.0, 10.0];
        for ($i = 0; $i < 100; $i++) {
            $mid = ($lo + $hi) / 2;
            $sum($mid) < $seconds ? $lo = $mid : $hi = $mid;
        }
        $out = [];
        $t   = 0.0;
        for ($k = 0; $k < $gaps; $k++) {
            $t    += self::FIRST_RETRY_SEC * $lo ** $k;
            $out[] = $k === $gaps - 1 ? $seconds : (int) round($t);
        }

        return $out;
    }

    /** The domain part of a sender/recipient entry (a domain or an address). */
    public static function domainOf(string $entry): string
    {
        $at = strrpos($entry, '@');

        return $at === false ? $entry : substr($entry, $at + 1);
    }

    /** @param array<string, mixed> $project @param array<string, mixed> $want */
    private function assertWithinProject(array $project, array $want): void
    {
        foreach (['hour', 'day'] as $p) {
            $limit = $project['send_limit_' . $p];
            if ($limit === null || (int) $limit === 0) {
                throw new ConfigException('PROJECT_LIMIT_MISSING', 'The project has no send limit per ' . $p . ' yet (set in MailRunner Admin)');
            }
            if ($want['limit_' . $p] > (int) $limit) {
                throw new ConfigException('LIMIT_EXCEEDS_PROJECT', 'limit_' . $p . ' ' . $want['limit_' . $p] . ' is above the project limit of ' . $limit);
            }
        }
        $bad = array_values(array_filter($want['senders'], static fn ($s) => ! ProjectService::sendDomainAllowed($project, self::domainOf($s))));
        if ($bad !== []) {
            throw new ConfigException('DOMAIN_NOT_ALLOWED', 'Sender(s) not allowed for this project: ' . implode(', ', $bad));
        }
    }

    /** @param array<string, mixed> $old @param array<string, mixed> $row */
    private static function same(array $old, array $row): bool
    {
        foreach ($row as $k => $v) {
            $o = $old[$k];
            if (in_array($k, ['senders', 'recipients'], true)) {
                $o = $o === null ? null : json_encode(json_decode($o, true));
            }
            if ((string) $o !== (string) $v) {
                return false;
            }
        }

        return true;
    }

    /** @return array{attempts: int, max_minutes: int} */
    public static function normalizeRetry(mixed $retry): array
    {
        if ($retry === 'default') {
            return self::RETRY_DEFAULT;
        }
        if (! is_array($retry) || array_diff(array_keys($retry), ['attempts', 'max_minutes']) !== []) {
            throw new ConfigException('INVALID_PROFILE', 'retry must be "default" or {"attempts": 5-32, "max_minutes": 10-2880}');
        }
        $a = self::int($retry['attempts'] ?? null, 'retry.attempts', ...self::RETRY_ATTEMPTS);
        $m = self::int($retry['max_minutes'] ?? null, 'retry.max_minutes', ...self::RETRY_MINUTES);
        if ($m < $a - 1) {
            throw new ConfigException('INVALID_PROFILE', 'retry: ' . $a . ' attempts need max_minutes of at least ' . ($a - 1) . ' (the first retry is after one minute)');
        }

        return ['attempts' => $a, 'max_minutes' => $m];
    }

    /** @return list<string>|null */
    public static function normalizeAddressList(mixed $list, string $field, bool $nullable): ?array
    {
        if ($list === null && $nullable) {
            return null;
        }
        if (! is_array($list) || $list === [] || ! array_is_list($list) || count($list) > self::MAX_LIST) {
            throw new ConfigException('INVALID_PROFILE', $field . ' must be a list of 1-' . self::MAX_LIST . ' domains or addresses' . ($nullable ? ' (or null)' : ''));
        }
        $out = [];
        foreach ($list as $e) {
            if (! is_string($e)) {
                throw new ConfigException('INVALID_PROFILE', $field . ': every entry must be a string');
            }
            $e = strtolower(trim($e));
            $at = strrpos($e, '@');
            if ($at === false) {
                $e = SendDomainService::normalize($e);
                $ok = ProjectService::isDomainName($e);
            } else {
                $local = substr($e, 0, $at);
                $dom   = SendDomainService::normalize(substr($e, $at + 1));
                $e     = $local . '@' . $dom;
                $ok    = $local !== '' && strlen($local) <= 64 && preg_match('/^[a-z0-9!#$%&\'*+\/=?^_`{|}~.-]+$/', $local) === 1 && ProjectService::isDomainName($dom);
            }
            if (! $ok) {
                throw new ConfigException('INVALID_PROFILE', $field . ': "' . $e . '" is neither a domain nor an address');
            }
            $out[] = $e;
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    private static function optionalDomain(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $d = is_string($v) ? SendDomainService::normalize($v) : '';
        if (! ProjectService::isDomainName($d)) {
            throw new ConfigException('INVALID_PROFILE', 'return_path_domain must be a domain name or null');
        }

        return $d;
    }

    private static function int(mixed $v, string $field, int $min, int $max): int
    {
        if (! is_int($v) || $v < $min || $v > $max) {
            throw new ConfigException('INVALID_PROFILE', $field . ' must be an integer ' . $min . '-' . $max);
        }

        return $v;
    }

    private static function bool(mixed $v, string $field): bool
    {
        if (! is_bool($v)) {
            throw new ConfigException('INVALID_PROFILE', $field . ' must be true or false');
        }

        return $v;
    }

    private static function label(mixed $v): string
    {
        if (! is_string($v) || trim($v) === '' || mb_strlen(trim($v)) > 100) {
            throw new ConfigException('INVALID_PROFILE', 'label must be 1-100 characters');
        }

        return trim($v);
    }
}
