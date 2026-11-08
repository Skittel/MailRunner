<?php

namespace Tests\Feature\Send;

use App\Core\Secrets\NodeCrypto;
use App\Core\Send\SendConfigSync;
use App\Core\Send\SendDomainService;
use App\Core\Support\ChangeContext;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Central distribution of the send configuration: export with secrets, apply on another node (here: the same
 * database emptied, as a fresh node), same hash, bearer and SMTP password keep working, conflicts are refused.
 *
 * @internal
 */
final class SendConfigSyncTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $manage;
    private int $pid;
    private string $bearer;
    private string $dir;

    protected function tearDown(): void
    {
        parent::tearDown();
        exec('rm -rf ' . escapeshellarg($this->dir));
        config('Mailrunner')->adminConfig = 'auto';
    }

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(true);
        config('Mailrunner')->sendEnabled = true;
        config('Mailrunner')->dataDir     = sys_get_temp_dir() . '/mr-sync-none'; // no apply.request
        $this->dir = sys_get_temp_dir() . '/mr-sync-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir . '/node.key', base64_encode(random_bytes(32)));
        config('Mailrunner')->configDir = $this->dir; // manual mode: queue encrypted with the node key
        $crypto  = NodeCrypto::fromKey(random_bytes(32));
        $domains = new SendDomainService(db_connect(), service('journalWriter'), fn () => $crypto);
        Services::injectMock('sendDomainService', $domains);

        $cli = ChangeContext::cli();
        service('customerService')->create('acme', '', $cli);
        service('projectService')->create('acme/mailer', '', '', $cli, 'send');
        service('projectService')->update('acme/mailer', ['send_limit_hour' => 100, 'send_limit_day' => 1000], $cli);
        $this->pid    = (int) service('projectService')->get('acme/mailer')['id'];
        $this->manage = service('apiTokenService')->create('acme/mailer', 'app', null, $cli)['bearer'];
        $this->api('PUT', 'domains/shop.example', ['selector' => 's1', 'private_key' => \App\Core\Send\DkimKeys::generate('ed25519', 0)['private_key']]);
        $this->api('PUT', 'send-profiles/shop', ['senders' => ['shop.example'], 'limit_hour' => 50, 'limit_day' => 500, 'return_path_domain' => 'bounce.example']);
        $this->bearer = $this->api('POST', 'send-profiles/shop/accesses', ['type' => 'api', 'label' => 'App'])[1]['bearer'];
        $this->api('POST', 'send-profiles/shop/accesses', ['type' => 'smtp', 'username' => 'shop-relay', 'password' => 'relay-password-0123456']);
        $this->api('POST', 'send-profiles/shop/accesses', ['type' => 'ip', 'networks' => ['192.0.2.8/29']]);
    }

    /** @return array{0: int, 1: array<string, mixed>|null} */
    private function api(string $method, string $path, ?array $body = null): array
    {
        $req = $this->withHeaders(['Authorization' => 'Bearer ' . $this->manage]);
        $res = $body !== null ? $req->withBodyFormat('json')->call($method, 'v1/' . $path, $body) : $req->call($method, 'v1/' . $path);

        return [$res->response()->getStatusCode(), json_decode((string) $res->response()->getBody(), true)];
    }

    /** A fresh node: no send configuration of the project at all. */
    private function wipe(): void
    {
        $db = db_connect();
        $db->table('send_accesses')->where('project_id', $this->pid)->delete();
        $db->table('api_tokens')->where('project_id', $this->pid)->where('scope', 'send')->delete();
        $db->table('smtp_credentials')->where('project_id', $this->pid)->delete();
        $db->table('send_profiles')->where('project_id', $this->pid)->delete();
        $db->table('send_domains')->where('project_id', $this->pid)->delete();
    }

    private function sync(): SendConfigSync
    {
        return service('sendConfigSync');
    }

    public function testExportApplyOnAFreshNodeGivesTheSameSetAndWorkingSecrets(): void
    {
        $set  = $this->sync()->snapshot($this->pid, true);
        $hash = $this->sync()->hash($this->pid);
        $this->assertSame(SendConfigSync::hashOf($set), $hash, 'private keys are not part of the hash');
        $this->assertSame(32, strlen((string) base64_decode($set['domains'][0]['private_key'], true)), 'ed25519 seed');
        $types = array_column($set['accesses'], 'type');
        sort($types);
        $this->assertSame(['api', 'ip', 'smtp'], $types);
        $this->assertArrayNotHasKey('private_key', $this->sync()->snapshot($this->pid)['domains'][0]);

        $this->wipe();
        $this->assertNotSame($hash, $this->sync()->hash($this->pid));
        $r = $this->sync()->apply($this->pid, $set, null, new ChangeContext('admin', 'admin@example.org'));
        $this->assertSame($hash, $r['hash'], 'same canonical set on the new node');
        $this->assertSame(['domains' => 1, 'profiles' => 1, 'accesses' => 3, 'removed' => 0], $r['changed']);

        // the same bearer and SMTP password work, the domain needs its own test on this node
        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $this->bearer])->withBodyFormat('json')
            ->call('POST', 'v1/send', ['from' => 'info@shop.example', 'to' => ['a@example.net'], 'subject' => 'x', 'text' => 'x']);
        $this->assertSame(202, $res->response()->getStatusCode());
        $this->assertTrue(service('smtpCredentialService')->verifyPassword('shop-relay', 'relay-password-0123456'));
        $d = db_connect()->table('send_domains')->where('project_id', $this->pid)->get()->getRowArray();
        $this->assertNotNull($d['test_requested_at']);

        // idempotent: nothing changes, nothing is journaled
        $before = db_connect()->table('journal')->countAllResults();
        $this->assertSame(['domains' => 0, 'profiles' => 0, 'accesses' => 0, 'removed' => 0], $this->sync()->apply($this->pid, $set, $hash, new ChangeContext('admin', 'a'))['changed']);
        $this->assertSame($before, db_connect()->table('journal')->countAllResults());
    }

    public function testChangesRemovalsAndConflicts(): void
    {
        $set  = $this->sync()->snapshot($this->pid, true);
        $hash = $this->sync()->hash($this->pid);
        $ctx  = new ChangeContext('admin', 'a');

        // local change on this node since the admin read it: refused, the admin takes it over first
        $this->api('PUT', 'send-profiles/shop', ['senders' => ['shop.example'], 'limit_hour' => 40, 'limit_day' => 500, 'return_path_domain' => 'bounce.example']);
        try {
            $this->sync()->apply($this->pid, $set, $hash, $ctx);
            $this->fail('expected SEND_CONFIG_CHANGED');
        } catch (\App\Core\Support\ConfigException $e) {
            $this->assertSame('SEND_CONFIG_CHANGED', $e->errorCode);
        }

        // the central set without the api access and with another limit: token revoked, limit back
        $smaller = $set;
        $smaller['accesses'] = array_values(array_filter($set['accesses'], static fn ($a) => $a['type'] !== 'api'));
        $r = $this->sync()->apply($this->pid, $smaller, null, $ctx);
        $this->assertSame(1, $r['changed']['removed']);
        $this->assertSame(50, (int) db_connect()->table('send_profiles')->where('project_id', $this->pid)->get()->getRow('limit_hour'));
        $api = array_values(array_filter($set['accesses'], static fn ($a) => $a['type'] === 'api'))[0];
        $this->assertNotNull(db_connect()->table('api_tokens')->where('token_id', $api['token']['token_id'])->get()->getRow('revoked_at'));
        $this->assertSame(SendConfigSync::hashOf($smaller), $r['hash']);

        // a key that does not match key_sha256 is refused (nothing applied)
        $bad = $set;
        $bad['domains'][0]['key_sha256'] = str_repeat('0', 64);
        try {
            $this->sync()->apply($this->pid, $bad, null, $ctx);
            $this->fail('expected INVALID_SEND_CONFIG');
        } catch (\App\Core\Support\ConfigException $e) {
            $this->assertSame('INVALID_SEND_CONFIG', $e->errorCode);
        }

        // an SMTP username of another project on this node is a conflict
        $cli = ChangeContext::cli();
        service('projectService')->create('acme/inbox', 'checked_auth', '', $cli);
        $this->wipe();
        service('smtpCredentialService')->create('acme/inbox', 'shop-relay', '', null, true, null, $cli);
        try {
            $this->sync()->apply($this->pid, $set, null, $ctx);
            $this->fail('expected USERNAME_EXISTS');
        } catch (\App\Core\Support\ConfigException $e) {
            $this->assertSame('USERNAME_EXISTS', $e->errorCode);
        }
        $this->assertSame(0, db_connect()->table('send_domains')->where('project_id', $this->pid)->countAllResults(), 'all or nothing');
    }

    public function testAdminApiExportJournalAndManualQueueWithoutClearKeys(): void
    {
        $admin = function (string $method, string $uri, ?array $body = null): array {
            service('superglobals')->setServer('MR_CLIENT_VERIFY', 'SUCCESS')->setServer('MR_CLIENT_S_DN', 'CN=mailrunner-admin,O=MailRunner Admin');
            $req = $this->withHeaders(['X-MailRunner-Actor' => 'admin@example.org']);
            $res = $body !== null ? $req->withBodyFormat('json')->call($method, $uri, $body) : $req->call($method, $uri);

            return [$res->response()->getStatusCode(), json_decode((string) $res->response()->getBody(), true)];
        };
        [$s, $show] = $admin('get', 'admin/v1/projects/acme/mailer');
        $this->assertSame($this->sync()->hash($this->pid), $show['data']['send_config']['hash']);

        [$s, $exp] = $admin('get', 'admin/v1/projects/acme/mailer/send-config');
        $this->assertSame(200, $s);
        $this->assertSame($show['data']['send_config']['hash'], $exp['data']['hash']);
        $key = $exp['data']['domains'][0]['private_key'];
        $this->assertSame(32, strlen((string) base64_decode($key, true)));
        $this->assertStringNotContainsString($key, json_encode($show), 'project view without the private key');
        $j = db_connect()->table('journal')->where('event', 'send.config.exported')->get()->getRowArray();
        $this->assertNotNull($j);
        $this->assertStringNotContainsString($key, (string) $j['details']);

        [$s, $err] = $admin('put', 'admin/v1/projects/acme/mailer/send-config', ['domains' => [], 'profiles' => [], 'accesses' => [], 'if_hash' => str_repeat('0', 64)]);
        $this->assertSame([409, 'SEND_CONFIG_CHANGED'], [$s, $err['error']['code']]);
        [$s, $err] = $admin('get', 'admin/v1/projects/system/selftest/send-config');
        $this->assertSame('SEND_CONFIG_LOCAL', $err['error']['code']);

        config('Mailrunner')->adminConfig = 'manual';
        $set = $exp['data'];
        unset($set['hash'], $set['changed_at']);
        [$s, $q] = $admin('put', 'admin/v1/projects/acme/mailer/send-config', $set);
        $this->assertSame(202, $s);
        $payload = (string) db_connect()->table('admin_pending_changes')->where('kind', 'send_config')->get()->getRow('payload');
        $this->assertStringNotContainsString($key, $payload);
        $this->assertStringNotContainsString('argon2id', $payload);
        $pending = new \App\Core\Admin\PendingChanges();
        $this->assertSame(['domains' => [1, 1], 'profiles' => [1, 1], 'accesses' => [3, 3]], (new \App\Core\Admin\AdminChanges())->preview('send_config', 'acme/mailer', json_decode($payload, true)));
        $this->wipe();
        $this->assertNull($pending->apply($pending->open()[0], 'operator'));
        $this->assertSame($exp['data']['hash'], $this->sync()->hash($this->pid));
    }
}
