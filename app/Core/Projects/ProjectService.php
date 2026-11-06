<?php

namespace App\Core\Projects;

use App\Core\Journal\JournalWriter;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;
use App\Core\Support\Time;
use App\Core\Support\Transaction;
use CodeIgniter\Database\BaseConnection;

/**
 * Projects: profile, settings, revision and the managed_by guard.
 */
class ProjectService
{
    /** Receive profiles (E-05); a send project always has profile smarthost (E-27). */
    public const PROFILES = ['analysis', 'checked', 'checked_auth'];

    /** Project type, fixed at creation (E-27): a project either receives or sends, never both. */
    public const TYPES = ['receive', 'send'];

    public const SEND_PROFILE = 'smarthost';

    /** Domain test criteria a send project can require (admin checkboxes) and their defaults. */
    public const TEST_CRITERIA = ['spf' => true, 'dkim' => true, 'dmarc' => true, 'dmarc_policy' => false, 'blacklist' => false];

    public const MAX_SEND_LIMIT = 10_000_000;

    /** Settings that only exist for send projects (set by MailRunner Admin). */
    public const SEND_SETTINGS = ['send_limit_hour', 'send_limit_day', 'send_allowed_domains', 'send_test_criteria'];

    /** Settings that only exist for receive projects. */
    public const RECEIVE_SETTINGS = ['recipient_pattern', 'recipient_always_allow', 'delete_after_ack_hours', 'unacked_days',
        'journal_scope', 'role_mail', 'role_mail_forward_to'];

    /**
     * Journal entries a project's token sees besides its own:
     * own = none, connections = SMTP connections without MAIL FROM (IP, host name, EHLO, TLS),
     * unassigned = all SMTP entries without a project (incl. sender/recipients of other domains).
     */
    public const JOURNAL_SCOPES = ['own', 'connections', 'unassigned'];

    /**
     * Delivery of mail to postmaster@/abuse@ (E-18): default = forwarded to the node operator,
     * api = the customer collects it, forward = forwarded to role_mail_forward_to (needs the send role).
     */
    public const ROLE_MAIL = ['default', 'api', 'forward'];

