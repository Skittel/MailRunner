<?php

namespace Tests\Feature\Send;

use App\Core\Secrets\NodeCrypto;
use App\Core\Send\SendDomainService;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Send role step 1 (E-27): project type, send settings, sender domains with DKIM keys over the customer API.
 *
 * @internal
 */
final class SendDomainsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private NodeCrypto $crypto;
    private string $bearer;
    private string $receiveBearer;

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(true);
        config('Mailrunner')->sendEnabled    = true;
        config('Mailrunner')->receiveEnabled = true;
        $this->crypto = NodeCrypto::fromKey(random_bytes(32));
        Services::injectMock('sendDomainService', new SendDomainService(db_connect(), service('journalWriter'), fn () => $this->crypto));

        $cli = new ChangeContext('cli', 'phpunit');
        service('customerService')->create('acme', '', $cli);
        service('projectService')->create('acme/mailer', '', '', $cli, 'send');
        service('projectService')->create('acme/inbound', 'checked', '', $cli);
        $this->bearer        = service('apiTokenService')->create('acme/mailer', 'test', 600, $cli)['bearer'];
        $this->receiveBearer = service('apiTokenService')->create('acme/inbound', 'test', 600, $cli)['bearer'];
    }

    /** @return array{0: int, 1: array<string, mixed>|null, 2: string} */
    private function api(string $method, string $path, array $body = [], ?string $bearer = null): array
    {
        $req = $this->withHeaders(['Authorization' => 'Bearer ' . ($bearer ?? $this->bearer)]);
        $res = $body !== [] ? $req->withBodyFormat('json')->call($method, 'v1/' . $path, $body) : $req->call($method, 'v1/' . $path);
        $raw = (string) $res->response()->getBody();

        return [$res->response()->getStatusCode(), json_decode($raw, true), $raw];
    }

    public function testProjectTypeIsFixedAndSendProjectsHaveNoReceiveFeatures(): void
    {
        $cli = new ChangeContext('cli', 'phpunit');
        $p   = service('projectService')->get('acme/mailer');
        $this->assertSame('send', $p['type']);
        $this->assertSame('smarthost', $p['profile']);
        $this->assertSame('receive', service('projectService')->get('acme/inbound')['type']);

        foreach ([
            fn () => service('routeService')->set('acme/mailer', ['mailer.example'], $cli),
            fn () => service('projectService')->update('acme/mailer', ['recipient_pattern' => 'x'], $cli),
            fn () => service('projectService')->update('acme/inbound', ['send_limit_hour' => 10], $cli),
            fn () => service('smtpCredentialService')->create('acme/mailer', 'dev1', '', null, true, null, $cli),
            fn () => service('projectService')->create('acme/other', 'analysis', '', $cli, 'send'),
        ] as $i => $fn) {
            try {
                $fn();
                $this->fail('call ' . $i . ' must be refused');
            } catch (ConfigException) {
                $this->addToAssertionCount(1);
            }
        }

        // the admin cannot change the type of an existing project
        try {
            (new \App\Core\Admin\AdminChanges())->project('acme/inbound', ['type' => 'send'], new ChangeContext('admin', 'phpunit'));
            $this->fail('type change must be refused');
        } catch (ConfigException $e) {
            $this->assertSame('TYPE_IMMUTABLE', $e->errorCode);
        }
    }

    public function testSendSettingsAreValidatedAndPresentedByTheAdminApi(): void
    {
        $admin = new ChangeContext('admin', 'phpunit');
        [$p, $created] = (new \App\Core\Admin\AdminChanges())->project('acme/relay', ['type' => 'send', 'name' => 'Relay', 'routes' => [],
            'settings' => ['send_limit_hour' => 500, 'send_limit_day' => 5000, 'send_allowed_domains' => ['Shop.Example', 'example.org.'],
                'send_test_criteria' => ['spf' => true, 'dkim' => true, 'dmarc' => false]]], $admin);
        $this->assertTrue($created);
        $view = \App\Controllers\Admin\V1\Projects::present($p, []);
        $this->assertSame('send', $view['type']);
        $this->assertSame(['example.org', 'shop.example'], $view['settings']['send_allowed_domains']);
        $this->assertSame(['spf' => true, 'dkim' => true, 'dmarc' => false, 'dmarc_policy' => false, 'blacklist' => false], $view['settings']['send_test_criteria']);
        $this->assertArrayNotHasKey('recipient_pattern', $view['settings']);
        $this->assertSame(500, $view['settings']['send_limit_hour']);

        $rev = (int) $p['revision'];
        [$p] = (new \App\Core\Admin\AdminChanges())->project('acme/relay', ['type' => 'send', 'settings' => $view['settings']], $admin);
        $this->assertSame($rev, (int) $p['revision'], 'pushing the same state again changes nothing');

        foreach ([['send_limit_hour' => -1], ['send_allowed_domains' => ['no domain']], ['send_test_criteria' => ['spam' => true]]] as $bad) {
            try {
                service('projectService')->update('acme/relay', $bad, $admin);
                $this->fail('must be refused: ' . json_encode($bad));
            } catch (ConfigException $e) {
                $this->assertSame('INVALID_SETTING', $e->errorCode);
            }
        }
    }

    public function testGenerateAndSetDkimKeys(): void
    {
        foreach ([['algorithm' => 'rsa'], ['algorithm' => 'ed25519']] as $opt) {
            $before       = db_connect()->table('send_domains')->countAllResults();
            [$code, $gen] = $this->api('POST', 'dkim/generate', ['domain' => 'Mail.Example.COM'] + $opt);
            $this->assertSame(200, $code);
            $g = $gen['data'];
            $this->assertSame('mail.example.com', $g['domain']);
            $this->assertMatchesRegularExpression('/^mr\d{4}-[a-z2-9]{4}$/', $g['selector']);
            $this->assertSame($g['selector'] . '._domainkey.mail.example.com', $g['dns']['name']);
            $this->assertStringStartsWith('v=DKIM1; k=' . $opt['algorithm'] . '; p=', $g['dns']['value']);
            $this->assertSame($g['dns']['value'], implode('', $g['dns']['value_parts']));
            $this->assertLessThanOrEqual(255, max(array_map('strlen', $g['dns']['value_parts'])));
            $this->assertSame($before, db_connect()->table('send_domains')->countAllResults(), 'generate stores nothing');

            [$code, $set, $raw] = $this->api('PUT', 'domains/mail.example.com', ['selector' => $g['selector'], 'private_key' => $g['private_key']]);
            $this->assertSame(200, $code, $raw);
            $this->assertTrue($set['changed']);
            $this->assertSame($g['dns'], $set['data']['dns'], 'the stored key publishes the same record');
            $this->assertStringNotContainsString(substr(preg_replace('/-----[^-]+-----|\s/', '', $g['private_key']), 10, 40), $raw, 'private key is never returned');

            [, $again] = $this->api('PUT', 'domains/mail.example.com', ['selector' => $g['selector'], 'private_key' => $g['private_key']]);
            $this->assertFalse($again['changed']);

            $row = service('sendDomainService')->get((int) service('projectService')->get('acme/mailer')['id'], 'mail.example.com');
            $this->assertStringNotContainsString($g['private_key'], (string) $row['dkim_private_enc']);
            $this->assertSame(trim($g['private_key']), trim(service('sendDomainService')->privateKey($row)), 'decrypts to the key');
        }

        $journal = db_connect()->table('journal')->where('event', 'send.domain.updated')->get()->getResultArray();
        $this->assertCount(2, $journal);
        foreach ($journal as $j) {
            $this->assertStringNotContainsString('PRIVATE', $j['details']);
        }

        [, $list] = $this->api('GET', 'domains');
        $this->assertSame(['mail.example.com'], array_column($list['data'], 'domain'));
        $this->assertSame('ed25519', $list['data'][0]['dkim']['algorithm']);
        [$code, $del] = $this->api('DELETE', 'domains/mail.example.com');
        $this->assertSame([200, true], [$code, $del['deleted']]);
        [$code] = $this->api('GET', 'domains/mail.example.com');
        $this->assertSame(404, $code);
    }

    public function testInvalidKeysDomainsAndProjectRules(): void
    {
        $rsa1024 = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]);
        openssl_pkey_export($rsa1024, $pem1024);
        $rsa512 = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 512]);
        openssl_pkey_export($rsa512, $pem512);
        $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($ec, $pemEc);

        $this->assertSame(200, $this->api('PUT', 'domains/example.net', ['selector' => 's1', 'private_key' => $pem1024])[0], '1024 bit is the minimum');
        foreach ([
            ['example.net', ['selector' => 's1', 'private_key' => $pem512], 'INVALID_DKIM_KEY'],
            ['example.net', ['selector' => 's1', 'private_key' => $pemEc], 'INVALID_DKIM_KEY'],
            ['example.net', ['selector' => 's1', 'private_key' => 'bm90IGEga2V5'], 'INVALID_DKIM_KEY'],
            ['example.net', ['selector' => 'Bad_Selector', 'private_key' => $pem1024], 'INVALID_DKIM_SELECTOR'],
            ['not_a_domain', ['selector' => 's1', 'private_key' => $pem1024], 'INVALID_DOMAIN'],
        ] as [$domain, $body, $error]) {
            [$code, $res] = $this->api('PUT', 'domains/' . $domain, $body);
            $this->assertSame([422, $error], [$code, $res['error']['code'] ?? null], json_encode([$domain, $body['selector']]));
        }

        // allowed sender domains of the project (admin): subdomains included
        service('projectService')->update('acme/mailer', ['send_allowed_domains' => ['shop.example']], new ChangeContext('cli', 'phpunit'));
        $this->assertSame(200, $this->api('PUT', 'domains/news.shop.example', ['selector' => 's1', 'private_key' => $pem1024])[0]);
        [$code, $res] = $this->api('PUT', 'domains/other.example', ['selector' => 's1', 'private_key' => $pem1024]);
        $this->assertSame([403, 'DOMAIN_NOT_ALLOWED'], [$code, $res['error']['code']]);
        [, $list] = $this->api('GET', 'domains');
        $this->assertSame([false, true], array_column($list['data'], 'allowed'), 'example.net was set before the restriction');

        // receive projects have no send endpoints
        [$code, $res] = $this->api('GET', 'domains', [], $this->receiveBearer);
        $this->assertSame([409, 'PROJECT_TYPE'], [$code, $res['error']['code']]);
        [$code] = $this->api('POST', 'dkim/generate', ['domain' => 'x.example'], $this->receiveBearer);
        $this->assertSame(409, $code);
    }
}
