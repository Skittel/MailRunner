<?php

namespace Tests\Feature\Core;

use App\Core\Support\ChangeContext;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * A node without MailRunner Admin: everything the admin app does on a node is also possible with the CLI.
 * Runs the real spark commands.
 *
 * @internal
 */
final class CliParityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private function run_(string $cmd): string
    {
        $this->resetStreamFilterBuffer();
        command($cmd);

        return $this->getStreamFilterBuffer();
    }

    public function testCustomerProjectRouteTokenLifecycle(): void
    {
        $this->run_('mr:customer:create acme --name="ACME"');
        $this->run_('mr:project:create acme/inbox --profile=analysis --name="Inbox"');
        $this->run_('mr:route:set acme/inbox in.example.org');
        $this->run_('mr:project:set acme/inbox --journal-scope=unassigned --role-mail=api');
        $out = $this->run_('mr:token:create acme/inbox --label="App"');
        $this->assertStringContainsString('mrt_', $out);
        $this->seeInDatabase('projects', ['project_key' => 'inbox', 'journal_scope' => 'unassigned', 'role_mail' => 'api']);
        $this->seeInDatabase('receive_routes', ['route' => 'in.example.org']);

        $out = $this->run_('mr:customer:set acme --name=ACME-GmbH --enabled=0');
        $this->assertStringContainsString('saved', $out);
        $this->seeInDatabase('customers', ['customer_key' => 'acme', 'name' => 'ACME-GmbH', 'enabled' => 0]);
        $this->seeInDatabase('journal', ['event' => 'customer.updated']);
    }

    public function testAdminManagedCustomerNeedsForce(): void
    {
        service('customerService')->create('acme', 'ACME', new ChangeContext('admin', 'CN=test'));
        $this->assertStringContainsString('MANAGED_BY_ADMIN', $this->run_('mr:customer:set acme --name=X'));
        $this->seeInDatabase('customers', ['customer_key' => 'acme', 'name' => 'ACME']);
        $this->run_('mr:customer:set acme --name=X --force');
        $this->seeInDatabase('customers', ['customer_key' => 'acme', 'name' => 'X']);
    }

    public function testRoleMailListAndShow(): void
    {
        $cli = ChangeContext::cli();
        service('customerService')->create('acme', '', $cli);
        $p = service('projectService')->create('acme/inbox', 'analysis', '', $cli);
        service('customerService')->create('system', '', $cli);
        $st = service('projectService')->create('system/selftest', 'analysis', '', $cli);
        foreach ([['abuse@in.example.org', 'abuse', $p, 'Complaint'], ['postmaster@rx.selftest.test', 'postmaster', $st, 'Selftest']] as $i => [$rcpt, $role, $proj, $subj]) {
            db_connect()->table('receive_messages')->insert([
                'message_id' => 'mrm_cli' . $i, 'project_id' => $proj['id'], 'spool_id' => 's' . $i, 'profile' => 'analysis', 'role' => $role,
                'status' => 'analyzed', 'received_at' => '2026-11-02 09:00:00', 'imported_at' => '2026-11-02 09:00:00', 'queue_id' => 'Q',
                'mail_from' => 'a@example.net', 'rcpt' => $rcpt, 'client_ip' => '192.0.2.1', 'helo' => 'h', 'client_name' => 'n', 'subject' => $subj,
                'attachments' => '[]', 'analysis_status' => 'ok', 'size' => 1, 'sha256' => str_repeat('d', 64),
            ]);
        }
        $out = $this->run_('mr:rolemail:list');
        $this->assertStringContainsString('mrm_cli0', $out);
        $this->assertStringNotContainsString('mrm_cli1', $out); // self tests hidden by default
        $this->assertStringContainsString('mrm_cli1', $this->run_('mr:rolemail:list --selftests'));

        $out = $this->run_('mr:rolemail:show mrm_cli0');
        $this->assertStringContainsString('acme/inbox', $out);
        $this->assertStringContainsString('Complaint', $out);
        $this->assertStringContainsString('not found', $this->run_('mr:rolemail:show mrm_nope'));
    }
}
