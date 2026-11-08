<?php

namespace Tests\Feature;

use App\Libraries\NodeError;
use App\Libraries\SendConfigs;
use App\Libraries\Vault;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/** A node's send configuration as the node admin API shows it (hash without private keys, like SendConfigSync). */
final class FakeSendNode
{
    public array $set     = ['domains' => [], 'profiles' => [], 'accesses' => []];
    public string $changedAt = '2026-11-08T10:00:00.000000Z';
    public bool $manual   = false;
    public bool $down     = false;
    public array $calls   = [];

    public function hash(): string
    {
        $s = $this->set;
        foreach ($s['domains'] as $i => $d) {
            unset($s['domains'][$i]['private_key']);
        }

        return hash('sha256', json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function ok(string $method, string $path, ?array $body = null): array
    {
        $this->calls[] = $method . ' ' . $path;
        if ($this->down) {
            throw new \RuntimeException('connection refused');
        }
        if ($method === 'GET') {
            return str_ends_with($path, '/send-config') ? ['data' => $this->set + ['hash' => $this->hash(), 'changed_at' => $this->changedAt]]
                : ['data' => ['send_config' => ['hash' => $this->hash(), 'changed_at' => $this->changedAt]]];
        }
        if ($this->manual) {
            return ['pending' => true];
        }
        if ($body['if_hash'] !== $this->hash()) {
            throw new NodeError('SEND_CONFIG_CHANGED', 'changed', 409);
        }
        unset($body['if_hash']);
        $this->set = $body;

        return ['data' => ['hash' => $this->hash(), 'changed' => ['domains' => 1]]];
    }

    public function puts(): int
    {
        return count(array_filter($this->calls, static fn ($c) => str_starts_with($c, 'PUT')));
    }
}

/**
 * Central send configuration: take over, put on every node, local changes, conflicts, manual nodes, errors.
 *
 * @internal
 */
final class SendConfigsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    /** @var array<int, FakeSendNode> */
    private array $nodes = [];
    private Vault $vault;
    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();
        $now = '2026-11-08 10:00:00.000000';
        foreach ([1, 2] as $i) {
            $this->db->table('nodes')->insert(['public_id' => 'n0de-000' . $i, 'node_key' => 'node' . $i . '.eu', 'name' => 'Node ' . $i, 'base_url' => 'https://node' . $i . '.invalid',
                'enabled' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $this->nodes[$i] = new FakeSendNode();
        }
        $this->db->table('customers')->insert(['public_id' => 'cu5t-0001', 'customer_key' => 'acme', 'name' => 'ACME', 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->db->table('projects')->insert(['public_id' => 'pr0j-0002', 'customer_id' => 1, 'node_id' => 1, 'project_key' => 'mailer', 'name' => 'Mailer', 'type' => 'send',
            'profile' => 'smarthost', 'enabled' => 1, 'routes' => '[]', 'settings' => '{}', 'changed_at' => $now, 'created_at' => $now]);
        $this->keyFile = sys_get_temp_dir() . '/mra-key-' . bin2hex(random_bytes(4));
        file_put_contents($this->keyFile, base64_encode(random_bytes(32)));
        $this->vault = new Vault($this->keyFile);
        $this->nodes[1]->set = ['domains' => [['domain' => 'shop.example', 'selector' => 's1', 'algorithm' => 'ed25519', 'bits' => 0, 'key_sha256' => str_repeat('a', 64),
            'private_key' => 'SECRET-DKIM-KEY-1']], 'profiles' => [['name' => 'shop', 'limit_hour' => 50]],
            'accesses' => [['access_id' => 'mra_aaaaaaaaaaaaaaaa', 'type' => 'smtp', 'smtp' => ['password_hash' => '$argon2id$SECRET-HASH']]]];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink($this->keyFile);
    }

    private function configs(): SendConfigs
    {
        return new SendConfigs(fn (array $node) => $this->nodes[(int) $node['id']], $this->vault);
    }

    private function startMove(): void
    {
        $this->db->table('projects')->where('id', 1)->update(['node_id' => 2, 'move_from_node_id' => 1]);
    }

    public function testTakeOverAndPutOnTheNewNodeWithoutClearSecrets(): void
    {
        $this->assertNull($this->configs()->reconcile(1));
        $row = $this->db->table('send_configs')->where('project_id', 1)->get()->getRowArray();
        $this->assertSame($this->nodes[1]->hash(), $row['hash']);
        $this->assertStringNotContainsString('SECRET', $row['config_enc'], 'stored encrypted');
        $this->assertSame(['domains' => 1, 'profiles' => 1, 'accesses' => 1], json_decode($row['counts'], true));

        $this->startMove();
        $this->assertNull($this->configs()->reconcile(1));
        $this->assertSame($this->nodes[1]->set, $this->nodes[2]->set, 'new node got the full set incl. key');
        $this->assertSame(1, $this->nodes[2]->puts());
        $this->assertNull($this->configs()->reconcile(1));
        $this->assertSame(1, $this->nodes[2]->puts(), 'in sync: nothing pushed again');
        $o = $this->configs()->overview(1);
        $this->assertTrue($o['nodes'][1]['in_sync'] && $o['nodes'][2]['in_sync']);
        $this->assertStringNotContainsString('SECRET', (string) $this->db->table('audit_log')->select('GROUP_CONCAT(details) AS d')->get()->getRow('d'));

        // the card shows it (without secrets)
        helper('admin');
        $html = view('projects/_send', ['project' => ['public_id' => 'pr0j-0002'], 'central' => $o, 'nodes' => [
            ['id' => 2, 'label' => 'Node', 'node_key' => 'node2.eu', 'data' => ['domains' => [], 'send_profiles' => []]],
            ['id' => 1, 'label' => 'alte Node', 'node_key' => 'node1.eu', 'data' => null]]]);
        $this->assertStringContainsString('1 Domains, 1 Profile, 1 Zugänge', $html);
        $this->assertStringContainsString('synchron', $html);
        $this->assertStringNotContainsString('SECRET', $html);
    }

    public function testLocalChangeIsTakenOverAndConflictsGoToTheLaterChange(): void
    {
        $this->startMove();
        $this->db->table('projects')->where('id', 1)->update(['node_id' => 1, 'move_from_node_id' => 2]); // node1 primary, node2 second
        $this->configs()->reconcile(1);
        $this->assertSame($this->nodes[1]->set, $this->nodes[2]->set);

        // the customer's app changes a profile on node2: taken over, put on node1
        $this->nodes[2]->set['profiles'][0]['limit_hour'] = 40;
        $this->nodes[2]->changedAt = '2026-11-08T11:00:00.000000Z';
        $this->assertNull($this->configs()->reconcile(1));
        $this->assertSame(40, $this->nodes[1]->set['profiles'][0]['limit_hour']);
        $this->assertSame(2, (int) $this->db->table('send_configs')->select('source_node_id')->where('project_id', 1)->get()->getRow('source_node_id'));

        // both change at once: the later one wins, noted as conflict
        $this->nodes[1]->set['profiles'][0]['limit_hour'] = 30;
        $this->nodes[1]->changedAt = '2026-11-08T12:00:00.000000Z';
        $this->nodes[2]->set['profiles'][0]['limit_hour'] = 20;
        $this->nodes[2]->changedAt = '2026-11-08T12:05:00.000000Z';
        $this->configs()->reconcile(1);
        $this->assertSame(20, $this->nodes[1]->set['profiles'][0]['limit_hour']);
        $this->assertSame(1, $this->db->table('audit_log')->where('action', 'send_config.conflict')->countAllResults());
    }

    public function testManualNodeIsAskedOnceAndUnreachableNodesAreShown(): void
    {
        $this->configs()->reconcile(1);
        $this->startMove();
        $this->nodes[2]->manual = true;
        $this->configs()->reconcile(1);
        $this->configs()->reconcile(1);
        $this->assertSame(1, $this->nodes[2]->puts(), 'queued once, not every minute');
        $st = $this->configs()->overview(1)['nodes'][2];
        $this->assertNotNull($st['pending_hash']);
        $this->assertFalse($st['in_sync']);

        // approved on the node (it now has the set): in sync
        $this->nodes[2]->set = $this->nodes[1]->set;
        $this->configs()->reconcile(1);
        $this->assertTrue($this->configs()->overview(1)['nodes'][2]['in_sync']);

        $this->nodes[1]->down = true;
        $err = $this->configs()->reconcile(1);
        $this->assertStringContainsString('node1.eu: connection refused', (string) $err);
        $this->assertSame('connection refused', $this->configs()->overview(1)['nodes'][1]['error']);
    }

    public function testFirstTakeOverNeedsTheProjectsNodeAndSelfTestProjectsStayLocal(): void
    {
        $this->nodes[1]->down = true;
        $this->assertNotNull($this->configs()->reconcile(1));
        $this->assertSame(0, $this->db->table('send_configs')->countAllResults());

        $this->db->table('customers')->where('id', 1)->update(['customer_key' => 'system']);
        $this->nodes[1]->down = false;
        $calls = count($this->nodes[1]->calls);
        $this->assertNull($this->configs()->reconcile(1));
        $this->assertCount($calls, $this->nodes[1]->calls, 'no call for system projects');
    }
}
