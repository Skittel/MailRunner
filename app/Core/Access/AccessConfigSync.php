<?php

namespace App\Core\Access;

use App\Core\Journal\JournalWriter;
use App\Core\Projects\ProjectService;
use App\Core\Send\SendConfigSync;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;
use App\Core\Support\Time;
use App\Core\Support\Transaction;
use App\Core\Webhooks\WebhookService;
use CodeIgniter\Database\BaseConnection;

/**
 * Central distribution of what the customer's application sets up on a node (decision E-27, extended to receive
 * projects): MailRunner Admin takes the set over from the node where it changed and puts it on the other nodes of the
 * project, with the same ids and hashes, so logins and tokens work everywhere.
 *
 *   send projects:    domains with DKIM key, send profiles, accesses (SendConfigSync) + webhook
 *   receive projects: SMTP logins (profile checked_auth) + webhook
 *
 * "webhook" is only part of the set while one is set: sets without a webhook keep the hash of node 0.2.8/0.2.9.
 * Not part of the set (stays per node): last use, webhook delivery state, domain tests, messages. Self test projects
 * (system/...) stay local.
 */
class AccessConfigSync
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly JournalWriter $journal,
        private readonly SendConfigSync $send,
        private readonly WebhookService $webhooks,
        private readonly ProjectService $projects,
    ) {
    }

    /**
     * The canonical set. Secrets (DKIM private keys) only with $secrets; password and token hashes always (they
     * are what makes the same login work on every node; the hash of the set is shown, never the set itself).
     *
     * @param array<string, mixed> $project row with id and type
     *
     * @return array<string, mixed>
     */
    public function snapshot(array $project, bool $secrets = false): array
    {
        $pid = (int) $project['id'];
        $set = ($project['type'] ?? 'receive') === 'send' ? $this->send->snapshot($pid, $secrets) : ['smtp_credentials' => $this->credentials($pid)];
        $w   = $this->webhooks->get($pid);
        if ($w !== null) {
            $set['webhook'] = ['url' => $w['url'], 'cooldown_seconds' => (int) $w['cooldown_seconds']];
        }

        return $set;
    }

    /** @param array<string, mixed> $project */
    public function hash(array $project): string
    {
        return SendConfigSync::hashOf($this->snapshot($project));
    }

    /** Latest local change (tie-break when two nodes changed at once); deletions are not seen. @param array<string, mixed> $project */
    public function changedAt(array $project): ?string
    {
        $pid = (int) $project['id'];
        $t   = [($project['type'] ?? 'receive') === 'send' ? $this->send->changedAt($pid) : Time::toIso($this->db->table('smtp_credentials')
            ->selectMax('updated_at', 't')->where('project_id', $pid)->where('scope', 'receive')->get()->getRow('t'))];
        $t[] = Time::toIso($this->db->table('project_webhooks')->select('updated_at')->where('project_id', $pid)->get()->getRow('updated_at'));
        $t   = array_filter($t);

        return $t === [] ? null : max($t);
    }

    /**
     * Makes the node's set equal to $set. With $ifHash the node refuses when its set changed in the meantime
     * (SEND_CONFIG_CHANGED: the admin takes the local change over first). All or nothing.
     *
     * @param array<string, mixed> $set
     *
     * @return array{hash: string, changed: array<string, int>}
     */
    public function apply(string $ref, array $set, ?string $ifHash, ChangeContext $ctx): array
    {
        if (array_key_exists('webhook', $set) && $set['webhook'] !== null && ! is_array($set['webhook'])) {
            throw new ConfigException('INVALID_SEND_CONFIG', 'webhook must be an object or null');
        }

        return Transaction::run($this->db, function () use ($ref, $set, $ifHash, $ctx): array {
            $project = $this->projects->getForChange($ref, $ctx); // locks the project row: one apply at a time
            if ($ifHash !== null && ! hash_equals($ifHash, $this->hash($project))) {
                throw new ConfigException('SEND_CONFIG_CHANGED', 'The configuration on this node changed since it was read; take it over first');
            }
            $send = $project['type'] === 'send';
            $n    = $send ? $this->send->applySet($project, $set, $ctx) : $this->applyCredentials($project, $set['smtp_credentials'] ?? null);
            $n['webhook'] = $this->applyWebhook((int) $project['id'], is_array($set['webhook'] ?? null) ? $set['webhook'] : null, $ctx) ? 1 : 0;

            $hash = $this->hash($project);
            if (array_sum($n) > 0) {
                $this->journal->append($send ? 'send.config.synced' : 'receive.config.synced', $ctx, (int) $project['customer_id'], (int) $project['id'], $n + ['hash' => $hash]);
                ProjectService::requestApply(); // mail path (credentials.json, send.json, send_clients.cidr)
            }

            return ['hash' => $hash, 'changed' => $n];
        });
    }

    /** SMTP logins of a receive project (not revoked), sorted by id. @return list<array<string, mixed>> */
    private function credentials(int $projectId): array
    {
        $out = [];
        foreach ($this->db->table('smtp_credentials')->where('project_id', $projectId)->where('scope', 'receive')->where('revoked_at', null)
            ->orderBy('credential_id')->get()->getResultArray() as $c) {
            $out[] = ['credential_id' => $c['credential_id'], 'username' => $c['username'], 'label' => $c['label'], 'password_hash' => $c['password_hash'],
                'previous_password_hash' => $c['previous_password_hash'], 'previous_valid_until' => Time::toIso($c['previous_valid_until']),
                'tls_required' => (bool) $c['tls_required'], 'allowed_networks' => $c['allowed_networks'] === null ? null : json_decode($c['allowed_networks'], true),
                'enabled' => (bool) $c['enabled'], 'password_change_required' => (bool) $c['password_change_required']];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $project
     *
     * @return array{smtp_credentials: int, removed: int}
     */
    private function applyCredentials(array $project, mixed $want): array
    {
        if (! is_array($want) || ! array_is_list($want)) {
            throw new ConfigException('INVALID_SEND_CONFIG', 'smtp_credentials must be a list');
        }
        $pid = (int) $project['id'];
        if ($want !== [] && $project['profile'] !== 'checked_auth') {
            throw new ConfigException('PROFILE_NO_SMTP_AUTH', 'SMTP logins need profile checked_auth');
        }
        $before = array_column($this->credentials($pid), null, 'credential_id');
        $ids    = array_column($want, 'credential_id');
        $n      = ['smtp_credentials' => 0, 'removed' => 0];
        $now    = Time::now();
        foreach ($before as $id => $c) { // first free the usernames of logins that go away
            if (! in_array($id, $ids, true)) {
                $this->db->table('smtp_credentials')->where('credential_id', $id)->update(['revoked_at' => $now, 'enabled' => 0, 'updated_at' => $now]);
                $n['removed']++;
            }
        }
        foreach ($want as $c) {
            $this->send->putCredential($pid, $c, ! empty($c['enabled']), 'receive');
        }
        $after = array_column($this->credentials($pid), null, 'credential_id');
        foreach ($after as $id => $c) {
            $n['smtp_credentials'] += ($before[$id] ?? null) !== $c ? 1 : 0;
        }
        if (array_sum($n) > 0) {
            $this->projects->bumpRevision($pid);
        }

        return $n;
    }

    /** @param array<string, mixed>|null $want */
    private function applyWebhook(int $projectId, ?array $want, ChangeContext $ctx): bool
    {
        if ($want === null) {
            return $this->webhooks->delete($projectId, $ctx);
        }
        if (! is_string($want['url'] ?? null)) {
            throw new ConfigException('INVALID_SEND_CONFIG', 'webhook needs url');
        }

        return $this->webhooks->set($projectId, $want['url'], isset($want['cooldown_seconds']) ? (int) $want['cooldown_seconds'] : null, $ctx, false)[1];
    }
}
