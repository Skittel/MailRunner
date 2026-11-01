<?php

namespace Tests\Feature\Receive;

use App\Core\Secrets\NodeCrypto;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;
use App\Modules\Receive\Libraries\ConfigBuilder;
use App\Modules\Receive\Libraries\Importer;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;

/**
 * postmaster@/abuse@ (E-18): accepted for every project domain, original kept, noted in the journal,
 * delivery setting per project.
 *
 * @internal
 */
final class RoleMailTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private ChangeContext $cli;

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(true);
        $this->cli = new ChangeContext('cli', 'phpunit');
        service('customerService')->create('acme', '', $this->cli);
        service('projectService')->create('acme/inbound', 'analysis', '', $this->cli);
        service('projectService')->create('acme/single', 'checked', '', $this->cli);
        service('routeService')->set('acme/inbound', ['in.example.org'], $this->cli);
        service('routeService')->set('acme/single', ['only@single.example.org'], $this->cli);
        service('projectService')->update('acme/inbound', ['recipient_pattern' => '^[a-z0-9]{8,}$'], $this->cli);
    }

    public function testRoleAddressesAcceptedForEveryDomain(): void
    {
        $files = (new ConfigBuilder(db_connect()))->build();

        // domain route with pattern: role addresses pass the pattern check without any always-allow setting
        $this->assertStringContainsString('/^(?:postmaster|abuse)@/ DUNNO', $files['recipient_access.pcre']);
        // domain with only an address route: role addresses routed to that project
        $this->assertStringContainsString("postmaster@single.example.org OK\n", $files['mailboxes']);
        $this->assertStringContainsString("abuse@single.example.org OK\n", $files['mailboxes']);
        $routes = json_decode($files['routes.json'], true);
        $this->assertSame('acme/single', $routes['routes']['abuse@single.example.org']['project']);
        $this->assertArrayNotHasKey('postmaster@in.example.org', $routes['routes']); // the domain route covers it
        $this->assertSame(['postmaster', 'abuse'], $routes['role_localparts']);
    }

    public function testImportNotesRoleMailInJournal(): void
    {
        $dir = sys_get_temp_dir() . '/mr-role-' . bin2hex(random_bytes(4));
        mkdir($dir . '/analyzed', 0o700, true);
        mkdir($dir . '/failed', 0o700, true);
        $crypto  = NodeCrypto::fromKey(random_bytes(32));
        $project = service('projectService')->get('acme/inbound');
        $id      = '20261101T230000Z-00000000000000ab';
        $meta    = [
            'spool_id' => $id, 'received_at' => '2026-11-01T23:00:00Z', 'queue_id' => 'Q1', 'client_ip' => '192.0.2.10', 'client_name' => 'mx.example.net',
            'helo' => 'mx.example.net', 'mail_from' => 'reporter@example.net', 'rcpt' => 'abuse@in.example.org', 'project_id' => (int) $project['id'],
            'project' => 'acme/inbound', 'profile' => 'analysis', 'role' => 'abuse', 'size' => 10, 'sha256' => str_repeat('c', 64), 'attachments' => [],
            'subject' => 'Complaint', 'analysis_status' => 'ok', 'analysis_ms' => 5, 'analysis_path' => null, 'raw_path' => '2026/11/01/' . $id . '.eml.enc',
        ];
        file_put_contents($dir . '/analyzed/' . $id . '.json', $crypto->encryptJson($meta, 'spool:' . $id . ':analyzed'));
        (new Importer(db_connect(), service('journalWriter'), $crypto, $dir))->run();
        rmdir($dir . '/analyzed');
        rmdir($dir . '/failed');
        @unlink($dir . '/.import.lock');
        rmdir($dir);

        $this->assertSame('abuse', db_connect()->table('receive_messages')->get()->getRow('role'));
        $event = db_connect()->table('journal')->where('event', 'receive.role_mail')->get()->getRowArray();
        $details = json_decode($event['details'], true);
        $this->assertSame((int) $project['id'], (int) $event['project_id']);
        $this->assertSame(['abuse', 'abuse@in.example.org', true, 'default', false],
            [$details['role'], $details['rcpt'], $details['raw_kept'], $details['delivery'], $details['forwarded']]);
    }

    public function testRoleMailSetting(): void
    {
        $p = service('projectService')->update('acme/inbound', ['role_mail' => 'api'], $this->cli);
        $this->assertSame('api', $p['role_mail']);
        $p = service('projectService')->update('acme/inbound', ['role_mail' => 'forward', 'role_mail_forward_to' => 'Abuse@Example.NET'], $this->cli);
        $this->assertSame(['forward', 'abuse@example.net'], [$p['role_mail'], $p['role_mail_forward_to']]);

        $this->expectException(ConfigException::class);
        service('projectService')->update('acme/single', ['role_mail' => 'forward'], $this->cli); // no address
    }

    public function testInvalidRoleMailValues(): void
    {
        foreach ([['role_mail' => 'mail'], ['role_mail_forward_to' => 'not-an-address']] as $bad) {
            try {
                service('projectService')->update('acme/inbound', $bad, $this->cli);
                $this->fail('accepted ' . json_encode($bad));
            } catch (ConfigException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
