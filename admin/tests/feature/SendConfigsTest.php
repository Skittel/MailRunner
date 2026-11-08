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
        if (preg_match('~/send-accesses/(mra_\w+)/lock$~', $path, $m) === 1) { // admin lock, like SendAccessService::lock
            if ($this->manual) {
                return ['pending' => true];
            }
            foreach ($this->set['accesses'] as $i => $a) {
                if ($a['access_id'] === $m[1]) {
                    unset($this->set['accesses'][$i]['locked']);
                    if ($method === 'PUT') {
                        $this->set['accesses'][$i]['locked'] = ['at' => '2026-11-08T13:00:00.000000Z', 'reason' => $body['reason']];
                    }
                    $this->changedAt = '2026-11-08T13:00:00.000000Z';

                    return ['data' => ['access_id' => $m[1], 'locked' => $method === 'PUT']];
                }
            }
            throw new NodeError('ACCESS_NOT_FOUND', 'unknown', 404);
        }
        if (str_ends_with($path, '/test')) { // domain test: also in manual mode
            return ['data' => ['test' => ['requested' => true]]];
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

    public function testLockOnTheProjectsNodeIsDistributedAndManualNodesWaitForApproval(): void
    {
        $this->startMove(); // node2 own node, node1 old node
        $this->nodes[2]->set = $this->nodes[1]->set;
        $this->configs()->reconcile(1);

        $r = $this->configs()->lockAccess(1, 'mra_aaaaaaaaaaaaaaaa', true, 'Spam reports');
        $this->assertSame(['error' => null, 'pending' => false], $r);
        $this->assertSame('Spam reports', $this->nodes[1]->set['accesses'][0]['locked']['reason'], 'put on the other node at once');
        $this->assertSame(2, (int) $this->db->table('send_configs')->select('source_node_id')->get()->getRow('source_node_id'));
        $audit = $this->db->table('audit_log')->where('action', 'send.access_locked')->get()->getRowArray();
        $this->assertSame('acme/mailer', $audit['object']);
        $this->assertStringContainsString('Spam reports', $audit['details']);

        $this->assertSame(['error' => null, 'pending' => false], $this->configs()->lockAccess(1, 'mra_aaaaaaaaaaaaaaaa', false, ''));
        $this->assertArrayNotHasKey('locked', $this->nodes[1]->set['accesses'][0]);
        $this->assertStringContainsString('ACCESS_NOT_FOUND', (string) $this->configs()->lockAccess(1, 'mra_bbbbbbbbbbbbbbbb', true, 'x')['error']);

        // the own node in manual mode: waits; after approval the poll takes it over and distributes it
        $this->nodes[2]->manual = true;
        $this->assertSame(['error' => null, 'pending' => true], $this->configs()->lockAccess(1, 'mra_aaaaaaaaaaaaaaaa', true, 'Abuse'));
        $this->assertArrayNotHasKey('locked', $this->nodes[1]->set['accesses'][0]);
        $this->nodes[2]->set['accesses'][0]['locked'] = ['at' => '2026-11-08T14:00:00.000000Z', 'reason' => 'Abuse'];
        $this->nodes[2]->changedAt = '2026-11-08T14:00:00.000000Z';
        $this->configs()->reconcile(1);
        $this->assertSame('Abuse', $this->nodes[1]->set['accesses'][0]['locked']['reason']);
    }

    public function testAnActionOnANodeTheAdminHasNotSeenIsTakenOverNotOverwritten(): void
    {
        $this->configs()->reconcile(1);
        $this->startMove();
        $this->nodes[2]->set = $this->nodes[1]->set; // came with the move, never reconciled by the admin
        $this->db->table('send_config_nodes')->where('node_id', 2)->delete();
        $this->configs()->lockAccess(1, 'mra_aaaaaaaaaaaaaaaa', true, 'Abuse');
        $this->assertSame('Abuse', $this->nodes[2]->set['accesses'][0]['locked']['reason']);
        $this->assertSame('Abuse', $this->nodes[1]->set['accesses'][0]['locked']['reason']);
    }

    public function testDomainTestOnlyOnTheProjectsNodes(): void
    {
        $this->assertNull($this->configs()->requestDomainTest(1, 'shop.example', 1));
        $this->assertContains('POST projects/acme/mailer/domains/shop.example/test', $this->nodes[1]->calls);
        $this->assertNotNull($this->configs()->requestDomainTest(1, 'shop.example', 2), 'node2 is not the project\'s node');
        $this->assertSame([], $this->nodes[2]->calls);
        $this->assertSame(1, $this->db->table('audit_log')->where('action', 'send.domain_test_requested')->countAllResults());
        $this->nodes[1]->down = true;
        $this->assertStringContainsString('node1.eu: connection refused', (string) $this->configs()->requestDomainTest(1, 'shop.example', 1));
    }

    public function testCardShowsLockAndActionButtonsOnlyForWriters(): void
    {
        helper('admin');
        $data = ['domains' => [['domain' => 'shop.example', 'allowed' => true, 'dkim' => ['selector' => 's1', 'algorithm' => 'ed25519', 'bits' => 0], 'test' => ['state' => 'passed', 'requested' => false]]],
            'send_profiles' => [['name' => 'shop', 'label' => '', 'enabled' => true, 'limit_hour' => 1, 'limit_day' => 1, 'retry' => ['attempts' => 1, 'max_minutes' => 60],
                'test_interval_hours' => 24, 'senders' => ['shop.example'], 'recipients' => null, 'return_path_domain' => null, 'access_list' => [
                    ['access_id' => 'mra_aaaaaaaaaaaaaaaa', 'type' => 'api', 'token_id' => 'mrt_x', 'label' => '', 'enabled' => true, 'locked' => true, 'locked_at' => '2026-11-08T13:00:00.000000Z', 'locked_reason' => 'Spam <b>reports</b>'],
                    ['access_id' => 'mra_bbbbbbbbbbbbbbbb', 'type' => 'ip', 'networks' => ['192.0.2.8/29'], 'label' => '', 'enabled' => true, 'locked' => false, 'locked_at' => null, 'locked_reason' => null]]]]];
        $render = fn () => view('projects/_send', ['project' => ['public_id' => 'pr0j-0002'], 'central' => ['central' => null, 'nodes' => []],
            'nodes' => [['id' => 1, 'label' => 'Node', 'node_key' => 'node1.eu', 'data' => $data]], 'notice' => ['ok', 'Zugang gesperrt']]);

        session()->set('access', 'rw');
        $html = $render();
        $this->assertStringContainsString('vom Betreiber gesperrt', $html);
        $this->assertStringContainsString('Spam &lt;b&gt;reports&lt;/b&gt;', $html);
        $this->assertStringContainsString('/projects/pr0j-0002/send/accesses/mra_aaaaaaaaaaaaaaaa/unlock', $html);
        $this->assertStringContainsString('/projects/pr0j-0002/send/accesses/mra_bbbbbbbbbbbbbbbb/lock', $html);
        $this->assertStringContainsString('/projects/pr0j-0002/send/domains/shop.example/test', $html);
        $this->assertStringContainsString('Zugang gesperrt', $html);

        session()->set('access', 'ro');
        $html = $render();
        $this->assertStringContainsString('vom Betreiber gesperrt', $html);
        $this->assertStringNotContainsString('hx-post', $html);
    }
}
