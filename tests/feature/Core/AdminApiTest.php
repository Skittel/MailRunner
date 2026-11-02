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
        foreach ([['abuse@in.example.org', 'abuse'], ['user0001@in.example.org', null]] as $i => [$rcpt, $role]) {
            db_connect()->table('receive_messages')->insert([
                'message_id' => 'mrm_test' . $i, 'project_id' => $p['id'], 'spool_id' => 's' . $i, 'profile' => 'analysis', 'role' => $role,
                'status' => 'analyzed', 'received_at' => '2026-11-01 23:00:00', 'imported_at' => '2026-11-01 23:00:00', 'queue_id' => 'Q',
                'mail_from' => 'a@example.net', 'rcpt' => $rcpt, 'client_ip' => '192.0.2.1', 'helo' => 'h', 'client_name' => 'n',
                'attachments' => '[]', 'analysis_status' => 'ok', 'size' => 1, 'sha256' => str_repeat('d', 64),
            ]);
        }
        [$s, $b] = $this->api('get', 'admin/v1/role-mails');
        $this->assertSame([200, 1, 'abuse', 'acme/inbox'], [$s, count($b['data']), $b['data'][0]['role'], $b['data'][0]['project']]);
        [$s] = $this->api('get', 'admin/v1/messages/mrm_test1');
        $this->assertSame(404, $s); // not a role mail
    }
}
