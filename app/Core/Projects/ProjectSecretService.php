<?php

namespace App\Core\Projects;

use App\Core\Journal\JournalWriter;
use App\Core\Secrets\NodeCrypto;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;
use App\Core\Support\Time;
use App\Core\Support\Transaction;
use CodeIgniter\Database\BaseConnection;

/**
 * Encrypted secrets per project. Only known names are accepted; values are never returned
 * by CLI or API, only used internally (e.g. passed to the analysis via mr:apply).
 */
class ProjectSecretService
{
    /** name => [validation regex, description] */
    public const ALLOWED = [
        'spamhaus_dqs_key'     => ['/^[A-Za-z0-9]{8,128}$/', 'Spamhaus Data Query Service key (DNSBL via zen.dq.spamhaus.net)'],
        'blacklistmaster_user' => ['/^[\x21-\x7e]{1,200}$/', 'blacklistmaster.com API user'],
        'blacklistmaster_pass' => ['/^[\x20-\x7e]{1,200}$/', 'blacklistmaster.com API password'],
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly JournalWriter $journal,
        private readonly ProjectService $projects,
        private readonly ?NodeCrypto $crypto = null,
    ) {
    }

    public function set(string $ref, string $name, string $value, ChangeContext $ctx): void
    {
        self::assertName($name);
        $value = trim($value);
        if (preg_match(self::ALLOWED[$name][0], $value) !== 1) {
            throw new ConfigException('INVALID_SECRET', 'Value for "' . $name . '" has an invalid format');
        }

        Transaction::run($this->db, function () use ($ref, $name, $value, $ctx): void {
            $project = $this->projects->getForChange($ref, $ctx);
            $enc     = $this->crypto()->encrypt($value, self::context((int) $project['id'], $name));
            $exists  = $this->db->table('project_secrets')->where(['project_id' => $project['id'], 'name' => $name])->countAllResults() > 0;
            $row     = ['value_enc' => $enc, 'updated_at' => Time::now(), 'updated_source' => $ctx->source];
            $exists
                ? $this->db->table('project_secrets')->where(['project_id' => $project['id'], 'name' => $name])->update($row)
                : $this->db->table('project_secrets')->insert($row + ['project_id' => $project['id'], 'name' => $name]);
            $revision = $this->projects->bumpRevision((int) $project['id']);
            $this->journal->append('secret.set', $ctx, (int) $project['customer_id'], (int) $project['id'], ['name' => $name, 'revision' => $revision]);
        });
    }

    public function unset(string $ref, string $name, ChangeContext $ctx): void
    {
        self::assertName($name);
        Transaction::run($this->db, function () use ($ref, $name, $ctx): void {
            $project = $this->projects->getForChange($ref, $ctx);
            $this->db->table('project_secrets')->where(['project_id' => $project['id'], 'name' => $name])->delete();
            if ($this->db->affectedRows() === 0) {
                throw new ConfigException('SECRET_NOT_FOUND', 'Secret "' . $name . '" is not set');
            }
            $revision = $this->projects->bumpRevision((int) $project['id']);
            $this->journal->append('secret.unset', $ctx, (int) $project['customer_id'], (int) $project['id'], ['name' => $name, 'revision' => $revision]);
        });
    }

    /**
     * Names and timestamps only, never values.
     *
     * @return list<array{name: string, updated_at: string, updated_source: string}>
     */
    public function list(string $ref): array
    {
        $project = $this->projects->get($ref);

        return $this->db->table('project_secrets')->select('name, updated_at, updated_source')
            ->where('project_id', $project['id'])->orderBy('name')->get()->getResultArray();
    }

    /**
     * Decrypted secrets of a project (internal use only).
     *
     * @return array<string, string>
     */
    public function valuesForProject(int $projectId): array
    {
        $out = [];
        foreach ($this->db->table('project_secrets')->where('project_id', $projectId)->get()->getResultArray() as $row) {
            $out[$row['name']] = $this->crypto()->decrypt($row['value_enc'], self::context($projectId, $row['name']));
        }

        return $out;
    }

    private function crypto(): NodeCrypto
    {
        return $this->crypto ?? NodeCrypto::forNode();
    }

    private static function context(int $projectId, string $name): string
    {
        return 'db:project_secrets:' . $projectId . ':' . $name;
    }

    private static function assertName(string $name): void
    {
        if (! array_key_exists($name, self::ALLOWED)) {
            throw new ConfigException('UNKNOWN_SECRET', 'Unknown secret "' . $name . '". Allowed: ' . implode(', ', array_keys(self::ALLOWED)));
        }
    }
}
