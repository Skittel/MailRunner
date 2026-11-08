<?php

namespace App\Libraries;

use Closure;
use Throwable;

/**
 * Central access configuration (decision E-27, node AccessConfigSync): what the customer's application sets up on a
 * node. Send projects: domains with DKIM key, send profiles and accesses; receive projects: SMTP logins; both: the
 * webhook. Tables send_configs / send_config_nodes keep their names from admin 0.1.31.
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
class AccessConfigs
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

    /**
     * Every project the node belongs to (its node or the old node of a move). One project list call per node: only
     * projects whose hash there differs from what the admin knows are reconciled (a node before 0.2.10: every send
     * project, as before).
     */
    public function reconcileNode(array $node): void
    {
        $db   = db_connect();
        $rows = $db->table('projects p')->select('p.id, p.type, p.project_key, c.customer_key, s.hash AS central, k.hash AS known, k.pending_hash, k.error')
            ->join('customers c', 'c.id = p.customer_id')
            ->join('send_configs s', 's.project_id = p.id', 'left')
            ->join('send_config_nodes k', 'k.project_id = p.id AND k.node_id = ' . (int) $node['id'], 'left')
            ->where('c.customer_key !=', Tokens::LOCAL_CUSTOMER)->groupStart()->where('p.node_id', $node['id'])->orWhere('p.move_from_node_id', $node['id'])->groupEnd()
            ->get()->getResultArray();
        if ($rows === []) {
            return;
        }
        $hashes = null;
        try {
            $hashes = [];
            foreach (($this->client)($node)->ok('GET', 'projects')['data'] ?? [] as $r) {
                if (array_key_exists('access_config_hash', $r)) {
                    $hashes[$r['ref']] = $r['access_config_hash'];
                }
            }
        } catch (Throwable) {
            $hashes = null; // reconcile() notes the error per project
        }
        foreach ($rows as $p) {
            $ref = $p['customer_key'] . '/' . $p['project_key'];
            if ($hashes === null || $hashes === []) { // unreachable or older node
                if ($p['type'] === 'send') {
                    $this->reconcile((int) $p['id']);
                }

                continue;
            }
            if (! array_key_exists($ref, $hashes)) {
                continue; // not on the node (yet): the project push creates it
            }
            $h = $hashes[$ref];
            if ($p['central'] !== null && $h === $p['central'] && $h === $p['known'] && $p['error'] === null) {
                continue; // in sync
            }
            if ($p['central'] !== null && $p['pending_hash'] === $p['central'] && $h === $p['known'] && $p['error'] === null) {
                continue; // waits for approval on the node
            }
            $this->reconcile((int) $p['id']);
        }
    }

    /**
     * Brings the project's nodes and the central copy together. Returns null or the errors (German, for the GUI).
     * $from: the node an admin action just changed; its set is taken over even if this admin has not seen the node yet.
     */
    public function reconcile(int $projectId, ?int $from = null): ?string
    {
        $db = db_connect();
        $p  = $db->table('projects p')->select('p.id, p.type, p.node_id, p.move_from_node_id, p.project_key, c.customer_key')
            ->join('customers c', 'c.id = p.customer_id')->where('p.id', $projectId)->get()->getRowArray();
        if ($p === null || $p['customer_key'] === Tokens::LOCAL_CUSTOMER) {
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
                if (! is_array($d['access_config'] ?? null)) {
                    throw new \RuntimeException('Node zu alt für die zentrale Verteilung der Zugänge (ab Node 0.2.10)');
                }
                $state[$nid] = $d['access_config'];
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
            if ($from !== null && isset($state[$from]) && $state[$from]['hash'] !== $central['hash']) {
                $dirty[$from] = $state[$from];
            }
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
     * Admin lock of a send access (abuse etc.): set on the project's node, which then is taken over as the central set
     * and put on the other nodes. The customer sees the lock and its reason but cannot lift it.
     *
     * @return array{error: string|null, pending: bool} pending: the node waits for approval (manual mode)
     */
    public function lockAccess(int $projectId, string $accessId, bool $locked, string $reason): array
    {
        [$ref, $nodes] = $this->project($projectId);
        if ($ref === null) {
            return ['error' => 'Kein Versandprojekt mit zentraler Konfiguration', 'pending' => false];
        }
        $node = $nodes[0];
        try {
            $res = ($this->client)($node)->ok($locked ? 'PUT' : 'DELETE', 'projects/' . $ref . '/send-accesses/' . rawurlencode($accessId) . '/lock', $locked ? ['reason' => $reason] : null);
        } catch (Throwable $e) {
            return ['error' => $node['node_key'] . ': ' . self::message($e), 'pending' => false];
        }
        $pending = ! empty($res['pending']);
        Audit::log($locked ? 'send.access_locked' : 'send.access_unlocked', $ref, ['access_id' => $accessId, 'node' => $node['node_key'], 'pending' => $pending]
            + ($locked ? ['reason' => $reason] : []));
        if ($pending) { // after approval the node's set differs from the remembered one: the poll takes it over
            return ['error' => null, 'pending' => true];
        }

        return ['error' => $this->reconcile($projectId, (int) $node['id']), 'pending' => false];
    }

    /**
     * Brings the domain test forward on one node of the project (the test runs per node: its own IPs and keys).
     * Returns null or the error.
     */
    public function requestDomainTest(int $projectId, string $domain, int $nodeId): ?string
    {
        [$ref, $nodes] = $this->project($projectId);
        $node = array_values(array_filter($nodes, static fn ($n) => (int) $n['id'] === $nodeId))[0] ?? null;
        if ($ref === null || $node === null) {
            return 'Die Node gehört nicht zu diesem Versandprojekt';
        }
        try {
            ($this->client)($node)->ok('POST', 'projects/' . $ref . '/domains/' . rawurlencode($domain) . '/test');
        } catch (Throwable $e) {
            return $node['node_key'] . ': ' . self::message($e);
        }
        Audit::log('send.domain_test_requested', $ref, ['domain' => $domain, 'node' => $node['node_key']]);

        return null;
    }

    /** @return array{0: string|null, 1: list<array<string, mixed>>} ref of a central send project, its nodes (own node first) */
    private function project(int $projectId): array
    {
        $db = db_connect();
        $p  = $db->table('projects p')->select('p.type, p.node_id, p.move_from_node_id, p.project_key, c.customer_key')
            ->join('customers c', 'c.id = p.customer_id')->where('p.id', $projectId)->get()->getRowArray();
        if ($p === null || $p['type'] !== 'send' || $p['customer_key'] === Tokens::LOCAL_CUSTOMER) {
            return [null, []];
        }
        $nodes = [];
        foreach (array_filter([(int) $p['node_id'], (int) ($p['move_from_node_id'] ?? 0)]) as $nid) {
            $nodes[] = $db->table('nodes')->where('id', $nid)->get()->getRowArray();
        }

        return [$p['customer_key'] . '/' . $p['project_key'], $nodes];
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
            $d = ($this->client)($node)->ok('GET', 'projects/' . $ref . '/access-config')['data'];
        } catch (Throwable $e) {
            $errors[(int) $node['id']] = 'Übernahme: ' . self::message($e);

            return null;
        }
        $hash = (string) $d['hash'];
        unset($d['hash'], $d['changed_at']);
        $counts = self::counts($d);
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
            $res = ($this->client)($node)->ok('PUT', 'projects/' . $ref . '/access-config', $this->set($central) + ['if_hash' => $ifHash]);
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

    /** Counts of a set (never keys, hashes or the webhook URL). @param array<string, mixed> $set @return array<string, int> */
    public static function counts(array $set): array
    {
        $out = [];
        foreach (['domains', 'profiles', 'accesses', 'smtp_credentials'] as $k) {
            if (is_array($set[$k] ?? null)) {
                $out[$k] = count($set[$k]);
            }
        }

        return $out + ['webhook' => is_array($set['webhook'] ?? null) ? 1 : 0];
    }

    private static function message(Throwable $e): string
    {
        return $e instanceof NodeError ? $e->errorCode . ': ' . $e->getMessage() : $e->getMessage();
    }
}
