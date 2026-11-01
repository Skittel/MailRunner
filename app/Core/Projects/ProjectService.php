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
    public const PROFILES = ['analysis', 'checked', 'checked_auth'];

    /** Settings that may be changed with update(). */
    private const SETTINGS = [
        'name', 'enabled', 'recipient_pattern', 'recipient_always_allow',
        'delete_after_ack_hours', 'unacked_days', 'metadata_days',
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
    public function create(string $ref, string $profile, string $name, ChangeContext $ctx): array
    {
        [$customerKey, $projectKey] = Keys::splitProjectRef($ref);
        if (! in_array($profile, self::PROFILES, true)) {
            throw new ConfigException('INVALID_PROFILE', 'Profile must be one of: ' . implode(', ', self::PROFILES));
        }

        return Transaction::run($this->db, function () use ($ref, $customerKey, $projectKey, $profile, $name, $ctx): array {
            $customer = $this->customers->getByKey($customerKey);
            if ($this->find($ref) !== null) {
                throw new ConfigException('PROJECT_EXISTS', 'Project "' . $ref . '" already exists');
            }
            $now = Time::now();
            $this->db->table('projects')->insert([
                'customer_id' => $customer['id'],
                'project_key' => $projectKey,
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
                'project' => $ref, 'profile' => $profile, 'revision' => 1,
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

        return Transaction::run($this->db, function () use ($ref, $changes, $ctx): array {
            $project = $this->getForChange($ref, $ctx);
            $row     = $changes;
            if (array_key_exists('recipient_always_allow', $row)) {
                $row['recipient_always_allow'] = $row['recipient_always_allow'] === null ? null : json_encode($row['recipient_always_allow']);
            }
            if ($row !== []) {
                $this->db->table('projects')->where('id', $project['id'])->update($row + ['updated_at' => Time::now()]);
            }
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

    public static function refOf(array $project): string
    {
        return $project['customer_key'] . '/' . $project['project_key'];
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
