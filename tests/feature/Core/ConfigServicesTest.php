<?php

namespace Tests\Feature\Core;

use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;

/**
 * Configuration layer used by CLI and Admin API: customers, projects,
 * routes, API tokens, SMTP credentials, revisions and journal.
 *
 * @internal
 */
final class ConfigServicesTest extends CIUnitTestCase
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
        service('customerService')->create('smartmailflow', 'SmartMailFlow', $this->cli);
        service('projectService')->create('smartmailflow/test', 'analysis', 'Mail test', $this->cli);
    }

    private function expectCode(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected ConfigException ' . $code);
        } catch (ConfigException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());
        }
    }

    public function testDuplicateCustomerAndProjectAreRejected(): void
    {
        $this->expectCode('CUSTOMER_EXISTS', fn () => service('customerService')->create('smartmailflow', '', $this->cli));
        $this->expectCode('PROJECT_EXISTS', fn () => service('projectService')->create('smartmailflow/test', 'checked', '', $this->cli));
        $this->expectCode('INVALID_PROFILE', fn () => service('projectService')->create('smartmailflow/other', 'nope', '', $this->cli));
        $this->expectCode('INVALID_KEY', fn () => service('customerService')->create('Bad_Key', '', $this->cli));
    }

    public function testRoutesAreReplacedNormalizedAndUniquePerNode(): void
    {
        $routes = service('routeService')->set('smartmailflow/test', ['SmartMailFlow.email', 'ip4.smartmailflow.email', 'Info@Bücher.example'], $this->cli);
        $this->assertSame(
            ['info@xn--bcher-kva.example', 'ip4.smartmailflow.email', 'smartmailflow.email'],
            array_column($routes, 'route'),
        );

        $routes = service('routeService')->set('smartmailflow/test', ['smartmailflow.email'], $this->cli);
        $this->assertSame(['smartmailflow.email'], array_column($routes, 'route'));

        service('customerService')->create('other', '', $this->cli);
        service('projectService')->create('other/inbox', 'checked', '', $this->cli);
        $this->expectCode('ROUTE_CONFLICT', fn () => service('routeService')->set('other/inbox', ['smartmailflow.email'], $this->cli));
        $this->expectCode('INVALID_ROUTE', fn () => service('routeService')->set('other/inbox', ['not a domain'], $this->cli));

        $this->assertSame([], service('routeService')->set('smartmailflow/test', [], $this->cli));
    }

    public function testEveryChangeIncrementsRevisionAndIsJournaled(): void
    {
        $this->assertSame(1, (int) service('projectService')->get('smartmailflow/test')['revision']);
        service('routeService')->set('smartmailflow/test', ['smartmailflow.email'], $this->cli);
        service('projectService')->update('smartmailflow/test', ['recipient_pattern' => '^[A-Za-z0-9_-]{8,}$', 'recipient_always_allow' => ['Postmaster', 'abuse']], $this->cli);
        service('apiTokenService')->create('smartmailflow/test', 'web', null, $this->cli);

        $project = service('projectService')->get('smartmailflow/test');
        $this->assertSame(4, (int) $project['revision']);
        $this->assertSame('["postmaster","abuse"]', $project['recipient_always_allow']);

        $events = array_column(db_connect()->table('journal')->orderBy('id')->get()->getResultArray(), 'event');
        $this->assertSame(['customer.created', 'project.created', 'routes.updated', 'project.updated', 'token.created'], $events);
        $ids = array_map('intval', array_column(db_connect()->table('journal')->orderBy('id')->get()->getResultArray(), 'id'));
        $this->assertSame(range(1, 5), $ids, 'journal ids are gap-free');

        $this->expectCode('INVALID_SETTING', fn () => service('projectService')->update('smartmailflow/test', ['recipient_pattern' => '(['], $this->cli));
    }

    public function testSeveralTokensPerProjectWithIdAndSecret(): void
    {
        $a = service('apiTokenService')->create('smartmailflow/test', 'web', null, $this->cli);
        $b = service('apiTokenService')->create('smartmailflow/test', 'worker', null, $this->cli);

        $this->assertMatchesRegularExpression('/^mrt_[a-z0-9]{16}$/', $a['token']['token_id']);
        $this->assertNotSame($a['token']['token_id'], $b['token']['token_id']);
        $this->assertSame($a['token']['token_id'] . '.' . $a['secret'], $a['bearer']);
        $this->assertSame(hash('sha256', $a['secret']), $a['token']['secret_hash']);
        $this->assertCount(2, service('apiTokenService')->list('smartmailflow/test'));

        $this->assertNotNull(service('apiTokenService')->authenticate($a['bearer']));
        $this->assertNull(service('apiTokenService')->authenticate($a['token']['token_id'] . '.' . $b['secret']), 'secret of another token');
        $this->assertNull(service('apiTokenService')->authenticate('garbage'));
    }

    public function testTokenRotationWithGraceAndImmediateRevoke(): void
    {
        $old = service('apiTokenService')->create('smartmailflow/test', 'web', null, $this->cli);

        $rotated = service('apiTokenService')->rotate($old['token']['token_id'], 3600, $this->cli);
        $this->assertSame('web', $rotated['token']['label']);
        $this->assertNotNull($rotated['old']['expires_at']);
        $this->assertNotNull(service('apiTokenService')->authenticate($old['bearer']), 'old token valid during grace');
        $this->assertNotNull(service('apiTokenService')->authenticate($rotated['bearer']));

        $again = service('apiTokenService')->rotate($rotated['token']['token_id'], 0, $this->cli);
        $this->assertNull(service('apiTokenService')->authenticate($rotated['bearer']), 'grace 0 revokes at once');
        $this->assertNotNull(service('apiTokenService')->authenticate($again['bearer']));

        service('apiTokenService')->revoke($again['token']['token_id'], $this->cli);
        $this->assertNull(service('apiTokenService')->authenticate($again['bearer']));
        $this->expectCode('TOKEN_INACTIVE', fn () => service('apiTokenService')->revoke($again['token']['token_id'], $this->cli));
    }

    public function testSmtpCredentialsOnlyForCheckedAuthAndRotationGrace(): void
    {
        $this->expectCode('PROFILE_NO_SMTP_AUTH', fn () => service('smtpCredentialService')->create('smartmailflow/test', 'dev01', '', null, true, null, $this->cli));

        service('customerService')->create('acme', '', $this->cli);
        service('projectService')->create('acme/devices', 'checked_auth', '', $this->cli);
        $svc = service('smtpCredentialService');

        $first  = $svc->create('acme/devices', 'printer01', 'Printer', null, true, ['192.0.2.0/24', '2001:db8::1'], $this->cli);
        $second = $svc->create('acme/devices', 'nas01', 'NAS', null, false, null, $this->cli);
        $this->assertMatchesRegularExpression('/^mrc_[a-z0-9]{16}$/', $first['credential']['credential_id']);
        $this->assertSame('["192.0.2.0\/24","2001:db8::1\/128"]', $first['credential']['allowed_networks']);
        $this->assertSame(0, (int) $second['credential']['tls_required']);
        $this->assertCount(2, $svc->list('acme/devices'));
        $this->expectCode('USERNAME_EXISTS', fn () => $svc->create('acme/devices', 'printer01', '', null, true, null, $this->cli));
        $this->expectCode('INVALID_NETWORK', fn () => $svc->create('acme/devices', 'x-device', '', null, true, ['300.1.1.1/8'], $this->cli));

        $this->assertTrue($svc->verifyPassword('printer01', $first['password']));
        $rotated = $svc->rotate('printer01', 3600, null, $this->cli);
        $this->assertTrue($svc->verifyPassword('printer01', $rotated['password']));
        $this->assertTrue($svc->verifyPassword('printer01', $first['password']), 'old password valid during grace');

        $svc->rotate($first['credential']['credential_id'], 0, null, $this->cli);
        $this->assertFalse($svc->verifyPassword('printer01', $rotated['password']), 'grace 0 invalidates at once');

        $svc->revoke('nas01', $this->cli);
        $this->assertFalse($svc->verifyPassword('nas01', $second['password']));
    }

    public function testAdminManagedProjectNeedsForceFromCli(): void
    {
        db_connect()->table('projects')->update(['managed_by' => 'admin']);

        $this->expectCode('MANAGED_BY_ADMIN', fn () => service('routeService')->set('smartmailflow/test', ['smartmailflow.email'], $this->cli));

        service('routeService')->set('smartmailflow/test', ['smartmailflow.email'], new ChangeContext('cli', 'phpunit', true));
        service('routeService')->set('smartmailflow/test', ['ip4.smartmailflow.email'], new ChangeContext('admin', 'cert:admin-app'));

        $last = db_connect()->table('journal')->where('event', 'routes.updated')->orderBy('id')->get()->getResultArray();
        $this->assertTrue(json_decode($last[0]['details'], true)['forced']);
        $this->assertSame('admin', $last[1]['source']);
    }
}
