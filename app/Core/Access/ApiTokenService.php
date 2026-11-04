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
 * API tokens: several per project, each with a public id and a secret value.
 *
 * Presented by clients as "Authorization: Bearer <token_id>.<secret>".
 * Only the SHA-256 of the secret is stored; the secret is shown once.
 *
 * Central tokens: once MailRunner Admin has sent the token set of a project (sync()), the project's tokens come
 * only from the admin (same token on every node of the project). Local create/rotate is refused then; a local
 * revoke stays possible as an emergency stop and is never undone by a later sync.
 */
class ApiTokenService
{
    public const ID_PREFIX = 'mrt';

    /** Customer of the node's own projects (self test): their tokens are always local (install.sh creates them). */
    public const LOCAL_CUSTOMER = 'system';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly JournalWriter $journal,
        private readonly ProjectService $projects,
    ) {
    }

    /**
     * Creates a token.
     *
     * @return array{token: array<string, mixed>, secret: string, bearer: string}
     */
    public function create(string $ref, string $label, ?int $expiresInSeconds, ChangeContext $ctx): array
    {
        $label = self::normalizeLabel($label);

        return Transaction::run($this->db, function () use ($ref, $label, $expiresInSeconds, $ctx): array {
            $project = $this->projects->getForChange($ref, $ctx);
            self::assertLocal($project);

            return $this->insertToken($project, $label, $expiresInSeconds, $ctx, null, 'token.created');
        });
    }

    /**
     * Rotates a token: creates a successor with the same label and lets the old one
     * expire after the grace period (0 = revoke immediately).
     *
     * @return array{token: array<string, mixed>, secret: string, bearer: string, old: array<string, mixed>}
     */
    public function rotate(string $tokenId, int $graceSeconds, ChangeContext $ctx): array
    {
        return Transaction::run($this->db, function () use ($tokenId, $graceSeconds, $ctx): array {
            $old     = $this->getActive($tokenId);
            $project = $this->projects->getForChange(ProjectService::refOf($this->projects->getById((int) $old['project_id'])), $ctx);
            self::assertLocal($project);

            $expiresIn = $old['expires_at'] === null ? null
                : max(0, strtotime($old['expires_at'] . ' UTC') - time());
            $new = $this->insertToken($project, $old['label'], $expiresIn, $ctx, (int) $old['id'], 'token.rotated', [
                'replaces' => $old['token_id'], 'grace_seconds' => $graceSeconds,
            ]);

            if ($graceSeconds === 0) {
                $this->db->table('api_tokens')->where('id', $old['id'])->update(['revoked_at' => Time::now()]);
            } else {
                $graceEnd = Time::inSeconds($graceSeconds);
                if ($old['expires_at'] === null || $old['expires_at'] > $graceEnd) {
                    $this->db->table('api_tokens')->where('id', $old['id'])->update(['expires_at' => $graceEnd]);
                }
            }

            return $new + ['old' => $this->getByTokenId($old['token_id'])];
        });
    }

    public function revoke(string $tokenId, ChangeContext $ctx): void
    {
        Transaction::run($this->db, function () use ($tokenId, $ctx): void {
            $token   = $this->getActive($tokenId);
            $project = $this->projects->getForChange(ProjectService::refOf($this->projects->getById((int) $token['project_id'])), $ctx);
            $this->db->table('api_tokens')->where('id', $token['id'])->update(['revoked_at' => Time::now()]);
            $revision = $this->projects->bumpRevision((int) $project['id']);
            $this->journal->append('token.revoked', $ctx, (int) $project['customer_id'], (int) $project['id'], [
                'token_id' => $tokenId, 'revision' => $revision,
            ]);
        });
    }

    /**
     * Checks a bearer value "<token_id>.<secret>". Returns the token row (with project_id) or null.
     *
     * @return array<string, mixed>|null
     */
    public function authenticate(string $bearer): ?array
    {
        if (preg_match('/^(' . self::ID_PREFIX . '_[a-z0-9]{16})\.([A-Za-z0-9_-]{43})$/', $bearer, $m) !== 1) {
            return null;
        }
        $token = $this->findByTokenId($m[1]);
        if ($token === null || ! self::isUsable($token)) {
            return null;
        }
        if (! hash_equals($token['secret_hash'], hash('sha256', $m[2]))) {
            return null;
        }
        // Throttled usage timestamp (at most once per minute).
        if ($token['last_used_at'] === null || strtotime($token['last_used_at'] . ' UTC') < time() - 60) {
            $this->db->table('api_tokens')->where('id', $token['id'])->update(['last_used_at' => Time::now()]);
        }

        return $token;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $ref): array
    {
        $project = $this->projects->get($ref);

        return $this->db->table('api_tokens')
            ->select('token_id, label, created_at, created_source, expires_at, revoked_at, last_used_at, rotated_from_id')
            ->where('project_id', $project['id'])->orderBy('id')->get()->getResultArray();
    }

    /**
     * @param array<string, mixed> $token
     */
    public static function isUsable(array $token): bool
    {
        if ($token['revoked_at'] !== null) {
            return false;
        }

        return $token['expires_at'] === null || $token['expires_at'] > Time::now();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByTokenId(string $tokenId): ?array
    {
        return $this->db->table('api_tokens')->where('token_id', $tokenId)->get()->getRowArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function getByTokenId(string $tokenId): array
    {
        return $this->findByTokenId($tokenId)
            ?? throw new ConfigException('TOKEN_NOT_FOUND', 'Token "' . $tokenId . '" not found');
    }

    /**
     * @return array<string, mixed>
     */
    private function getActive(string $tokenId): array
    {
        $token = $this->getByTokenId($tokenId);
        if (! self::isUsable($token)) {
            throw new ConfigException('TOKEN_INACTIVE', 'Token "' . $tokenId . '" is already revoked or expired');
        }

        return $token;
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $extraDetails
     *
     * @return array{token: array<string, mixed>, secret: string, bearer: string}
     */
    private function insertToken(
        array $project,
        string $label,
        ?int $expiresInSeconds,
        ChangeContext $ctx,
        ?int $rotatedFromId,
        string $event,
        array $extraDetails = [],
    ): array {
        $tokenId = RandomId::publicId(self::ID_PREFIX);
        $secret  = RandomId::secret();
        $this->db->table('api_tokens')->insert([
            'token_id'        => $tokenId,
            'project_id'      => $project['id'],
            'label'           => $label,
            'secret_hash'     => hash('sha256', $secret),
            'created_at'      => Time::now(),
            'created_source'  => $ctx->source,
            'expires_at'      => $expiresInSeconds === null ? null : Time::inSeconds($expiresInSeconds),
            'revoked_at'      => null,
            'last_used_at'    => null,
            'rotated_from_id' => $rotatedFromId,
        ]);
        $revision = $this->projects->bumpRevision((int) $project['id']);
        $this->journal->append($event, $ctx, (int) $project['customer_id'], (int) $project['id'], [
            'token_id' => $tokenId, 'label' => $label, 'revision' => $revision,
        ] + $extraDetails);

        return ['token' => $this->getByTokenId($tokenId), 'secret' => $secret, 'bearer' => $tokenId . '.' . $secret];
    }

    /**
     * Replaces the token set of a project with the one from MailRunner Admin (only hashes, never secrets).
     * Unknown tokens are added, known ones updated (label, expiry, revocation), tokens missing from the set are
     * revoked. A revocation on the node is kept even if the admin still lists the token as active.
     *
     * @param list<array<string, mixed>> $tokens {token_id, label, secret_hash, created_at?, expires_at?, revoked_at?}
     *
     * @return array{added: int, updated: int, revoked: int}
     */
    public function sync(string $ref, array $tokens, ChangeContext $ctx): array
    {
        if (str_starts_with($ref, self::LOCAL_CUSTOMER . '/')) {
            throw new ConfigException('TOKENS_LOCAL', 'The tokens of node system projects (self test) stay local');
        }
        $rows = [];
        foreach ($tokens as $t) {
            if (! is_array($t) || preg_match('/^' . self::ID_PREFIX . '_[a-z0-9]{16}$/', (string) ($t['token_id'] ?? '')) !== 1
                || preg_match('/^[0-9a-f]{64}$/', (string) ($t['secret_hash'] ?? '')) !== 1) {
                throw new ConfigException('INVALID_TOKEN', 'Every token needs token_id (mrt_ + 16 chars) and secret_hash (sha256 hex)');
            }
            $rows[$t['token_id']] = [
                'label'       => self::normalizeLabel((string) ($t['label'] ?? '')),
                'secret_hash' => $t['secret_hash'],
                'created_at'  => self::time($t['created_at'] ?? null) ?? Time::now(),
                'expires_at'  => self::time($t['expires_at'] ?? null),
                'revoked_at'  => self::time($t['revoked_at'] ?? null),
            ];
        }

        return Transaction::run($this->db, function () use ($ref, $rows, $ctx): array {
            $project = $this->projects->getForChange($ref, $ctx);
            $pid     = (int) $project['id'];
            $stats   = ['added' => 0, 'updated' => 0, 'revoked' => 0];
            $have    = [];
            foreach ($this->db->table('api_tokens')->where('project_id', $pid)->get()->getResultArray() as $t) {
                $have[$t['token_id']] = $t;
            }
            if ($rows !== []) {
                foreach ($this->db->table('api_tokens')->select('token_id')->whereIn('token_id', array_keys($rows))->where('project_id !=', $pid)->get()->getResultArray() as $c) {
                    throw new ConfigException('TOKEN_CONFLICT', 'Token "' . $c['token_id'] . '" belongs to another project');
                }
            }
            $now = Time::now();
            foreach ($rows as $id => $r) {
                $cur = $have[$id] ?? null;
                if ($cur === null) {
                    $this->db->table('api_tokens')->insert(['token_id' => $id, 'project_id' => $pid, 'created_source' => 'admin',
                        'last_used_at' => null, 'rotated_from_id' => null] + $r);
                    $stats['added']++;

                    continue;
                }
                $want = ['label' => $r['label'], 'secret_hash' => $r['secret_hash'], 'expires_at' => $r['expires_at'],
                    'revoked_at' => $cur['revoked_at'] ?? $r['revoked_at']]; // a local revocation is never undone
                if (array_diff_assoc(array_map('strval', $want), array_map('strval', array_intersect_key($cur, $want))) !== []) {
                    $this->db->table('api_tokens')->where('id', $cur['id'])->update($want);
                    $stats['updated']++;
                }
            }
            foreach (array_diff_key($have, $rows) as $t) {
                if ($t['revoked_at'] === null) {
                    $this->db->table('api_tokens')->where('id', $t['id'])->update(['revoked_at' => $now]);
                    $stats['revoked']++;
                }
            }
            $changed = $stats !== ['added' => 0, 'updated' => 0, 'revoked' => 0];
            if ((int) $project['tokens_central'] !== 1) {
                $this->db->table('projects')->where('id', $pid)->update(['tokens_central' => 1]);
                $changed = true;
            }
            if ($changed) {
                $revision = $this->projects->bumpRevision($pid);
                $this->journal->append('token.synced', $ctx, (int) $project['customer_id'], $pid, $stats + ['revision' => $revision]);
            }

            return $stats;
        });
    }

    /** @param array<string, mixed> $project */
    private static function assertLocal(array $project): void
    {
        if ((int) ($project['tokens_central'] ?? 0) === 1) {
            throw new ConfigException('TOKENS_CENTRAL', 'The tokens of this project are managed in MailRunner Admin (same token on every node); create or rotate them there');
        }
    }

    /** ISO 8601 / DB time -> DB time (UTC), null stays null. */
    private static function time(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            $t = new \DateTimeImmutable((string) $v, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new ConfigException('INVALID_TOKEN', 'Invalid time "' . $v . '"');
        }

        return $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function normalizeLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 100) {
            throw new ConfigException('INVALID_LABEL', 'Label is required (max 100 chars)');
        }

        return $label;
    }
}
