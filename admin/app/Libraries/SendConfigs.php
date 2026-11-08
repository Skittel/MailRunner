<?php

namespace App\Libraries;

use Closure;
use Throwable;

/**
 * Central send configuration of send projects (decision E-27): domains with DKIM key, send profiles and accesses.
 *
 * The customer's application changes them over the API of any node of the project. Every node reports the hash of
 * its set. Per node the admin remembers the hash after the last sync:
 *   node hash = central hash                      in sync
 *   node hash != the remembered one               changed on the node -> take the full set over (it becomes central)
 *   otherwise                                     stale -> put the central set there (if_hash: refused if it changed
 *                                                 in between; then the next run takes that change over)
 * Two nodes changed at once: the later change wins (audit "send_config.conflict"). The set incl. DKIM private keys
 * and hashes is stored encrypted (Vault), never shown. Self test projects (system/...) stay local.
 */
class SendConfigs
{
    /** @var Closure(array): object */
    private readonly Closure $client;

    /** @param (callable(array): object)|null $client node row -> client with ok(method, path, body) */
    public function __construct(?callable $client = null, private readonly ?Vault $vault = null)
    {
        $this->client = $client !== null ? Closure::fromCallable($client) : static fn (array $node): object => (new Sync(config('Admin')))->client($node);
    }

    private function vault(): Vault
    {
        return $this->vault ?? Vault::make();
    }

    /** Every send project the node belongs to (its node or the old node of a move). */
    public function reconcileNode(array $node): void
    {
        foreach (db_connect()->table('projects p')->select('p.id')->join('customers c', 'c.id = p.customer_id')->where('p.type', 'send')
            ->where('c.customer_key !=', Tokens::LOCAL_CUSTOMER)->groupStart()->where('p.node_id', $node['id'])->orWhere('p.move_from_node_id', $node['id'])->groupEnd()
            ->get()->getResultArray() as $p) {
            $this->reconcile((int) $p['id']);
        }
    }

    /**
     * Brings the project's nodes and the central copy together. Returns null or the errors (German, for the GUI).
     */
    public function reconcile(int $projectId): ?string
    {
        $db = db_connect();
        $p  = $db->table('projects p')->select('p.id, p.type, p.node_id, p.move_from_node_id, p.project_key, c.customer_key')
            ->join('customers c', 'c.id = p.customer_id')->where('p.id', $projectId)->get()->getRowArray();
        if ($p === null || $p['type'] !== 'send' || $p['customer_key'] === Tokens::LOCAL_CUSTOMER) {
            return null;
        }
        $ref    = $p['customer_key'] . '/' . $p['project_key'];
        $nodes  = [];
        foreach (array_filter([(int) $p['node_id'], (int) ($p['move_from_node_id'] ?? 0)]) as $nid) {
            $nodes[$nid] = $db->table('nodes')->where('id', $nid)->get()->getRowArray();
        }
        $errors = [];
        $state  = [];
        foreach ($nodes as $nid => $node) {
            try {
                $d = ($this->client)($node)->ok('GET', 'projects/' . $ref)['data'];
                if (! is_array($d['send_config'] ?? null)) {
                    throw new \RuntimeException('Node zu alt für die zentrale Versand-Konfiguration (ab Node 0.2.8)');
                }
                $state[$nid] = $d['send_config'];
            } catch (Throwable $e) {
                $errors[$nid] = self::message($e);
            }
        }
        $known   = [];
        foreach ($db->table('send_config_nodes')->where('project_id', $projectId)->get()->getResultArray() as $r) {
            $known[(int) $r['node_id']] = $r;
        }
        $central = $db->table('send_configs')->where('project_id', $projectId)->get()->getRowArray();

        if ($central === null) { // first time: the project's node has the set (also an empty one)
            $primary = (int) $p['node_id'];
            if (! isset($state[$primary])) {
                $this->note($projectId, $errors);

                return $this->summary($errors, $nodes);
            }
            $central = $this->import($projectId, $ref, $nodes[$primary], $errors);
        } else {
            $dirty = array_filter($state, static fn ($s, $nid) => $s['hash'] !== $central['hash'] && isset($known[$nid]['hash']) && $s['hash'] !== $known[$nid]['hash'],
                ARRAY_FILTER_USE_BOTH);
            if ($dirty !== []) {
                uasort($dirty, static fn ($a, $b) => strcmp((string) ($b['changed_at'] ?? ''), (string) ($a['changed_at'] ?? '')));
                $from = array_key_first($dirty);
                if (count($dirty) > 1) {
                    Audit::log('send_config.conflict', $ref, ['taken_from' => $from, 'overwritten' => array_values(array_diff(array_keys($dirty), [$from]))]);
                }
                $central = $this->import($projectId, $ref, $nodes[$from], $errors) ?? $central;
                $state[$from]['hash'] = $central['hash'];
            }
        }
        if ($central === null) {
            $this->note($projectId, $errors);

            return $this->summary($errors, $nodes);
        }

        foreach ($state as $nid => $s) {
            if ($s['hash'] === $central['hash']) {
                $this->mark($projectId, $nid, ['hash' => $s['hash'], 'pending_hash' => null, 'error' => null], ($known[$nid]['hash'] ?? null) !== $s['hash']);

                continue;
            }
            if (($known[$nid]['pending_hash'] ?? null) === $central['hash']) {
                $this->mark($projectId, $nid, ['error' => null], false); // waits for approval on the node

                continue;
            }
            $this->push($projectId, $ref, $nodes[$nid], $central, $s['hash'], $errors);
        }
        $this->note($projectId, $errors);

        return $this->summary($errors, $nodes);
    }

