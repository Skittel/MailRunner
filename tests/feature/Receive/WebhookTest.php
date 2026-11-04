<?php

namespace Tests\Feature\Receive;

use App\Core\Secrets\NodeCrypto;
use App\Core\Support\ChangeContext;
use App\Core\Webhooks\WebhookSender;
use App\Core\Webhooks\WebhookService;
use App\Modules\Receive\Libraries\Importer;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Webhook: customer API, address checks, ping after import, cooldown with trailing ping, journal.
 *
 * @internal
 */
final class WebhookTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private const URL = 'https://hooks.example.com/hooks/mailrunner/k3f9x2abcdefghij';

    private string $dir;
    private NodeCrypto $crypto;
    private string $bearer;

    /** @var array<string, list<string>> */
    private array $dns = ['hooks.example.com' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'], 'internal.example.com' => ['93.184.216.34', '10.0.0.5']];

    /** @var list<string> */
    private array $calls = [];

    private int $answer = 204;

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(true);
        $this->dir = sys_get_temp_dir() . '/mr-wh-' . bin2hex(random_bytes(4));
        foreach (['config', 'data/receive/spool/analyzed', 'data/receive/spool/failed', 'data/receive/store/2026/11/01'] as $d) {
            mkdir($this->dir . '/' . $d, 0o700, true);
        }
        $key = random_bytes(32);
        file_put_contents($this->dir . '/config/node.key', base64_encode($key));
        $this->crypto = NodeCrypto::fromKey($key);
        $config                 = config('Mailrunner');
        $config->receiveEnabled = true;
        $config->configDir      = $this->dir . '/config';
        $config->dataDir        = $this->dir . '/data';

        Services::injectMock('webhookService', new WebhookService(db_connect(), service('journalWriter'), $this->dir . '/data/webhook.request',
            fn (string $host): array => $this->dns[$host] ?? []));

        $cli = new ChangeContext('cli', 'phpunit');
        service('customerService')->create('acme', '', $cli);
        service('projectService')->create('acme/inbound', 'analysis', '', $cli);
        $this->bearer = service('apiTokenService')->create('acme/inbound', 'test', 600, $cli)['bearer'];
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

    private function api(string $method, array $body = []): array
    {
        $req = $this->withHeaders(['Authorization' => 'Bearer ' . $this->bearer]);
        $res = $body !== [] ? $req->withBodyFormat('json')->call($method, 'v1/webhook', $body) : $req->call($method, 'v1/webhook');

        $body = (string) $res->response()->getBody();

        return [$res->response()->getStatusCode(), json_decode($body, true), $body];
    }

    private function receive(string $id): void
    {
        $project = service('projectService')->get('acme/inbound');
        $meta    = [
            'spool_id' => $id, 'received_at' => '2026-11-01T16:00:00Z', 'queue_id' => 'Q1', 'client_ip' => '192.0.2.10', 'client_name' => 'mx.example.net',
            'helo' => 'mx.example.net', 'mail_from' => 'a@example.net', 'rcpt' => 'b@example.org', 'project_id' => (int) $project['id'],
            'project' => 'acme/inbound', 'profile' => 'analysis', 'size' => 100, 'sha256' => str_repeat('b', 64), 'attachments' => [],
            'header_from' => null, 'header_to' => null, 'subject' => null, 'date_hdr' => null, 'message_id_hdr' => null, 'spf_status' => 'pass',
            'dkim_status' => 'none', 'analysis_status' => 'ok', 'client_info' => null, 'analysis_ms' => 10, 'analysis_path' => null, 'raw_path' => null,
        ];
        file_put_contents($this->dir . '/data/receive/spool/analyzed/' . $id . '.json', $this->crypto->encryptJson($meta, 'spool:' . $id . ':analyzed'));
        $importer = new Importer(db_connect(), service('journalWriter'), $this->crypto, $this->dir . '/data/receive/spool');
        $importer->run();
        service('webhookService')->markPending($importer->importedProjectIds());
    }

    private function sender(): WebhookSender
    {
        return new WebhookSender(db_connect(), service('journalWriter'), service('webhookService'), function (string $url, string $host, array $ips): array {
            $this->calls[] = $host . ' ' . implode(',', $ips);

            return ['code' => $this->answer, 'error' => null];
        });
    }

    private function events(string $event): array
    {
        return array_map(static fn ($r) => json_decode($r['details'], true), db_connect()->table('journal')->where('event', $event)->orderBy('id')->get()->getResultArray());
    }

    public function testApiChecksTheTargetAndNeverShowsTheFullUrl(): void
    {
        foreach ([
            ['http://hooks.example.com/x', 'INVALID_WEBHOOK_URL'], ['https://hooks.example.com:8443/x', 'INVALID_WEBHOOK_URL'],
            ['https://user:pw@hooks.example.com/x', 'INVALID_WEBHOOK_URL'], ['https://localhost/x', 'INVALID_WEBHOOK_URL'],
            ['https://127.0.0.1/x', 'INVALID_WEBHOOK_URL'], ['https://[::ffff:10.0.0.1]/x', 'INVALID_WEBHOOK_URL'],
            ['https://internal.example.com/x', 'INVALID_WEBHOOK_URL'], ['https://unknown.example.com/x', 'INVALID_WEBHOOK_URL'],
        ] as [$url, $code]) {
            [$status, $json] = $this->api('PUT', ['url' => $url]);
            $this->assertSame(422, $status, $url);
            $this->assertSame($code, $json['error']['code'], $url);
        }
        [$status] = $this->api('PUT', ['url' => self::URL, 'cooldown_seconds' => 0]);
        $this->assertSame(422, $status);

        [$status, $json] = $this->api('GET');
        $this->assertSame(200, $status);
        $this->assertNull($json['data']);

        [$status, $json, $raw] = $this->api('PUT', ['url' => self::URL, 'cooldown_seconds' => 5]);
        $this->assertSame(200, $status);
        $this->assertTrue($json['changed']);
        $this->assertSame('https://hooks.example.com/…ghij', $json['data']['url']);
        $this->assertSame(hash('sha256', self::URL), $json['data']['url_sha256']);
        $this->assertStringNotContainsString('k3f9x2', $raw);
        [, $json] = $this->api('PUT', ['url' => self::URL, 'cooldown_seconds' => 5]);
        $this->assertFalse($json['changed']);

        $updated = $this->events('webhook.updated');
        $this->assertCount(1, $updated);
        $this->assertSame(['url' => 'https://hooks.example.com/…ghij', 'cooldown_seconds' => 5, 'created' => true], $updated[0]);
        $this->assertStringNotContainsString('k3f9x2', json_encode(db_connect()->table('journal')->get()->getResultArray()));

        [, $json] = $this->api('DELETE');
        $this->assertTrue($json['deleted']);
        [, $json] = $this->api('GET');
        $this->assertNull($json['data']);
        $this->assertCount(1, $this->events('webhook.deleted'));
    }

    public function testPingAfterImportWithCooldownAndTrailingPing(): void
    {
        $this->receive('s1'); // no webhook yet: nothing pending
        $this->assertFileDoesNotExist($this->dir . '/data/webhook.request');

        $this->api('PUT', ['url' => self::URL, 'cooldown_seconds' => 30]);
        $this->receive('s2');
        $this->assertFileExists($this->dir . '/data/webhook.request');
        $r = $this->sender()->sendDue();
        $this->assertSame(1, $r['sent']);
        $this->assertSame(['hooks.example.com 93.184.216.34,2606:2800:220:1:248:1893:25c8:1946'], $this->calls, 'address pinned to the checked answer');

        // two more mails within the cooldown: no ping now, one ping when it ends
        $this->receive('s3');
        $this->receive('s4');
        $r = $this->sender()->sendDue();
        $this->assertSame(0, $r['sent']);
        $this->assertGreaterThan(25, $r['next_in']);
        db_connect()->query('UPDATE project_webhooks SET last_attempt_at = last_attempt_at - INTERVAL 31 SECOND');
        $this->answer = 500;
        $r = $this->sender()->sendDue();
        $this->assertSame(1, $r['failed']);
        $this->assertCount(2, $this->calls);
        $this->assertNull($this->sender()->sendDue()['next_in'], 'nothing pending, a failed ping is not repeated');

        $sent   = $this->events('webhook.sent');
        $failed = $this->events('webhook.failed');
        $this->assertSame(204, $sent[0]['http_code']);
        $this->assertSame(['url', 'http_code', 'error', 'ms', 'pending_since'], array_keys($failed[0]));
        $this->assertSame([500, 'http'], [$failed[0]['http_code'], $failed[0]['error']]);

        [, $json] = $this->api('GET');
        $this->assertSame(['failed', 500, false], [$json['data']['last_status'], $json['data']['last_http_code'], $json['data']['pending']]);
    }

    public function testAddressIsCheckedAgainAtSendTime(): void
    {
        $this->api('PUT', ['url' => self::URL]);
        $this->receive('s1');
        $this->dns['hooks.example.com'] = ['192.168.1.10']; // DNS changed to an internal address
        $r = $this->sender()->sendDue();
        $this->assertSame(1, $r['failed']);
        $this->assertSame([], $this->calls);
        $this->assertSame('blocked_address', $this->events('webhook.failed')[0]['error']);
    }

    public function testPublicAddressRanges(): void
    {
        foreach (['93.184.216.34' => true, '2a00:1450:4001::1' => true, '10.1.2.3' => false, '100.64.0.1' => false, '169.254.1.1' => false,
            '127.0.0.1' => false, '::1' => false, 'fd00::1' => false, 'fe80::1' => false, '::ffff:10.0.0.1' => false, '64:ff9b::a00:1' => false,
            '2002:a00:1::' => false, '2001:db8::1' => false, '192.0.2.1' => false] as $ip => $public) {
            $this->assertSame($public, WebhookService::isPublic($ip), $ip);
        }
    }

    public function testParallelFirstPutIsSerialised(): void
    {
        // a second connection holds the project lock as a parallel request would; the PUT must wait, not fail
        $other = \Config\Database::connect('tests', false);
        $other->transBegin();
        $other->query('SELECT id FROM projects WHERE id = ? FOR UPDATE', [(int) service('projectService')->get('acme/inbound')['id']]);
        $other->query('SET SESSION innodb_lock_wait_timeout = 1');
        db_connect()->query('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            service('webhookService')->set((int) service('projectService')->get('acme/inbound')['id'], self::URL, 2, new ChangeContext('api', 't'));
            $this->fail('expected a lock wait while the other request holds the project');
        } catch (\Throwable $e) {
            $this->assertStringContainsStringIgnoringCase('lock wait', $e->getMessage());
        } finally {
            $other->transRollback();
        }
        [, $json] = $this->api('PUT', ['url' => self::URL]);
        $this->assertTrue($json['changed']);
    }

    public function testUncaughtApiErrorsAreJson(): void
    {
        $response = service('response');
        $this->expectOutputRegex("/INTERNAL_ERROR/");
        (new \App\Core\Api\JsonExceptionHandler())->handle(new \RuntimeException('secret detail'), service('request'), $response, 500, 1);
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('INTERNAL_ERROR', $body['error']['code']);
        $this->assertNotEmpty($body['request_id']);
        $this->assertStringNotContainsString('secret detail', (string) $response->getBody());
    }
}
