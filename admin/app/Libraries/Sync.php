<?php

namespace App\Libraries;

use Config\Admin;
use Throwable;

/**
 * Pushes the desired state (customers, projects) to the nodes and reads node state.
 * The admin database is the source of truth; every push is idempotent (PUT with the full configuration).
 */
class Sync
{
    public function __construct(private readonly Admin $config)
    {
    }

    public function client(array $node): NodeClient
    {
        $actor = session('user_email') ?: session('user_name');

        return new NodeClient($this->config, $node['base_url'], $actor ?: null);
    }

    /** Polls one node (GET /admin/v1/node) and stores the result. */
    public function poll(array $node): void
    {
        $now = Clock::now();
        try {
            $status = $this->client($node)->ok('GET', 'node');
            $status = $this->offerUpdate($node, $status);
            $status = $this->offerSecrets($node, $status);
            $this->offerTokens($node);
            db_connect()->table('nodes')->where('id', $node['id'])->update([
                'last_checked_at' => $now, 'last_ok_at' => $now, 'last_error' => null,
                'last_status' => json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            db_connect()->table('nodes')->where('id', $node['id'])->update([
                'last_checked_at' => $now, 'last_error' => mb_substr($e->getMessage(), 0, 500),
            ]);
        }
    }

    /**
     * Pushes a project (and its customer) to its node. Stores the outcome on the project row.
     *
     * @return string|null error message, null on success
     */
    /**
     * Pushes the desired state of a project to its node, or with $nodeId to another node (project move:
     * the old node, then with $enabled = false). Only a push to the project's own node updates sync state.
     */
    public function pushProject(int $projectId, ?int $nodeId = null, ?bool $enabled = null): ?string
    {
        $db = db_connect();
        if ($nodeId === null) {
            $this->ensureTokens($projectId); // first push with central tokens: take over the node's tokens
        }
        $p  = $db->table('projects p')->select('p.*, c.customer_key, c.name AS customer_name, c.enabled AS customer_enabled')
            ->join('customers c', 'c.id = p.customer_id')->where('p.id', $projectId)->get()->getRowArray();
        $own  = $nodeId === null || $nodeId === (int) $p['node_id'];
        $node = $db->table('nodes')->where('id', $nodeId ?? $p['node_id'])->get()->getRowArray();
        try {
            $client = $this->client($node);
            $client->ok('PUT', 'customers/' . $p['customer_key'], ['name' => $p['customer_name'], 'enabled' => (bool) $p['customer_enabled']]);
            $body = [
                'profile'  => $p['profile'],
                'name'     => $p['name'],
                'enabled'  => $enabled ?? (bool) $p['enabled'],
                'routes'   => json_decode($p['routes'], true) ?: [],
                'settings' => json_decode($p['settings'], true) ?: [],
            ];
            $imported = $db->table('projects')->select('tokens_imported_at')->where('id', $projectId)->get()->getRow('tokens_imported_at');
            if ($imported !== null) { // until the node's own tokens are imported, the node keeps its tokens
                $body['tokens'] = (new Tokens())->forPush($projectId);
            }
            $res = $client->ok('PUT', 'projects/' . $p['customer_key'] . '/' . $p['project_key'], $body);
            if ($own) {
                $db->table('projects')->where('id', $projectId)->update(! empty($res['pending'])
                    ? ['sync_error' => null, 'sync_pending' => 1] // node in manual mode: waits for mr:config:apply there
                    : ['synced_at' => Clock::now(), 'sync_error' => null, 'sync_pending' => 0, 'node_revision' => (int) ($res['data']['revision'] ?? 0)]);
            }

            return null;
        } catch (Throwable $e) {
            $msg = $e instanceof NodeError ? $e->errorCode . ': ' . $e->getMessage() : $e->getMessage();
            if ($own) {
                $db->table('projects')->where('id', $projectId)->update(['sync_error' => mb_substr($msg, 0, 500)]);
            }

            return $msg;
        }
    }

    /**
     * Pushes a project to every node it is on: its node and, during a move, the old node (later: every API node
     * of the project). Used after token changes, so a bearer is valid or revoked everywhere.
     *
     * @return string|null errors per node, null on success
     */
    public function pushEverywhere(int $projectId): ?string
    {
        $p      = db_connect()->table('projects')->select('node_id, move_from_node_id')->where('id', $projectId)->get()->getRowArray();
        $errors = [];
        if (($e = $this->pushProject($projectId)) !== null) {
            $errors[] = $e;
        }
        if ($p['move_from_node_id'] !== null && ($e = $this->pushProject($projectId, (int) $p['move_from_node_id'])) !== null) {
            $errors[] = 'alte Node: ' . $e;
        }

        return $errors === [] ? null : implode('; ', $errors);
    }

    /**
     * Takes over the tokens the project's node has (once). True when the admin manages the project's tokens:
     * imported now or before, or the project does not exist on the node yet. False when the node is unreachable
     * or too old to report token hashes (then the node keeps its own tokens and nothing is sent).
     */
    public function ensureTokens(int $projectId): bool
    {
        $db = db_connect();
        $p  = $db->table('projects p')->select('p.id, p.node_id, p.tokens_imported_at, p.project_key, c.customer_key')
            ->join('customers c', 'c.id = p.customer_id')->where('p.id', $projectId)->get()->getRowArray();
        if ($p['tokens_imported_at'] !== null) {
            return true;
        }
        if ($p['customer_key'] === Tokens::LOCAL_CUSTOMER) {
            return false; // self test projects exist on every node with their own local tokens
        }
        $node = $db->table('nodes')->where('id', $p['node_id'])->get()->getRowArray();
        try {
            $data = $this->client($node)->ok('GET', 'projects/' . $p['customer_key'] . '/' . $p['project_key'])['data'];
        } catch (NodeError $e) {
            if ($e->status !== 404) {
                return false;
            }
            $data = ['tokens_central' => false, 'tokens' => []]; // new on this node: nothing to take over
        } catch (Throwable) {
            return false;
        }
        if (! array_key_exists('tokens_central', $data)) {
            return false; // node version without central tokens
        }
        $n = (new Tokens())->import($projectId, $data['tokens'] ?? []);
        $db->table('projects')->where('id', $projectId)->update(['tokens_imported_at' => Clock::now()]);
        Audit::log('tokens.imported', $p['customer_key'] . '/' . $p['project_key'], ['count' => $n, 'node' => (int) $p['node_id']]);

        return true;
    }

    /** Poll: projects of the node whose tokens are not central yet are imported and pushed (once per project). */
    private function offerTokens(array $node): void
    {
        foreach (db_connect()->table('projects p')->select('p.id')->join('customers c', 'c.id = p.customer_id')->where('p.node_id', $node['id'])
            ->where('p.tokens_imported_at', null)->where('p.move_from_node_id', null)->where('c.customer_key !=', Tokens::LOCAL_CUSTOMER)->get()->getResultArray() as $p) {
            if ($this->ensureTokens((int) $p['id'])) {
                $this->pushProject((int) $p['id']);
            }
        }
    }

    /** Customer change: push to every node that has projects of the customer. */
    public function pushCustomer(int $customerId): ?string
    {
        $errors = [];
        foreach (db_connect()->table('projects')->select('id')->where('customer_id', $customerId)->get()->getResultArray() as $p) {
            if (($e = $this->pushProject((int) $p['id'])) !== null) {
                $errors[] = $e;
            }
        }

        return $errors === [] ? null : implode('; ', array_unique($errors));
    }

    /**
     * Takes over customers and projects that exist on a node but not here (e.g. created on the CLI).
     *
     * @return array{customers: int, projects: int}
     */
    public function import(array $node): array
    {
        $db     = db_connect();
        $client = $this->client($node);
        $stats  = ['customers' => 0, 'projects' => 0];
        $now    = Clock::now();
        foreach ($client->ok('GET', 'customers')['data'] as $c) {
            if ($db->table('customers')->where('customer_key', $c['key'])->countAllResults() === 0) {
                $db->table('customers')->insert(['public_id' => PublicId::generate('customers'), 'customer_key' => $c['key'], 'name' => $c['name'], 'enabled' => $c['enabled'] ? 1 : 0, 'created_at' => $now, 'updated_at' => $now]);
                $stats['customers']++;
            }
        }
        foreach ($client->ok('GET', 'projects')['data'] as $p) {
            $customerId = (int) $db->table('customers')->select('id')->where('customer_key', $p['customer'])->get()->getRow('id');
            if ($db->table('projects')->where('customer_id', $customerId)->where('project_key', $p['project'])->countAllResults() > 0) {
                continue;
            }
            $db->table('projects')->insert([
                'public_id' => PublicId::generate('projects'), 'customer_id' => $customerId, 'node_id' => $node['id'], 'project_key' => $p['project'], 'name' => $p['name'],
                'profile' => $p['profile'], 'enabled' => $p['enabled'] ? 1 : 0, 'routes' => json_encode($p['routes']),
                'settings' => json_encode($p['settings']), 'changed_at' => $now, 'synced_at' => $now, 'node_revision' => $p['revision'], 'created_at' => $now,
            ]);
            $stats['projects']++;
        }

        return $stats;
    }

    /**
     * Automatic updates: if the node runs an older version than this admin, ask it once to update to the admin's
     * version. The node decides (MAILRUNNER_ADMIN_UPDATES auto|manual) and fetches the code from its own git source.
     *
     * @param array<string, mixed> $status
     *
     * @return array<string, mixed>
     */
    private function offerUpdate(array $node, array $status): array
    {
        $mine = \App\Controllers\Enroll::version();
        $its  = (string) ($status['version'] ?? '');
        if ($mine === '' || $its === '' || ! version_compare($its, $mine, '<') || ($status['update_requested'] ?? null) === $mine) {
            return $status;
        }

        return $this->requestUpdate($node, $mine) === null ? ['update_requested' => $mine] + $status : $status;
    }

    /**
     * Pushes the common secrets when the node reports another revision (and has none waiting for approval).
     * Nodes without the endpoint (older versions) report no "secrets" and are skipped until they are updated.
     */
    private function offerSecrets(array $node, array $status): array
    {
        $store = CommonSecrets::make();
        $rev   = $store->revision();
        $its   = $status['secrets'] ?? null;
        if ($rev === null || ! is_array($its) || ($its['revision'] ?? null) === $rev || ($its['pending_revision'] ?? null) === $rev) {
            return $status;
        }
        $err = $this->pushSecrets($node);
        if ($err !== null) {
            $status['secrets']['push_error'] = $err;
        } else {
            $status = $this->client($node)->ok('GET', 'node'); // fresh state (revision or pending_revision)
        }

        return $status;
    }

    /** Sends the full common set to one node; null or the error. */
    public function pushSecrets(array $node): ?string
    {
        $store = CommonSecrets::make();
        try {
            $this->client($node)->ok('PUT', 'node/secrets', ['revision' => (string) $store->revision(), 'values' => (object) $store->values()]);

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /** Asks a node to update to $version; null or the error. */
    public function requestUpdate(array $node, string $version): ?string
    {
        try {
            $this->client($node)->ok('PUT', 'node/update', ['version' => $version]);

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * State of a project on a node (admin API), e.g. the old node during a move. Null if unreachable.
     *
     * @return array<string, mixed>|null
     */
    public function projectOnNode(int $projectId, int $nodeId): ?array
    {
        $db   = db_connect();
        $p    = $db->table('projects p')->select('p.project_key, c.customer_key')->join('customers c', 'c.id = p.customer_id')->where('p.id', $projectId)->get()->getRowArray();
        $node = $db->table('nodes')->where('id', $nodeId)->get()->getRowArray();
        try {
            return $this->client($node)->ok('GET', 'projects/' . $p['customer_key'] . '/' . $p['project_key'])['data'];
        } catch (Throwable) {
            return null;
        }
    }
}
