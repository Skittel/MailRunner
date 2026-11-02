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
        $p  = $db->table('projects p')->select('p.*, c.customer_key, c.name AS customer_name, c.enabled AS customer_enabled')
            ->join('customers c', 'c.id = p.customer_id')->where('p.id', $projectId)->get()->getRowArray();
        $own  = $nodeId === null || $nodeId === (int) $p['node_id'];
        $node = $db->table('nodes')->where('id', $nodeId ?? $p['node_id'])->get()->getRowArray();
        try {
            $client = $this->client($node);
            $client->ok('PUT', 'customers/' . $p['customer_key'], ['name' => $p['customer_name'], 'enabled' => (bool) $p['customer_enabled']]);
            $res = $client->ok('PUT', 'projects/' . $p['customer_key'] . '/' . $p['project_key'], [
                'profile'  => $p['profile'],
                'name'     => $p['name'],
                'enabled'  => $enabled ?? (bool) $p['enabled'],
                'routes'   => json_decode($p['routes'], true) ?: [],
                'settings' => json_decode($p['settings'], true) ?: [],
            ]);
            if ($own) {
                $db->table('projects')->where('id', $projectId)->update(['synced_at' => Clock::now(), 'sync_error' => null, 'node_revision' => (int) ($res['data']['revision'] ?? 0)]);
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
