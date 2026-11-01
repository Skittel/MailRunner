<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Smoke test of the admin GUI: auth gate, every page renders for an administrator, forms validate.
 * Node calls fail in the test environment (no mTLS key) and must be shown as errors, not crash.
 *
 * @internal
 */
final class PagesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private array $admin = ['user_id' => 1, 'user_name' => 'Test Admin', 'user_email' => 'admin@example.org', 'is_admin' => true,
        'csrf_test_name' => 'testtoken0123456789abcdef'];

    /** POST as administrator with the session CSRF token. */
    private function postAs(string $uri, array $data)
    {
        return $this->withSession($this->admin)->post($uri, $data + ['csrf_test_name' => 'testtoken0123456789abcdef']);
    }

    public function testPostWithoutCsrfTokenRejected(): void
    {
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->withSession($this->admin)->post('customers', ['customer_key' => 'evil-corp']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $now = '2026-11-01 22:00:00.000000';
        $status = ['node_id' => 'node1.eu', 'version' => '0.1.0', 'roles' => ['receive'],
            'selftest' => ['status' => 'fail', 'ok' => 39, 'finished_at' => gmdate('Y-m-d\TH:i:s\Z'), 'duration_ms' => 20000, 'version' => '0.1.0',
                'failed' => [['name' => 'smtp.v4.certificate', 'detail' => '9 days left']], 'warnings' => []],
            'counts' => ['messages_24h' => 5, 'messages_unacked' => 2, 'role_mails_unacked' => 1, 'smtp_sessions_24h' => 9]];
        $this->db->table('users')->insert(['entra_oid' => '00000000-0000-0000-0000-000000000001', 'tenant_id' => '00000000-0000-0000-0000-000000000002',
            'name' => 'Test Admin', 'email' => 'admin@example.org', 'created_at' => $now]);
        $this->db->table('nodes')->insert(['node_key' => 'node1.eu', 'name' => 'Node 1', 'base_url' => 'https://node1.invalid', 'enabled' => 1,
            'last_checked_at' => $now, 'last_ok_at' => $now, 'last_status' => json_encode($status), 'created_at' => $now, 'updated_at' => $now]);
        $this->db->table('customers')->insert(['customer_key' => 'acme', 'name' => 'ACME GmbH', 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->db->table('projects')->insert(['customer_id' => 1, 'node_id' => 1, 'project_key' => 'inbox', 'name' => 'Inbox', 'profile' => 'analysis',
            'enabled' => 1, 'routes' => '["in.example.org"]', 'settings' => '{"journal_scope":"own","role_mail":"api"}', 'changed_at' => $now, 'created_at' => $now]);
    }

    public function testGuestIsSentToLogin(): void
    {
        $this->get('/')->assertRedirectTo(site_url('auth/login'));
        $r = $this->withHeaders(['HX-Request' => 'true'])->get('nodes/cards');
        $r->assertStatus(401);
        $r->assertHeader('HX-Redirect', site_url('auth/login'));
    }

    public function testLoginPageWithoutEntraConfig(): void
    {
        $this->get('auth/login')->assertSee('Anmeldung nicht eingerichtet');
    }

    public function testPagesRender(): void
    {
        foreach (['/' => 'Übersicht', 'nodes' => 'node1.eu', 'nodes/1' => 'smtp.v4.certificate', 'nodes/new' => 'Node-ID',
            'customers' => 'ACME GmbH', 'customers/1' => 'Inbox', 'customers/new' => 'Schlüssel', 'customers/1/projects/new' => 'Projekt anlegen',
            'projects/1' => 'in.example.org', 'role-mails' => 'postmaster', 'audit' => 'Protokoll'] as $uri => $text) {
            $r = $this->withSession($this->admin)->get($uri);
            $r->assertStatus(200);
            $r->assertSee($text);
        }
        // health from the stored status: failed self test -> Fehler
        $this->withSession($this->admin)->get('nodes/cards')->assertSee('Fehler');
        // node calls fail here and are shown as errors
        $this->withSession($this->admin)->get('nodes/1/journal')->assertSee('Journal nicht abrufbar');
        $this->withSession($this->admin)->get('role-mails/node/1')->assertSee('Node nicht abrufbar');
    }

    public function testCustomerValidation(): void
    {
        $r = $this->postAs('customers', ['customer_key' => 'A!', 'name' => 'x']);
        $r->assertSee('3-40 Zeichen');
        $r = $this->postAs('customers', ['customer_key' => 'acme', 'name' => 'x']);
        $r->assertSee('gibt es schon');
        $r = $this->postAs('customers', ['customer_key' => 'beta-corp', 'name' => 'Beta']);
        $r->assertRedirect();
        $this->seeInDatabase('customers', ['customer_key' => 'beta-corp']);
        $this->seeInDatabase('audit_log', ['action' => 'customer.created', 'object' => 'beta-corp']);
    }

    public function testProjectFormValidationAndSyncErrorShown(): void
    {
        $r = $this->postAs('customers/1/projects', ['project_key' => 'x', 'node_id' => 99, 'routes' => 'not a domain']);
        $r->assertSee('Ungültige Route');
        $r->assertSee('Bitte eine Node wählen');
        $r = $this->postAs('customers/1/projects', ['project_key' => 'support', 'node_id' => 1, 'profile' => 'analysis',
            'routes' => "support.example.org\nPostmaster@Other.example.org", 'role_mail' => 'forward']);
        $r->assertSee('gültige Adresse');
        $r = $this->postAs('customers/1/projects', ['project_key' => 'support', 'node_id' => 1, 'profile' => 'analysis',
            'routes' => 'support.example.org', 'journal_scope' => 'connections']);
        $r->assertRedirect();
        $row = $this->db->table('projects')->where('project_key', 'support')->get()->getRowArray();
        $this->assertSame('connections', json_decode($row['settings'], true)['journal_scope']);
        $this->assertNotNull($row['sync_error']); // node unreachable in tests
    }
}
