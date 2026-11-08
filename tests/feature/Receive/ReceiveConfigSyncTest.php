<?php

namespace Tests\Feature\Receive;

use App\Core\Send\SendConfigSync;
use App\Core\Support\ChangeContext;
use App\Core\Webhooks\WebhookService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Central distribution of receive projects: SMTP logins and webhook, exported with hashes, applied on another node
 * (here: the same database emptied, as a fresh node) with the same hash; logins keep working.
 *
 * @internal
 */
final class ReceiveConfigSyncTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(true);
        config('Mailrunner')->dataDir = sys_get_temp_dir() . '/mr-rsync-none';
        $this->dir = sys_get_temp_dir() . '/mr-rsync-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir . '/node.key', base64_encode(random_bytes(32)));
        config('Mailrunner')->configDir = $this->dir;
        Services::injectMock('webhookService', new WebhookService(db_connect(), service('journalWriter'), null, static fn (string $host): array => ['93.184.216.34']));

        $cli = ChangeContext::cli();
        service('customerService')->create('acme', '', $cli);
        service('projectService')->create('acme/inbox', 'checked_auth', '', $cli);
        service('projectService')->create('acme/analysis', 'analysis', '', $cli);
        service('smtpCredentialService')->create('acme/inbox', 'printer-1', 'Printer', 'printer-password-0123456', true, ['198.51.100.0/24'], $cli);
        service('smtpCredentialService')->create('acme/inbox', 'scanner-1', '', 'scanner-password-0123456', false, null, $cli);
        $gone = service('smtpCredentialService')->create('acme/inbox', 'old-device', '', null, true, null, $cli);
        service('smtpCredentialService')->revoke($gone['credential']['credential_id'], $cli);
        service('webhookService')->set($this->pid('acme/inbox'), 'https://app.example.com/hooks/k3f9x2m7q1w8e4r6', 5, $cli);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        exec('rm -rf ' . escapeshellarg($this->dir));
        config('Mailrunner')->adminConfig = 'auto';
    }

    private function pid(string $ref): int
    {
        return (int) service('projectService')->get($ref)['id'];
    }

    private function sync(): \App\Core\Access\AccessConfigSync
    {
        return service('accessConfigSync');
    }

    private function project(): array
    {
        return service('projectService')->get('acme/inbox');
    }

    private function wipe(): void
    {
        db_connect()->table('smtp_credentials')->where('project_id', $this->pid('acme/inbox'))->delete();
        db_connect()->table('project_webhooks')->where('project_id', $this->pid('acme/inbox'))->delete();
    }

    /** @return array{0: int, 1: array<string, mixed>|null} */
    private function admin(string $method, string $uri, ?array $body = null): array
    {
        service('superglobals')->setServer('MR_CLIENT_VERIFY', 'SUCCESS')->setServer('MR_CLIENT_S_DN', 'CN=mailrunner-admin,O=MailRunner Admin');
        $req = $this->withHeaders(['X-MailRunner-Actor' => 'admin@example.org']);
        $res = $body !== null ? $req->withBodyFormat('json')->call($method, $uri, $body) : $req->call($method, $uri);

        return [$res->response()->getStatusCode(), json_decode((string) $res->response()->getBody(), true)];
    }

    public function testExportApplyOnAFreshNodeGivesTheSameSetAndWorkingLogins(): void
    {
        $set  = $this->sync()->snapshot($this->project(), true);
        $hash = $this->sync()->hash($this->project());
        $names = array_column($set['smtp_credentials'], 'username');
        sort($names);
        $this->assertSame(['printer-1', 'scanner-1'], $names);
        $this->assertCount(2, $set['smtp_credentials'], 'revoked logins are not part of the set');
        $this->assertSame(['url' => 'https://app.example.com/hooks/k3f9x2m7q1w8e4r6', 'cooldown_seconds' => 5], $set['webhook']);

        $this->wipe();
        $r = $this->sync()->apply('acme/inbox', $set, null, new ChangeContext('admin', 'a'));
        $this->assertSame($hash, $r['hash']);
        $this->assertSame(['smtp_credentials' => 2, 'removed' => 0, 'webhook' => 1], $r['changed']);
        $this->assertTrue(service('smtpCredentialService')->verifyPassword('printer-1', 'printer-password-0123456'));
        $this->assertTrue(service('smtpCredentialService')->verifyPassword('scanner-1', 'scanner-password-0123456'));
        $mail = (new \App\Modules\Receive\Libraries\ConfigBuilder(db_connect()))->build();
        $this->assertStringContainsString('printer-1', json_encode($mail));

        // idempotent, then removal of a login and the webhook
        $this->assertSame(['smtp_credentials' => 0, 'removed' => 0, 'webhook' => 0], $this->sync()->apply('acme/inbox', $set, $hash, new ChangeContext('admin', 'a'))['changed']);
        $smaller = $set;
        array_pop($smaller['smtp_credentials']);
        unset($smaller['webhook']);
        $r = $this->sync()->apply('acme/inbox', $smaller, null, new ChangeContext('admin', 'a'));
        $this->assertSame(['smtp_credentials' => 0, 'removed' => 1, 'webhook' => 1], $r['changed']);
        $this->assertSame(SendConfigSync::hashOf($smaller), $r['hash']);
        $this->assertFalse(service('smtpCredentialService')->verifyPassword('scanner-1', 'scanner-password-0123456'));
        $this->assertNull(service('webhookService')->get($this->pid('acme/inbox')));

        // a local change since the admin read the set is refused; logins only for checked_auth
        service('smtpCredentialService')->update('printer-1', ['label' => 'Office printer'], ChangeContext::cli());
        try {
            $this->sync()->apply('acme/inbox', $set, $r['hash'], new ChangeContext('admin', 'a'));
            $this->fail('expected SEND_CONFIG_CHANGED');
        } catch (\App\Core\Support\ConfigException $e) {
            $this->assertSame('SEND_CONFIG_CHANGED', $e->errorCode);
        }
        try {
            $this->sync()->apply('acme/analysis', $set, null, new ChangeContext('admin', 'a'));
            $this->fail('expected PROFILE_NO_SMTP_AUTH');
        } catch (\App\Core\Support\ConfigException $e) {
            $this->assertSame('PROFILE_NO_SMTP_AUTH', $e->errorCode);
        }
    }

    public function testWebhookOnlyProjectAndUnresolvableHostOnTheTargetNode(): void
    {
        $analysis = service('projectService')->get('acme/analysis');
        $empty    = $this->sync()->hash($analysis);
        $this->assertSame(['smtp_credentials' => []], $this->sync()->snapshot($analysis), 'no webhook key while none is set');

        // the origin node resolved the host; this node may not (yet): the set is still applied
        Services::injectMock('webhookService', new WebhookService(db_connect(), service('journalWriter'), null, static fn (string $host): array => []));
        Services::resetSingle('accessConfigSync');
        $r = $this->sync()->apply('acme/analysis', ['smtp_credentials' => [], 'webhook' => ['url' => 'https://app.example.com/hook', 'cooldown_seconds' => 2]], $empty, new ChangeContext('admin', 'a'));
        $this->assertSame(1, $r['changed']['webhook']);
        try { // but never a non-public literal address
            $this->sync()->apply('acme/analysis', ['smtp_credentials' => [], 'webhook' => ['url' => 'https://10.0.0.1/hook']], null, new ChangeContext('admin', 'a'));
            $this->fail('expected INVALID_WEBHOOK_URL');
        } catch (\App\Core\Support\ConfigException $e) {
            $this->assertSame('INVALID_WEBHOOK_URL', $e->errorCode);
        }
    }

    public function testAdminApiShowIndexExportAndManualQueueWithoutClearSecrets(): void
    {
        [$s, $show] = $this->admin('get', 'admin/v1/projects/acme/inbox');
        $this->assertSame(200, $s);
        $this->assertSame($this->sync()->hash($this->project()), $show['data']['access_config']['hash']);
        $names = array_column($show['data']['smtp_credentials'], 'username');
        sort($names);
        $this->assertSame(['printer-1', 'scanner-1'], $names);
        $this->assertStringNotContainsString('argon2id', json_encode($show));
        $this->assertStringNotContainsString('k3f9x2m7q1w8e4r6', json_encode($show), 'webhook URL only masked');

        [, $index] = $this->admin('get', 'admin/v1/projects');
        $rows = array_column($index['data'], 'access_config_hash', 'ref');
        $this->assertSame($show['data']['access_config']['hash'], $rows['acme/inbox']);

        [$s, $exp] = $this->admin('get', 'admin/v1/projects/acme/inbox/access-config');
        $this->assertSame(200, $s);
        $this->assertSame($show['data']['access_config']['hash'], $exp['data']['hash']);
        $this->assertStringStartsWith('$argon2id$', $exp['data']['smtp_credentials'][0]['password_hash']);
        $j = db_connect()->table('journal')->where('event', 'receive.config.exported')->get()->getRowArray();
        $this->assertSame(['smtp_credentials' => 2, 'webhook' => 1], json_decode($j['details'], true));

        config('Mailrunner')->adminConfig = 'manual';
        $set = $exp['data'];
        unset($set['hash'], $set['changed_at']);
        array_pop($set['smtp_credentials']);
        [$s] = $this->admin('put', 'admin/v1/projects/acme/inbox/access-config', $set);
        $this->assertSame(202, $s);
        $payload = (string) db_connect()->table('admin_pending_changes')->where('kind', 'access_config')->get()->getRow('payload');
        $this->assertStringNotContainsString('argon2id', $payload);
        $this->assertStringNotContainsString('k3f9x2m7q1w8e4r6', $payload);
        $this->assertSame(['smtp_credentials' => [2, 1], 'webhook' => [1, 1]], (new \App\Core\Admin\AdminChanges())->preview('access_config', 'acme/inbox', json_decode($payload, true)));
        $pending = new \App\Core\Admin\PendingChanges();
        $this->assertNull($pending->apply($pending->open()[0], 'operator'));
        $this->assertSame(SendConfigSync::hashOf($set), $this->sync()->hash($this->project()));
    }
}
