<?php

namespace Tests\Feature\Core;

use App\Core\Support\ChangeContext;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Node admin API (/admin/v1, mTLS verified by nginx -> MR_CLIENT_VERIFY / MR_CLIENT_S_DN).
 *
 * @internal
 */
final class AdminApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(true);
        config('Mailrunner')->receiveEnabled = true;
        config('Mailrunner')->dataDir        = sys_get_temp_dir() . '/mr-admin-none'; // no apply.request
        $_SERVER['MR_CLIENT_VERIFY'] = 'SUCCESS';
        $_SERVER['MR_CLIENT_S_DN']   = 'CN=mailrunner-admin,O=MailRunner Admin';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['MR_CLIENT_VERIFY'], $_SERVER['MR_CLIENT_S_DN']);
        parent::tearDown();
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function api(string $method, string $uri, ?array $body = null): array
    {
        $req = $this->withHeaders(['X-MailRunner-Actor' => 'admin@example.org']);
        if ($body !== null) {
            $req = $req->withBodyFormat('json');
        }
        $r = $req->call($method, $uri, $body ?? []);

        return [$r->response()->getStatusCode(), json_decode($r->response()->getBody(), true) ?? []];
    }

    public function testWithoutCertificateForbidden(): void
    {
        $_SERVER['MR_CLIENT_VERIFY'] = 'NONE';
        [$status, $body] = $this->api('get', 'admin/v1/node');
        $this->assertSame(403, $status);
        $this->assertSame('ADMIN_CERT_REQUIRED', $body['error']['code']);
    }

    public function testCustomerAndProjectLifecycle(): void
    {
        [$s, $b] = $this->api('put', 'admin/v1/customers/acme', ['name' => 'ACME GmbH']);
        $this->assertSame([201, true, 'admin'], [$s, $b['created'], $b['data']['managed_by']]);
        [$s] = $this->api('put', 'admin/v1/customers/acme', ['name' => 'ACME GmbH']);
        $this->assertSame(200, $s); // idempotent

        $config = ['profile' => 'analysis', 'name' => 'Inbound', 'routes' => ['In.Example.org'],
            'settings' => ['recipient_pattern' => '^[a-z0-9]{8,}$', 'journal_scope' => 'connections', 'role_mail' => 'api']];
        [$s, $b] = $this->api('put', 'admin/v1/projects/acme/inbound', $config);
        $this->assertSame(201, $s);
        $this->assertSame(['in.example.org'], $b['data']['routes']);
        $this->assertSame('connections', $b['data']['settings']['journal_scope']);
        $rev = $b['data']['revision'];

        [$s, $b] = $this->api('put', 'admin/v1/projects/acme/inbound', $config);
        $this->assertSame([200, false], [$s, $b['created']]);
        $this->assertSame($rev, $b['data']['revision']); // same desired state: no new revision
        $updates = $this->db->table('journal')->where('event', 'project.updated')->countAllResults();
        $config['settings']['journal_scope'] = 'unassigned';
        [$s, $b] = $this->api('put', 'admin/v1/projects/acme/inbound', $config);
        $this->assertSame('unassigned', $b['data']['settings']['journal_scope']);
        $last = $this->db->table('journal')->where('event', 'project.updated')->orderBy('id', 'DESC')->get(1)->getRowArray();
        $this->assertSame(['journal_scope'], json_decode($last['details'], true)['changed']);
        $this->assertSame($updates + 1, $this->db->table('journal')->where('event', 'project.updated')->countAllResults());

        [$s, $b] = $this->api('put', 'admin/v1/projects/acme/inbound', ['profile' => 'checked']);
        $this->assertSame([422, 'PROFILE_IMMUTABLE'], [$s, $b['error']['code']]);
        [$s, $b] = $this->api('put', 'admin/v1/projects/acme/inbound', ['settings' => ['nope' => 1]]);
        $this->assertSame(422, $s);
        [$s, $b] = $this->api('put', 'admin/v1/projects/nobody/inbox', ['profile' => 'analysis']);
        $this->assertSame([404, 'CUSTOMER_NOT_FOUND'], [$s, $b['error']['code']]);

        [$s, $b] = $this->api('post', 'admin/v1/projects/acme/inbound/tokens', ['label' => 'App', 'expires_days' => 30]);
        $this->assertSame(201, $s);
        $this->assertStringStartsWith($b['data']['token_id'] . '.', $b['data']['bearer']);
        [$s, $b2] = $this->api('get', 'admin/v1/projects/acme/inbound');
        $this->assertSame($b['data']['token_id'], $b2['data']['tokens'][0]['token_id']);
        $this->assertArrayNotHasKey('bearer', $b2['data']['tokens'][0]);
        $this->assertSame(0, $b2['data']['messages_unacked']);
        [$s] = $this->api('delete', 'admin/v1/tokens/' . $b['data']['token_id']);
        $this->assertSame(200, $s);

        // journal actor = certificate subject + GUI user
        $actor = db_connect()->table('journal')->where('event', 'project.created')->get()->getRow('actor');
        $this->assertSame('CN=mailrunner-admin,O=MailRunner Admin | admin@example.org', $actor);

        [$s, $b] = $this->api('get', 'admin/v1/journal?event=project.created');
        $this->assertSame('acme/inbound', $b['data'][0]['project']);
        [$s, $b] = $this->api('get', 'admin/v1/node');
        $this->assertSame([200, 1, 1], [$s, $b['counts']['customers'], $b['counts']['projects']]);
        $this->assertGreaterThan(0, $rev);
    }

    public function testAdminTakesOverCliProject(): void
    {
        $cli = new ChangeContext('cli', 'phpunit');
        service('customerService')->create('acme', '', $cli);
        service('projectService')->create('acme/old', 'analysis', '', $cli);
        [$s, $b] = $this->api('put', 'admin/v1/projects/acme/old', ['settings' => ['unacked_days' => 7]]);
        $this->assertSame([200, 'admin', 7], [$s, $b['data']['managed_by'], $b['data']['settings']['unacked_days']]);
    }

    public function testRoleMailsListed(): void
    {
        $cli = new ChangeContext('cli', 'phpunit');
        service('customerService')->create('acme', '', $cli);
        $p = service('projectService')->create('acme/inbox', 'analysis', '', $cli);
        service('customerService')->create('system', '', $cli);
        $st = service('projectService')->create('system/selftest', 'analysis', '', $cli);
        foreach ([['abuse@in.example.org', 'abuse'], ['user0001@in.example.org', null], ['postmaster@rx.selftest.test', 'postmaster']] as $i => [$rcpt, $role]) {
            db_connect()->table('receive_messages')->insert([
                'message_id' => 'mrm_test' . $i, 'project_id' => $i === 2 ? $st['id'] : $p['id'], 'spool_id' => 's' . $i, 'profile' => 'analysis', 'role' => $role,
                'status' => 'analyzed', 'received_at' => '2026-11-01 23:00:00', 'imported_at' => '2026-11-01 23:00:00', 'queue_id' => 'Q',
                'mail_from' => 'a@example.net', 'rcpt' => $rcpt, 'client_ip' => '192.0.2.1', 'helo' => 'h', 'client_name' => 'n',
                'attachments' => '[]', 'analysis_status' => 'ok', 'size' => 1, 'sha256' => str_repeat('d', 64),
            ]);
        }
        [$s, $b] = $this->api('get', 'admin/v1/role-mails');
        $this->assertSame([200, 2], [$s, count($b['data'])]);
        [$s, $b] = $this->api('get', 'admin/v1/role-mails?selftests=0');
        $this->assertSame([200, 1, 'abuse', 'acme/inbox'], [$s, count($b['data']), $b['data'][0]['role'], $b['data'][0]['project']]);
        [$s] = $this->api('get', 'admin/v1/messages/mrm_test1');
        $this->assertSame(404, $s); // not a role mail
    }

    public function testManualConfigQueuesUntilApprovedOnTheNode(): void
    {
        config('Mailrunner')->adminConfig = 'manual';
        [$s, $b] = $this->api('put', 'admin/v1/customers/acme', ['name' => 'ACME']);
        $this->assertSame([202, true], [$s, $b['pending']]);
        $this->dontSeeInDatabase('customers', ['customer_key' => 'acme']);
        [$s] = $this->api('put', 'admin/v1/customers/acme', ['name' => 'ACME GmbH']); // replaces the open change
        $this->assertSame(202, $s);
        $this->assertSame(1, $this->db->table('admin_pending_changes')->where('decided_at', null)->countAllResults());
        [$s, $b] = $this->api('put', 'admin/v1/projects/acme/inbox', ['profile' => 'analysis', 'routes' => ['in.example.org'], 'settings' => ['journal_scope' => 'connections']]);
        $this->assertSame(202, $s);
        [$s, $b] = $this->api('post', 'admin/v1/projects/acme/inbox/tokens', ['label' => 'x']);
        $this->assertSame([409, 'CONFIG_MANUAL'], [$s, $b['error']['code']]);
        [$s, $b] = $this->api('get', 'admin/v1/node');
        $this->assertSame(['manual', 2], [$b['policy']['config'], $b['pending_changes']]);
        $this->seeInDatabase('journal', ['event' => 'admin.change_queued']);

        // preview shows what would change, then the CLI applies everything
        $preview = (new \App\Core\Admin\AdminChanges())->preview('project', 'acme/inbox', ['profile' => 'analysis', 'routes' => ['in.example.org'], 'settings' => ['journal_scope' => 'connections']]);
        $this->assertSame([null, ['in.example.org']], $preview['routes']);
        command('mr:config:apply --silent');
        $this->seeInDatabase('customers', ['customer_key' => 'acme', 'name' => 'ACME GmbH', 'managed_by' => 'admin']);
        $this->seeInDatabase('projects', ['project_key' => 'inbox', 'journal_scope' => 'connections']);
        $this->seeInDatabase('receive_routes', ['route' => 'in.example.org']);
        $this->assertSame(0, $this->db->table('admin_pending_changes')->where('decided_at', null)->countAllResults());
        $this->seeInDatabase('admin_pending_changes', ['decision' => 'applied']);
    }

    public function testUpdateRequestIsStoredForTheNode(): void
    {
        $dir = sys_get_temp_dir() . '/mr-update-' . bin2hex(random_bytes(4));
        config('Mailrunner')->dataDir = $dir;
        [$s, $b] = $this->api('put', 'admin/v1/node/update', ['version' => 'x']);
        $this->assertSame([422, 'INVALID_VERSION'], [$s, $b['error']['code']]);
        [$s, $b] = $this->api('put', 'admin/v1/node/update', ['version' => '0.0.1']);
        $this->assertSame([422, 'NOT_NEWER'], [$s, $b['error']['code']]);
        [$s, $b] = $this->api('put', 'admin/v1/node/update', ['version' => '99.0.0']);
        $this->assertSame([202, '99.0.0', 'auto'], [$s, $b['requested'], $b['mode']]);
        $this->assertSame('99.0.0', json_decode((string) file_get_contents($dir . '/update/desired.json'), true)['version']);
        [$s, $b] = $this->api('get', 'admin/v1/node');
        $this->assertSame(['99.0.0', 'auto'], [$b['update_requested'], $b['policy']['updates']]);
        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function testCommonSecretsAutoAndManual(): void
    {
        $dir = sys_get_temp_dir() . '/mr-secrets-' . bin2hex(random_bytes(4));
        mkdir($dir . '/config', 0o700, true);
        file_put_contents($dir . '/config/node.key', base64_encode(str_repeat("\x02", 32)));
        file_put_contents($dir . '/config/secrets.env', "SPAMHAUS_DQS_KEY=localkey1234567\nBLM_USER=\n");
        config('Mailrunner')->dataDir   = $dir . '/data';
        config('Mailrunner')->configDir = $dir . '/config';
        $file = $dir . '/data/secrets/common.env';

        [$s, $b] = $this->api('put', 'admin/v1/node/secrets', ['revision' => 'ab12-cd34', 'values' => ['EVIL' => 'x']]);
        $this->assertSame([422, 'INVALID_SECRETS'], [$s, $b['error']['code']]);
        [$s, $b] = $this->api('put', 'admin/v1/node/secrets', ['revision' => 'ab12-cd34', 'values' => ['SPAMHAUS_DQS_KEY' => 'central-dqs-0001', 'BLM_USER' => 'central-user']]);
        $this->assertSame([200, ['SPAMHAUS_DQS_KEY', 'BLM_USER']], [$s, $b['keys']]);
        $this->assertStringContainsString('BLM_USER=central-user', (string) file_get_contents($file));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        [$s, $b] = $this->api('get', 'admin/v1/node');
        $this->assertSame(['ab12-cd34', ['SPAMHAUS_DQS_KEY'], null], [$b['secrets']['revision'], $b['secrets']['local_overrides'], $b['secrets']['pending_revision']]);
        $this->assertStringNotContainsString('central-dqs', json_encode($b)); // never values
        $journal = $this->db->table('journal')->where('event', 'node.secrets_updated')->get()->getRowArray();
        $this->assertStringNotContainsString('central', (string) $journal['details']);

        // manual: stored encrypted, preview shows names only, mr:config:apply writes the file
        config('Mailrunner')->adminConfig = 'manual';
        [$s] = $this->api('put', 'admin/v1/node/secrets', ['revision' => 'ef56-gh78', 'values' => ['SPAMHAUS_DQS_KEY' => 'central-dqs-0002']]);
        $this->assertSame(202, $s);
        $row = $this->db->table('admin_pending_changes')->where('kind', 'secrets')->get()->getRowArray();
        $this->assertStringNotContainsString('central-dqs-0002', $row['payload']);
        $preview = (new \App\Core\Admin\AdminChanges())->preview('secrets', 'common', json_decode($row['payload'], true));
        $this->assertEquals(['SPAMHAUS_DQS_KEY' => ['set', 'changed'], 'BLM_USER' => ['set', 'removed']], $preview);
        [$s, $b] = $this->api('get', 'admin/v1/node');
        $this->assertSame(['ab12-cd34', 'ef56-gh78'], [$b['secrets']['revision'], $b['secrets']['pending_revision']]);
        command('mr:config:apply --silent');
        $this->assertStringContainsString('SPAMHAUS_DQS_KEY=central-dqs-0002', (string) file_get_contents($file));
        $this->assertStringNotContainsString('BLM_USER', (string) file_get_contents($file));
        exec('rm -rf ' . escapeshellarg($dir));
    }
}
