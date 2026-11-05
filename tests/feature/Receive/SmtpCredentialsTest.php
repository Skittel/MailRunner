<?php

namespace Tests\Feature\Receive;

use App\Core\Secrets\NodeCrypto;
use App\Core\Support\ChangeContext;
use App\Modules\Receive\Libraries\ConfigBuilder;
use App\Modules\Receive\Libraries\Importer;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * SMTP credentials over the customer API, generated mail path files, import of authenticated mail.
 *
 * @internal
 */
final class SmtpCredentialsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $dir;
    private string $bearer;
    private string $otherBearer;

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(true);
        $this->dir = sys_get_temp_dir() . '/mr-smtp-' . bin2hex(random_bytes(4));
        foreach (['config', 'data/receive/spool/analyzed', 'data/receive/spool/failed'] as $d) {
            mkdir($this->dir . '/' . $d, 0o700, true);
        }
        $config                 = config('Mailrunner');
        $config->receiveEnabled = true;
        $config->configDir      = $this->dir . '/config';
        $config->dataDir        = $this->dir . '/data';
        $cli = new ChangeContext('cli', 'phpunit');
        service('customerService')->create('acme', '', $cli);
        service('projectService')->create('acme/app', 'checked_auth', '', $cli);
        service('projectService')->create('acme/inbox', 'analysis', '', $cli);
        $this->bearer      = service('apiTokenService')->create('acme/app', 'test', 600, $cli)['bearer'];
        $this->otherBearer = service('apiTokenService')->create('acme/inbox', 'test', 600, $cli)['bearer'];
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    /** @return array{0: int, 1: array<string, mixed>|null, 2: string} */
    private function api(string $method, string $path, array $body = [], ?string $bearer = null): array
    {
        $req = $this->withHeaders(['Authorization' => 'Bearer ' . ($bearer ?? $this->bearer)]);
        $res = $body !== [] ? $req->withBodyFormat('json')->call($method, 'v1/' . $path, $body) : $req->call($method, 'v1/' . $path);
        $raw = (string) $res->response()->getBody();

        return [$res->response()->getStatusCode(), json_decode($raw, true), $raw];
    }

    public function testLifecycleOverTheApi(): void
    {
        [$status, $json] = $this->api('POST', 'smtp-credentials', ['username' => 'Shop@Example.org', 'label' => 'shop']);
        $this->assertSame(201, $status);
        $id       = $json['data']['credential_id'];
        $password = $json['password'];
        $this->assertSame('shop@example.org', $json['data']['username']);
        $this->assertFalse($json['data']['plaintext_port25']);
        $this->assertGreaterThanOrEqual(16, strlen($password));
        $this->assertTrue(service('smtpCredentialService')->verifyPassword('shop@example.org', $password));

        [$status, $json, $raw] = $this->api('GET', 'smtp-credentials');
        $this->assertSame([200, 1], [$status, count($json['data'])]);
        $this->assertStringNotContainsString('argon2', $raw);
        $this->assertStringNotContainsString($password, $raw);

        [$status, $json] = $this->api('POST', 'smtp-credentials', ['username' => 'shop@example.org']);
        $this->assertSame([409, 'USERNAME_EXISTS'], [$status, $json['error']['code']]);
        [$status, $json] = $this->api('POST', 'smtp-credentials', ['username' => 'x']);
        $this->assertSame([422, 'INVALID_USERNAME'], [$status, $json['error']['code']]);
        [$status, $json] = $this->api('POST', 'smtp-credentials', ['username' => 'other@example.org'], $this->otherBearer);
        $this->assertSame([422, 'PROFILE_NO_SMTP_AUTH'], [$status, $json['error']['code']]);

        [$status, $json] = $this->api('PATCH', 'smtp-credentials/' . $id, ['plaintext_port25' => true, 'allowed_networks' => ['198.51.100.0/24']]);
        $this->assertSame(200, $status);
        $this->assertTrue($json['data']['plaintext_port25']);
        $this->assertSame(['198.51.100.0/24'], $json['data']['allowed_networks']);

        // another project's token sees nothing
        [$status] = $this->api('PATCH', 'smtp-credentials/' . $id, ['enabled' => false], $this->otherBearer);
        $this->assertSame(404, $status);

        [$status, $json] = $this->api('POST', 'smtp-credentials/' . $id . '/rotate', ['grace_seconds' => 3600]);
        $this->assertSame(200, $status);
        $this->assertTrue(service('smtpCredentialService')->verifyPassword('shop@example.org', $json['password']));
        $this->assertTrue(service('smtpCredentialService')->verifyPassword('shop@example.org', $password), 'old password valid during grace');
        $this->assertNotNull($json['data']['previous_password_valid_until']);

        [$status] = $this->api('DELETE', 'smtp-credentials/' . $id);
        $this->assertSame(200, $status);
        $this->assertFalse(service('smtpCredentialService')->verifyPassword('shop@example.org', $json['password']));
        [, $json] = $this->api('GET', 'smtp-credentials');
        $this->assertSame([], $json['data']);
        [$status] = $this->api('POST', 'smtp-credentials', ['username' => 'shop@example.org']);
        $this->assertSame(201, $status, 'a revoked credential frees its username');
        [$status, $json] = $this->api('POST', 'smtp-credentials', ['username' => 'weak@example.org', 'password' => 'short']);
        $this->assertSame([422, 'WEAK_PASSWORD'], [$status, $json['error']['code']]);

        $events = array_column(db_connect()->table('journal')->like('event', 'credential.', 'after')->orderBy('id')->get()->getResultArray(), 'event');
        $this->assertStringNotContainsString('short', json_encode(db_connect()->table('journal')->get()->getResultArray()));
        $this->assertSame(['credential.created', 'credential.updated', 'credential.rotated', 'credential.revoked', 'credential.created'], $events);
    }

    public function testPasswordSetByTheApplication(): void
    {
        // the same login on several nodes: the application chooses the password
        [$status, $json, $raw] = $this->api('POST', 'smtp-credentials', ['username' => 'multi@example.org', 'password' => 'chosen-by-the-app-123']);
        $this->assertSame(201, $status);
        $this->assertArrayNotHasKey('password', $json, 'a password set by the application is not echoed');
        $this->assertStringNotContainsString('chosen-by-the-app-123', $raw);
        $this->assertTrue(service('smtpCredentialService')->verifyPassword('multi@example.org', 'chosen-by-the-app-123'));

        [$status, $json] = $this->api('POST', 'smtp-credentials/' . $json['data']['credential_id'] . '/rotate', ['password' => 'second-app-password-456', 'grace_seconds' => 60]);
        $this->assertSame(200, $status);
        $this->assertArrayNotHasKey('password', $json);
        $this->assertTrue(service('smtpCredentialService')->verifyPassword('multi@example.org', 'second-app-password-456'));
        $this->assertTrue(service('smtpCredentialService')->verifyPassword('multi@example.org', 'chosen-by-the-app-123'), 'old one during grace');
        $this->assertStringNotContainsString('chosen-by-the-app', json_encode(db_connect()->table('journal')->get()->getResultArray()));
    }

    public function testGeneratedFilesForTheMailPath(): void
    {
        $this->api('POST', 'smtp-credentials', ['username' => 'tls@example.org']);
        $files = (new ConfigBuilder(db_connect()))->build();
        $creds = json_decode($files['credentials.json'], true)['credentials'];
        $this->assertSame(['tls@example.org'], array_keys($creds));
        $this->assertSame(['acme/app', 'checked_auth', true], [$creds['tls@example.org']['project'], $creds['tls@example.org']['profile'], $creds['tls@example.org']['tls_required']]);
        $this->assertStringStartsWith('$argon2id$', $creds['tls@example.org']['hash']);
        $this->assertStringContainsString("smtpd_tls_auth_only = yes\n", $files['postfix.conf']);

        [, $json] = $this->api('POST', 'smtp-credentials', ['username' => 'old-device@example.org', 'plaintext_port25' => true]);
        $files = (new ConfigBuilder(db_connect()))->build();
        $this->assertStringContainsString("smtpd_tls_auth_only = no\n", $files['postfix.conf'], 'plain AUTH on port 25 only while a credential needs it');

        service('projectService')->update('acme/app', ['enabled' => false], new ChangeContext('cli', 'phpunit'));
        $files = (new ConfigBuilder(db_connect()))->build();
        $this->assertSame([], json_decode($files['credentials.json'], true)['credentials'], 'disabled project: no login');
        $this->assertStringContainsString("smtpd_tls_auth_only = yes\n", $files['postfix.conf']);
    }

    public function testImportOfAuthenticatedMail(): void
    {
        [, $json] = $this->api('POST', 'smtp-credentials', ['username' => 'shop@example.org']);
        $id      = $json['data']['credential_id'];
        $key     = random_bytes(32);
        $crypto  = NodeCrypto::fromKey($key);
        $project = service('projectService')->get('acme/app');
        $meta    = [
            'spool_id' => 'a1', 'received_at' => '2026-11-05T10:00:00Z', 'queue_id' => 'Q1', 'client_ip' => '198.51.100.7', 'client_name' => 'shop.example.org',
            'helo' => 'shop.example.org', 'mail_from' => 'shop@example.org', 'rcpt' => 'anyone@unrouted.invalid', 'project_id' => (int) $project['id'],
            'project' => 'acme/app', 'profile' => 'checked_auth', 'size' => 10, 'sha256' => str_repeat('c', 64), 'attachments' => [],
            'header_from' => null, 'header_to' => null, 'subject' => 'S', 'date_hdr' => null, 'message_id_hdr' => null, 'spf_status' => 'none',
            'dkim_status' => 'none', 'client_info' => null, 'analysis_ms' => 5, 'analysis_path' => null, 'raw_path' => null,
            'received_via' => 'auth', 'auth_credential_id' => $id, 'auth_username' => 'shop@example.org', 'smtp_service' => 'submission',
            'analysis_status' => 'error',
        ];
        file_put_contents($this->dir . '/data/receive/spool/analyzed/a1.json', $crypto->encryptJson($meta, 'spool:a1:analyzed'));
        (new Importer(db_connect(), service('journalWriter'), $crypto, $this->dir . '/data/receive/spool'))->run();

        [$status, $json] = $this->api('GET', 'messages');
        $this->assertSame(200, $status);
        $this->assertSame('auth', $json['data'][0]['received_via']);
        $this->assertSame(['credential_id' => $id, 'username' => 'shop@example.org', 'service' => 'submission'], $json['data'][0]['auth']);
        $this->assertSame('error', $json['data'][0]['analysis_status'], 'stored although the analysis failed');
        $this->assertSame('anyone@unrouted.invalid', $json['data'][0]['envelope']['rcpt']);
        [, $list] = $this->api('GET', 'smtp-credentials');
        $this->assertNotNull($list['data'][0]['last_used_at']);
        $event = json_decode(db_connect()->table('journal')->where('event', 'receive.received')->get()->getRow('details'), true);
        $this->assertSame(['auth', $id, 'shop@example.org'], [$event['received_via'], $event['credential_id'], $event['username']]);
    }
}
