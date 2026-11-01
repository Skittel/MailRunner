<?php

namespace Tests\Feature\Receive;

use App\Core\Secrets\NodeCrypto;
use App\Core\Support\ChangeContext;
use App\Modules\Receive\Libraries\Importer;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Customer API collection flow: list (with content), single message, ack, project isolation.
 *
 * @internal
 */
final class MessagesApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $dir;
    private NodeCrypto $crypto;
    private string $bearer;
    private string $otherBearer;

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(true);
        $this->dir = sys_get_temp_dir() . '/mr-api-' . bin2hex(random_bytes(4));
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

        $cli = new ChangeContext('cli', 'phpunit');
        service('customerService')->create('acme', '', $cli);
        service('projectService')->create('acme/inbound', 'analysis', '', $cli);
        service('projectService')->create('acme/other', 'analysis', '', $cli);
        $this->bearer      = service('apiTokenService')->create('acme/inbound', 'test', 600, $cli)['bearer'];
        $this->otherBearer = service('apiTokenService')->create('acme/other', 'test', 600, $cli)['bearer'];
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

    private function receive(string $id, string $rcpt): void
    {
        $analysisPath = '2026/11/01/' . $id . '.analysis.json.enc';
        file_put_contents($this->dir . '/data/receive/store/' . $analysisPath, $this->crypto->encryptJson(
            ['record' => ['mail_id' => $id], 'debug' => ['spf' => ['result' => 'pass']], 'raw_headers' => 'From: x'],
            'store:' . $id . ':analysis',
        ));
        $project = service('projectService')->get('acme/inbound');
        $meta    = [
            'spool_id' => $id, 'received_at' => '2026-11-01T16:00:00Z', 'queue_id' => 'Q' . substr($id, -6), 'client_ip' => '192.0.2.10',
            'client_name' => 'mx.example.net', 'helo' => 'mx.example.net', 'mail_from' => 'a@example.net', 'rcpt' => $rcpt,
            'project_id' => (int) $project['id'], 'project' => 'acme/inbound', 'profile' => 'analysis', 'size' => 100,
            'sha256' => str_repeat('b', 64), 'attachments' => [], 'header_from' => 'a@example.net', 'header_to' => $rcpt,
            'subject' => 'S ' . $id, 'date_hdr' => null, 'message_id_hdr' => null, 'spf_status' => 'pass', 'dkim_status' => 'none',
            'analysis_status' => 'ok', 'client_info' => null, 'analysis_ms' => 10, 'analysis_path' => $analysisPath, 'raw_path' => null,
        ];
        file_put_contents($this->dir . '/data/receive/spool/analyzed/' . $id . '.json', $this->crypto->encryptJson($meta, 'spool:' . $id . ':analyzed'));
        (new Importer(db_connect(), service('journalWriter'), $this->crypto, $this->dir . '/data/receive/spool'))->run();
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $uri, ?string $bearer = null): array
    {
        $result = $this->withHeaders(['Authorization' => 'Bearer ' . ($bearer ?? $this->bearer)])->get($uri);
        $result->assertStatus(200);

        return json_decode($result->response()->getBody(), true);
    }

    public function testCollectionFlowWithContentAndAck(): void
    {
        $this->receive('20261101T160000Z-0000000000000001', 'one@in.example');
        $this->receive('20261101T160000Z-0000000000000002', 'two@in.example');

        $list = $this->getJson('v1/messages?acked=0&include=content&limit=500');
        $this->assertCount(2, $list['data']);
        $this->assertSame('20261101T160000Z-0000000000000001', $list['data'][0]['record']['mail_id']);
        $this->assertSame('pass', $list['data'][1]['debug']['spf']['result']);
        $this->assertArrayNotHasKey('cursor', $list['data'][0]);

        // without include: metadata only
        $this->assertArrayNotHasKey('record', $this->getJson('v1/messages')['data'][0]);

        // other project's token sees nothing
        $this->assertSame([], $this->getJson('v1/messages', $this->otherBearer)['data']);
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->otherBearer])
            ->get('v1/messages/' . $list['data'][0]['message_id'])->assertStatus(404);

        $first = $list['data'][0]['message_id'];
        $ack   = $this->withHeaders(['Authorization' => 'Bearer ' . $this->bearer])
            ->withBodyFormat('json')->post('v1/messages/ack', ['message_ids' => [$first, 'mrm_unknown']]);
        $ack->assertStatus(200);
        $body = json_decode($ack->response()->getBody(), true);
        $this->assertSame([$first], $body['acked']);
        $this->assertSame(['mrm_unknown'], $body['unknown']);

        $open = $this->getJson('v1/messages?acked=0');
        $this->assertCount(1, $open['data']);
        $this->assertSame('two@in.example', $open['data'][0]['envelope']['rcpt']);
    }

    public function testInvalidIncludeAndMissingToken(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->bearer])->get('v1/messages?include=raw')->assertStatus(400);
        // headers persist between requests of FeatureTestTrait -> reset them
        $this->withHeaders([])->get('v1/messages')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer mrt_xxxxxxxxxxxxxxxx.wrong'])->get('v1/messages')->assertStatus(401);
    }
}