    /** Settings that may be changed with update(). */
    private const SETTINGS = [
        'name', 'enabled', 'recipient_pattern', 'recipient_always_allow',
        'delete_after_ack_hours', 'unacked_days', 'metadata_days', 'journal_scope',
        'role_mail', 'role_mail_forward_to',
        'send_limit_hour', 'send_limit_day', 'send_allowed_domains', 'send_test_criteria',
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly JournalWriter $journal,
        private readonly CustomerService $customers,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $ref, string $profile, string $name, ChangeContext $ctx, string $type = 'receive'): array
    {
        [$customerKey, $projectKey] = Keys::splitProjectRef($ref);
        if (! in_array($type, self::TYPES, true)) {
            throw new ConfigException('INVALID_TYPE', 'Type must be one of: ' . implode(', ', self::TYPES));
        }
        if ($type === 'send') {
            if ($profile !== '' && $profile !== self::SEND_PROFILE) {
                throw new ConfigException('INVALID_PROFILE', 'A send project has profile ' . self::SEND_PROFILE);
            }
            $profile = self::SEND_PROFILE;
        } elseif (! in_array($profile, self::PROFILES, true)) {
            throw new ConfigException('INVALID_PROFILE', 'Profile must be one of: ' . implode(', ', self::PROFILES));
        }

        return Transaction::run($this->db, function () use ($ref, $customerKey, $projectKey, $profile, $name, $ctx, $type): array {
            $customer = $this->customers->getByKey($customerKey);
            if ($this->find($ref) !== null) {
                throw new ConfigException('PROJECT_EXISTS', 'Project "' . $ref . '" already exists');
            }
            $now = Time::now();
            $this->db->table('projects')->insert([
                'customer_id' => $customer['id'],
                'project_key' => $projectKey,
                'type'        => $type,
                'name'        => trim($name) !== '' ? trim($name) : $projectKey,
                'profile'     => $profile,
                'enabled'     => 1,
                'managed_by'  => $ctx->source === 'admin' ? 'admin' : 'cli',
                'revision'    => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $id = (int) $this->db->insertID();
            $this->journal->append('project.created', $ctx, (int) $customer['id'], $id, [
                'project' => $ref, 'type' => $type, 'profile' => $profile, 'revision' => 1,
            ]);

            return $this->getById($id);
        });
    }

    /**
     * Changes project settings and increments the revision.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public function update(string $ref, array $changes, ChangeContext $ctx): array
    {
        $unknown = array_diff(array_keys($changes), self::SETTINGS);
        if ($unknown !== []) {
            throw new ConfigException('INVALID_SETTING', 'Unknown setting(s): ' . implode(', ', $unknown));
        }
        if (array_key_exists('recipient_pattern', $changes)) {
            $changes['recipient_pattern'] = self::normalizeRecipientPattern($changes['recipient_pattern']);
        }
        if (array_key_exists('recipient_always_allow', $changes)) {
            $changes['recipient_always_allow'] = self::normalizeLocalParts($changes['recipient_always_allow']);
        }
        foreach (['delete_after_ack_hours', 'unacked_days', 'metadata_days'] as $field) {
            if (array_key_exists($field, $changes)) {
                $value = filter_var($changes[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 3650]]);
                if ($value === false) {
                    throw new ConfigException('INVALID_SETTING', $field . ' must be an integer 0-3650');
                }
                $changes[$field] = $value;
            }
        }
        if (array_key_exists('enabled', $changes)) {
            $changes['enabled'] = $changes['enabled'] ? 1 : 0;
        }
        if (array_key_exists('role_mail', $changes) && ! in_array($changes['role_mail'], self::ROLE_MAIL, true)) {
            throw new ConfigException('INVALID_SETTING', 'role_mail must be one of: ' . implode(', ', self::ROLE_MAIL));
        }
        if (array_key_exists('role_mail_forward_to', $changes)) {
            $to = trim((string) $changes['role_mail_forward_to']);
            if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                throw new ConfigException('INVALID_SETTING', 'role_mail_forward_to must be an e-mail address');
            }
            $changes['role_mail_forward_to'] = $to === '' ? null : strtolower($to);
        }
        if (array_key_exists('journal_scope', $changes) && ! in_array($changes['journal_scope'], self::JOURNAL_SCOPES, true)) {
            throw new ConfigException('INVALID_SETTING', 'journal_scope must be one of: ' . implode(', ', self::JOURNAL_SCOPES));
        }
        foreach (['send_limit_hour', 'send_limit_day'] as $field) {
            if (array_key_exists($field, $changes) && $changes[$field] !== null) {
                $value = filter_var($changes[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_SEND_LIMIT]]);
                if ($value === false) {
                    throw new ConfigException('INVALID_SETTING', $field . ' must be an integer 0-' . self::MAX_SEND_LIMIT . ' or null');
                }
                $changes[$field] = $value;
            }
        }
        if (array_key_exists('send_allowed_domains', $changes)) {
            $changes['send_allowed_domains'] = self::normalizeDomainList($changes['send_allowed_domains']);
        }
        if (array_key_exists('send_test_criteria', $changes)) {
            $changes['send_test_criteria'] = self::normalizeCriteria($changes['send_test_criteria']);
        }

        return Transaction::run($this->db, function () use ($ref, $changes, $ctx): array {
            $project = $this->getForChange($ref, $ctx);
            $foreign = array_intersect(array_keys($changes), $project['type'] === 'send' ? self::RECEIVE_SETTINGS : self::SEND_SETTINGS);
            if ($foreign !== []) {
                throw new ConfigException('INVALID_SETTING', 'Not a setting of a ' . $project['type'] . ' project: ' . implode(', ', $foreign));
            }
            $merged  = $changes + $project;
            if (($merged['role_mail'] ?? 'default') === 'forward' && empty($merged['role_mail_forward_to'])) {
                throw new ConfigException('INVALID_SETTING', 'role_mail forward needs role_mail_forward_to');
            }
            // Only real changes count (the admin GUI always sends the full desired state).
            $changes = array_filter($changes, static function ($value, string $field) use ($project): bool {
                $current = $project[$field] ?? null;
                if (in_array($field, ['recipient_always_allow', 'send_allowed_domains', 'send_test_criteria'], true)) {
                    $current = is_string($current) ? json_decode($current, true) : $current;

                    return $value !== $current;
                }

                return $value === null || $current === null ? $value !== $current : (string) $value !== (string) $current;
            }, ARRAY_FILTER_USE_BOTH);
            $row = $changes;
            if ($ctx->source === 'admin' && $project['managed_by'] !== 'admin') {
                $row['managed_by'] = 'admin'; // the admin GUI takes over a project created on the CLI
            }
            foreach (['recipient_always_allow', 'send_allowed_domains', 'send_test_criteria'] as $jsonField) {
                if (array_key_exists($jsonField, $row)) {
                    $row[$jsonField] = $row[$jsonField] === null ? null : json_encode($row[$jsonField]);
                }
            }
            if ($row === []) {
                return $this->getById((int) $project['id']); // nothing changed: no revision, no journal entry
            }
            $this->db->table('projects')->where('id', $project['id'])->update($row + ['updated_at' => Time::now()]);
            $revision = $this->bumpRevision((int) $project['id']);
            $this->journal->append('project.updated', $ctx, (int) $project['customer_id'], (int) $project['id'], [
                'project' => $ref, 'changed' => array_keys($changes), 'revision' => $revision,
            ]);

            return $this->getById((int) $project['id']);
        });
    }

    /**
     * Loads a project for modification and enforces the managed_by rule:
     * admin-managed projects may be changed from the CLI only with --force.
     * Call inside a transaction; the row is locked.
     *
     * @return array<string, mixed>
     */
    public function getForChange(string $ref, ChangeContext $ctx): array
    {
        [$customerKey, $projectKey] = Keys::splitProjectRef($ref);
        $project = $this->db->query(
            'SELECT p.*, c.customer_key FROM projects p JOIN customers c ON c.id = p.customer_id
             WHERE c.customer_key = ? AND p.project_key = ? FOR UPDATE',
            [$customerKey, $projectKey],
        )->getRowArray() ?? throw new ConfigException('PROJECT_NOT_FOUND', 'Project "' . $ref . '" not found');

        if ($project['managed_by'] === 'admin' && $ctx->source === 'cli' && ! $ctx->force) {
            throw new ConfigException(
                'MANAGED_BY_ADMIN',
                'Project "' . $ref . '" is managed by the admin app; use --force to change it from the CLI',
            );
        }

        return $project;
    }

    /**
     * Increments the project revision; returns the new revision.
     */
    public function bumpRevision(int $projectId): int
    {
        $this->db->query('UPDATE projects SET revision = revision + 1, updated_at = ? WHERE id = ?', [Time::now(), $projectId]);
        self::requestApply();

        return (int) $this->db->query('SELECT revision FROM projects WHERE id = ?', [$projectId])->getRow()->revision;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $ref): ?array
    {
        [$customerKey, $projectKey] = Keys::splitProjectRef($ref);

        return $this->db->query(
            'SELECT p.*, c.customer_key FROM projects p JOIN customers c ON c.id = p.customer_id
             WHERE c.customer_key = ? AND p.project_key = ?',
            [$customerKey, $projectKey],
        )->getRowArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $ref): array
    {
        return $this->find($ref) ?? throw new ConfigException('PROJECT_NOT_FOUND', 'Project "' . $ref . '" not found');
    }

    /**
     * @return array<string, mixed>
     */
    public function getById(int $id): array
    {
        return $this->db->query(
            'SELECT p.*, c.customer_key FROM projects p JOIN customers c ON c.id = p.customer_id WHERE p.id = ?',
            [$id],
        )->getRowArray() ?? throw new ConfigException('PROJECT_NOT_FOUND', 'Project #' . $id . ' not found');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $customerKey = null): array
    {
        $builder = $this->db->table('projects p')
            ->select('p.*, c.customer_key')
            ->join('customers c', 'c.id = p.customer_id')
            ->orderBy('c.customer_key')->orderBy('p.project_key');
        if ($customerKey !== null) {
            $builder->where('c.customer_key', $customerKey);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * Asks the node to regenerate the mail path configuration (systemd path unit
     * mailrunner-apply.path runs "spark mr:apply" as root). No-op where the data
     * directory is not writable, e.g. in development.
     */
    public static function requestApply(): void
    {
        $dir = config('Mailrunner')->dataDir;
        if (is_dir($dir) && is_writable($dir)) {
            @touch($dir . '/apply.request');
        }
    }

    public static function refOf(array $project): string
    {
        return $project['customer_key'] . '/' . $project['project_key'];
    }

    /**
     * Test criteria of a send project: stored values over the defaults.
     *
     * @return array<string, bool>
     */
    public static function testCriteria(array $project): array
    {
        $stored = is_string($project['send_test_criteria'] ?? null) ? json_decode($project['send_test_criteria'], true) : ($project['send_test_criteria'] ?? null);

        return array_merge(self::TEST_CRITERIA, is_array($stored) ? array_intersect_key($stored, self::TEST_CRITERIA) : []);
    }

    /**
     * Allowed sender domains of a send project; null = all.
     *
     * @return list<string>|null
     */
    public static function allowedSendDomains(array $project): ?array
    {
        $v = $project['send_allowed_domains'] ?? null;

        return is_string($v) ? json_decode($v, true) : $v;
    }

    /** A domain is allowed if the list is null (all) or it equals a listed domain or is a subdomain of one. */
    public static function sendDomainAllowed(array $project, string $domain): bool
    {
        $list = self::allowedSendDomains($project);
        if ($list === null) {
            return true;
        }
        foreach ($list as $d) {
            if ($domain === $d || str_ends_with($domain, '.' . $d)) {
                return true;
            }
        }

        return false;
    }

    public static function isDomainName(string $domain): bool
    {
        return strlen($domain) <= 253 && preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $domain) === 1;
    }

    /**
     * @return list<string>|null
     */
    private static function normalizeDomainList(mixed $list): ?array
    {
        if ($list === null) {
            return null; // all domains
        }
        if (! is_array($list)) {
            throw new ConfigException('INVALID_SETTING', 'send_allowed_domains must be a list of domains or null (all)');
        }
        $out = [];
        foreach ($list as $d) {
            $d = strtolower(rtrim(trim((string) $d), '.'));
            if ($d === '') {
                continue;
            }
            if (! self::isDomainName($d)) {
                throw new ConfigException('INVALID_SETTING', 'Invalid domain "' . $d . '" in send_allowed_domains');
            }
            $out[] = $d;
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * @return array<string, bool>
     */
    private static function normalizeCriteria(mixed $value): ?array
    {
        if ($value === null) {
            return null; // defaults
        }
        if (! is_array($value)) {
            throw new ConfigException('INVALID_SETTING', 'send_test_criteria must be an object');
        }
        $unknown = array_diff(array_keys($value), array_keys(self::TEST_CRITERIA));
        if ($unknown !== []) {
            throw new ConfigException('INVALID_SETTING', 'Unknown test criteria: ' . implode(', ', $unknown) . ' (known: ' . implode(', ', array_keys(self::TEST_CRITERIA)) . ')');
        }
        $out = [];
        foreach (self::TEST_CRITERIA as $k => $default) {
            $out[$k] = array_key_exists($k, $value) ? (bool) $value[$k] : $default;
        }

        return $out;
    }

    private static function normalizeRecipientPattern(mixed $pattern): ?string
    {
        if ($pattern === null || $pattern === '') {
            return null;
        }
        $pattern = (string) $pattern;
        if (strlen($pattern) > 200) {
            throw new ConfigException('INVALID_SETTING', 'recipient_pattern is too long (max 200)');
        }
        // Stored without delimiters; must compile as PCRE (Python's re is checked by the mail path).
        if (@preg_match('/' . str_replace('/', '\/', $pattern) . '/', '') === false) {
            throw new ConfigException('INVALID_SETTING', 'recipient_pattern is not a valid regular expression');
        }

        return $pattern;
    }

    /**
     * @return list<string>|null
     */
    private static function normalizeLocalParts(mixed $list): ?array
    {
        if ($list === null) {
            return null;
        }
        $result = [];
        foreach ((array) $list as $part) {
            $part = strtolower(trim((string) $part));
            if ($part === '') {
                continue;
            }
            if (preg_match('/^[a-z0-9._+-]{1,64}$/', $part) !== 1) {
                throw new ConfigException('INVALID_SETTING', 'Invalid local part "' . $part . '"');
            }
            $result[] = $part;
        }

        return array_values(array_unique($result));
    }
}