    /**
     * Central state for the GUI: counts, source, per node in sync / pending / error. Never secrets.
     *
     * @return array{central: array<string, mixed>|null, nodes: array<int, array<string, mixed>>}
     */
    public function overview(int $projectId): array
    {
        $db = db_connect();
        $c  = $db->table('send_configs s')->select('s.hash, s.counts, s.imported_at, n.node_key AS source')->join('nodes n', 'n.id = s.source_node_id', 'left')
            ->where('s.project_id', $projectId)->get()->getRowArray();
        $nodes = [];
        foreach ($db->table('send_config_nodes')->where('project_id', $projectId)->get()->getResultArray() as $r) {
            $nodes[(int) $r['node_id']] = $r + ['in_sync' => $c !== null && $r['hash'] === $c['hash'] && $r['error'] === null];
        }

        return ['central' => $c === null ? null : ['counts' => json_decode($c['counts'], true)] + $c, 'nodes' => $nodes];
    }

    /** The decrypted central set (only for a push). @return array<string, mixed> */
    private function set(array $central): array
    {
        return json_decode($this->vault()->decrypt($central['config_enc'], 'send-config:' . $central['project_id']), true);
    }

    /** @param array<int, string> $errors @return array<string, mixed>|null the new central row */
    private function import(int $projectId, string $ref, array $node, array &$errors): ?array
    {
        try {
            $d = ($this->client)($node)->ok('GET', 'projects/' . $ref . '/send-config')['data'];
        } catch (Throwable $e) {
            $errors[(int) $node['id']] = 'Übernahme: ' . self::message($e);

            return null;
        }
        $hash = (string) $d['hash'];
        unset($d['hash'], $d['changed_at']);
        $counts = ['domains' => count($d['domains'] ?? []), 'profiles' => count($d['profiles'] ?? []), 'accesses' => count($d['accesses'] ?? [])];
        $row    = ['project_id' => $projectId, 'hash' => $hash, 'config_enc' => $this->vault()->encrypt(json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'send-config:' . $projectId),
            'counts' => json_encode($counts), 'source_node_id' => (int) $node['id'], 'imported_at' => Clock::now()];
        db_connect()->table('send_configs')->replace($row);
        $this->mark($projectId, (int) $node['id'], ['hash' => $hash, 'pending_hash' => null, 'error' => null], true);
        Audit::log('send_config.imported', $ref, ['node' => $node['node_key']] + $counts);

        return $row;
    }

    /** @param array<string, mixed> $central @param array<int, string> $errors */
    private function push(int $projectId, string $ref, array $node, array $central, string $ifHash, array &$errors): void
    {
        $nid = (int) $node['id'];
        try {
            $res = ($this->client)($node)->ok('PUT', 'projects/' . $ref . '/send-config', $this->set($central) + ['if_hash' => $ifHash]);
        } catch (NodeError $e) {
            if ($e->errorCode === 'SEND_CONFIG_CHANGED') {
                return; // changed on the node right now: the next run takes it over
            }
            $errors[$nid] = self::message($e);

            return;
        } catch (Throwable $e) {
            $errors[$nid] = self::message($e);

            return;
        }
        if (! empty($res['pending'])) {
            $this->mark($projectId, $nid, ['pending_hash' => $central['hash'], 'error' => null], false);
            Audit::log('send_config.queued', $ref, ['node' => $node['node_key']]);

            return;
        }
        $got = (string) ($res['data']['hash'] ?? '');
        $this->mark($projectId, $nid, ['hash' => $got, 'pending_hash' => null, 'error' => null], true);
        if ($got !== $central['hash']) {
            $errors[$nid] = 'Nach der Übertragung weicht die Prüfsumme ab';
        }
        Audit::log('send_config.pushed', $ref, ['node' => $node['node_key'], 'changed' => $res['data']['changed'] ?? null]);
    }

    /** @param array<string, mixed> $set */
    private function mark(int $projectId, int $nodeId, array $set, bool $synced): void
    {
        $db  = db_connect();
        $now = Clock::now();
        $set += ['checked_at' => $now] + ($synced ? ['synced_at' => $now] : []);
        if ($db->table('send_config_nodes')->where('project_id', $projectId)->where('node_id', $nodeId)->countAllResults() === 0) {
            $db->table('send_config_nodes')->insert($set + ['project_id' => $projectId, 'node_id' => $nodeId]);
        } else {
            $db->table('send_config_nodes')->where('project_id', $projectId)->where('node_id', $nodeId)->update($set);
        }
    }

    /** @param array<int, string> $errors */
    private function note(int $projectId, array $errors): void
    {
        foreach ($errors as $nid => $msg) {
            $this->mark($projectId, $nid, ['error' => mb_substr($msg, 0, 500)], false);
        }
    }

    /** @param array<int, string> $errors @param array<int, array<string, mixed>> $nodes */
    private function summary(array $errors, array $nodes): ?string
    {
        if ($errors === []) {
            return null;
        }

        return implode('; ', array_map(static fn ($nid, $m) => ($nodes[$nid]['node_key'] ?? '?') . ': ' . $m, array_keys($errors), $errors));
    }

    private static function message(Throwable $e): string
    {
        return $e instanceof NodeError ? $e->errorCode . ': ' . $e->getMessage() : $e->getMessage();
    }
}
