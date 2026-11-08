<?php

namespace Tests\Feature\Send;

use App\Core\Send\SendProfileService;
use App\Core\Support\ChangeContext;
use App\Modules\Receive\Libraries\ConfigBuilder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Send role step 2 (E-27): send profiles checked against the project, accesses api / smtp / ip.
 *
 * @internal
 */
final class SendProfilesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $bearer;

    private const PROFILE = ['senders' => ['shop.example', 'Info@News.Shop.Example'], 'limit_hour' => 100, 'limit_day' => 1000];

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(true);
        config('Mailrunner')->sendEnabled    = true;
        config('Mailrunner')->receiveEnabled = true;

        $cli = ChangeContext::cli();
        service('customerService')->create('acme', '', $cli);
        service('projectService')->create('acme/mailer', '', '', $cli, 'send');
        service('projectService')->update('acme/mailer', ['send_limit_hour' => 500, 'send_limit_day' => 5000, 'send_allowed_domains' => ['shop.example']], $cli);
        $this->bearer = service('apiTokenService')->create('acme/mailer', 'app', null, $cli)['bearer'];
    }

    /** @return array{0: int, 1: array<string, mixed>|null, 2: string} */
    private function api(string $method, string $path, ?array $body = null, ?string $bearer = null): array
    {
        $req = $this->withHeaders(['Authorization' => 'Bearer ' . ($bearer ?? $this->bearer)]);
        $res = $body !== null ? $req->withBodyFormat('json')->call($method, 'v1/' . $path, $body) : $req->call($method, 'v1/' . $path);
        $raw = (string) $res->response()->getBody();

        return [$res->response()->getStatusCode(), json_decode($raw, true), $raw];
    }

    public function testProfilesAreCheckedAgainstTheProjectAndPutIsIdempotent(): void
    {
        [$code, $r, $raw] = $this->api('PUT', 'send-profiles/shop', self::PROFILE);
        $this->assertSame(201, $code, $raw);
        $p = $r['data'];
        $this->assertTrue($r['changed']);
        $this->assertMatchesRegularExpression('/^mrp_[a-z0-9]{16}$/', $p['profile_id']);
        $this->assertSame(['info@news.shop.example', 'shop.example'], $p['senders']);
        $this->assertNull($p['recipients']);
        $this->assertSame(['attempts' => 16, 'max_minutes' => 480], array_intersect_key($p['retry'], ['attempts' => 1, 'max_minutes' => 1]));
        $this->assertCount(15, $p['retry']['schedule_minutes']);
        $this->assertEquals(1, $p['retry']['schedule_minutes'][0]);
        $this->assertEquals(480, end($p['retry']['schedule_minutes']));
        $this->assertSame(24, $p['test_interval_hours']);
        $this->assertSame(['api' => 0, 'smtp' => 0, 'ip' => 0], $p['accesses']);

        [$code, $again] = $this->api('PUT', 'send-profiles/shop', ['senders' => ['shop.example', 'info@news.shop.example']] + self::PROFILE);
        $this->assertSame([200, false, $p['profile_id']], [$code, $again['changed'], $again['data']['profile_id']]);

        [, $changed] = $this->api('PUT', 'send-profiles/shop', ['retry' => ['attempts' => 5, 'max_minutes' => 10], 'recipients' => ['partner.example']] + self::PROFILE);
        $this->assertTrue($changed['changed']);
        $this->assertEquals([1, 2.7, 5.4, 10], $changed['data']['retry']['schedule_minutes']);
        $this->assertSame(2, db_connect()->table('journal')->where('event', 'send.profile.updated')->countAllResults());

        foreach ([
            [['limit_hour' => 501] + self::PROFILE, 403, 'LIMIT_EXCEEDS_PROJECT'],
            [['senders' => ['other.example']] + self::PROFILE, 403, 'DOMAIN_NOT_ALLOWED'],
            [['limit_hour' => 2000, 'limit_day' => 1000] + self::PROFILE, 422, 'INVALID_PROFILE'],
            [['senders' => []] + self::PROFILE, 422, 'INVALID_PROFILE'],
            [['senders' => ['not a domain']] + self::PROFILE, 422, 'INVALID_PROFILE'],
            [array_diff_key(self::PROFILE, ['limit_day' => 1]), 422, 'INVALID_PROFILE'],
            [['retry' => ['attempts' => 32, 'max_minutes' => 10]] + self::PROFILE, 422, 'INVALID_PROFILE'],
            [['retry' => ['attempts' => 4, 'max_minutes' => 60]] + self::PROFILE, 422, 'INVALID_PROFILE'],
            [['test_interval_hours' => 0] + self::PROFILE, 422, 'INVALID_PROFILE'],
            [['spam' => true] + self::PROFILE, 422, 'INVALID_PROFILE'],
        ] as [$body, $status, $error]) {
            [$code, $res] = $this->api('PUT', 'send-profiles/other', $body);
            $this->assertSame([$status, $error], [$code, $res['error']['code'] ?? null], json_encode($body));
        }
        [$code, $res] = $this->api('PUT', 'send-profiles/Bad_Name', self::PROFILE);
        $this->assertSame([422, 'INVALID_PROFILE_NAME'], [$code, $res['error']['code']]);

        // a project without limits cannot have profiles (fail closed); lowered project limits show as effective limits
        service('projectService')->update('acme/mailer', ['send_limit_day' => null], ChangeContext::cli());
        [$code, $res] = $this->api('PUT', 'send-profiles/other', self::PROFILE);
        $this->assertSame([403, 'PROJECT_LIMIT_MISSING'], [$code, $res['error']['code']]);
        service('projectService')->update('acme/mailer', ['send_limit_day' => 600, 'send_allowed_domains' => ['other.example']], ChangeContext::cli());
        [, $list] = $this->api('GET', 'send-profiles');
        $this->assertSame(600, $list['data'][0]['effective_limit_day']);
        $this->assertSame(['info@news.shop.example', 'shop.example'], $list['data'][0]['senders_not_allowed']);
    }

    public function testApiAccessTokenOnlySubmitsAndFollowsAccessState(): void
    {
        $this->api('PUT', 'send-profiles/shop', self::PROFILE);
        [$code, $r, $raw] = $this->api('POST', 'send-profiles/shop/accesses', ['type' => 'api', 'label' => 'webshop']);
        $this->assertSame(201, $code, $raw);
        $send = $r['bearer'];
        $id   = $r['data']['access_id'];
        $this->assertMatchesRegularExpression('/^mra_[a-z0-9]{16}$/', $id);
        $this->assertSame(['api', 'webshop', 'shop'], [$r['data']['type'], $r['data']['label'], $r['data']['profile']]);

        // the send token cannot manage the project, only submit (and read the node status)
        $this->assertSame(200, $this->api('GET', 'node/status', null, $send)[0]);
        foreach (['send-profiles', 'domains', 'send-profiles/shop/accesses'] as $path) {
            [$code, $res] = $this->api('GET', $path, null, $send);
            $this->assertSame([403, 'TOKEN_SCOPE'], [$code, $res['error']['code']], $path);
        }

        // never part of the central token set: not listed for the admin, not revoked by a sync
        $this->assertSame(['app'], array_column(service('apiTokenService')->list('acme/mailer'), 'label'));
        service('apiTokenService')->sync('acme/mailer', [], new ChangeContext('admin', 'phpunit'));
        $this->assertSame(200, $this->api('GET', 'node/status', null, $send)[0]);
        $this->assertSame(401, $this->api('GET', 'send-profiles')[0], 'the manage token was revoked by the sync');
        $this->bearer = $this->centralToken();

        // disabled access or profile: refused
        $this->api('PATCH', 'send-accesses/' . $id, ['enabled' => false]);
        $this->assertSame('ACCESS_DISABLED', $this->api('GET', 'node/status', null, $send)[1]['error']['code']);
        $this->api('PATCH', 'send-accesses/' . $id, ['enabled' => true]);
        $this->api('PUT', 'send-profiles/shop', ['enabled' => false] + self::PROFILE);
        $this->assertSame('ACCESS_DISABLED', $this->api('GET', 'node/status', null, $send)[1]['error']['code']);
        $this->api('PUT', 'send-profiles/shop', self::PROFILE);

        // rotation with grace: old and new token work; a manage-token rotation of a send token is refused
        [$code, $rot] = $this->api('POST', 'send-accesses/' . $id . '/rotate', ['grace_seconds' => 600]);
        $this->assertSame(200, $code);
        $this->assertNotSame($send, $rot['bearer']);
        $this->assertNotNull($rot['data']['previous_token_valid_until']);
        $this->assertSame(200, $this->api('GET', 'node/status', null, $send)[0]);
        $this->assertSame(200, $this->api('GET', 'node/status', null, $rot['bearer'])[0]);
        try {
            service('apiTokenService')->rotate($rot['data']['token_id'], 0, ChangeContext::cli());
            $this->fail('a send token must not be rotated as a project token');
        } catch (\App\Core\Support\ConfigException $e) {
            $this->assertSame('TOKEN_SCOPE', $e->errorCode);
        }

        // deleting the access revokes both tokens at once
        $this->assertSame(200, $this->api('DELETE', 'send-accesses/' . $id)[0]);
        $this->assertSame(401, $this->api('GET', 'node/status', null, $send)[0]);
        $this->assertSame(401, $this->api('GET', 'node/status', null, $rot['bearer'])[0]);
        $this->assertSame(404, $this->api('DELETE', 'send-accesses/' . $id)[0]);
        foreach (db_connect()->table('journal')->like('event', 'send.access.', 'after')->get()->getResultArray() as $j) {
            $this->assertStringNotContainsString(substr($rot['bearer'], 21), $j['details']);
        }
    }

    /** A new central manage token (the sync above revoked the local one). */
    private function centralToken(): string
    {
        $secret = 'Ab' . str_repeat('c', 41);
        service('apiTokenService')->sync('acme/mailer', [['token_id' => 'mrt_' . str_repeat('a', 16), 'label' => 'central', 'secret_hash' => hash('sha256', $secret)]], new ChangeContext('admin', 'phpunit'));

        return 'mrt_' . str_repeat('a', 16) . '.' . $secret;
    }

    public function testSmtpAccessSharesUsernamesButStaysOutOfTheReceivePath(): void
    {
        $cli = ChangeContext::cli();
        service('projectService')->create('acme/inbox', 'checked_auth', '', $cli);
        service('smtpCredentialService')->create('acme/inbox', 'device1', '', null, true, null, $cli);
        $this->api('PUT', 'send-profiles/shop', self::PROFILE);

        [$code, $res] = $this->api('POST', 'send-profiles/shop/accesses', ['type' => 'smtp', 'username' => 'Device1']);
        $this->assertSame([409, 'USERNAME_EXISTS'], [$code, $res['error']['code']], 'usernames are unique across both roles');

        [$code, $gen] = $this->api('POST', 'send-profiles/shop/accesses', ['type' => 'smtp', 'username' => 'shop-relay', 'allowed_networks' => ['198.51.100.0/24']]);
        $this->assertSame(201, $code);
        $this->assertSame(24, strlen($gen['password']));
        $this->assertSame(['shop-relay', false, ['198.51.100.0/24']], [$gen['data']['username'], $gen['data']['plaintext_port25'], $gen['data']['allowed_networks']]);
        $this->assertTrue(service('smtpCredentialService')->verifyPassword('shop-relay', $gen['password']));

        [$code, $set, $raw] = $this->api('POST', 'send-profiles/shop/accesses', ['type' => 'smtp', 'username' => 'exchange', 'password' => 'exchange-password-0123']);
        $this->assertSame(201, $code);
        $this->assertArrayNotHasKey('password', $set);
        $this->assertStringNotContainsString('exchange-password-0123', $raw);

        // not in the receive API, not for mr-auth (until the submission step)
        $this->assertSame(['device1'], array_column(service('smtpCredentialService')->list('acme/inbox'), 'username'));
        $this->assertSame([], service('smtpCredentialService')->list('acme/mailer'));
        $this->assertSame(['device1'], array_keys((new ConfigBuilder(db_connect()))->credentials()));

        // disable, rotate, delete
        $id = $gen['data']['access_id'];
        $this->api('PATCH', 'send-accesses/' . $id, ['enabled' => false, 'plaintext_port25' => true]);
        $this->assertFalse(service('smtpCredentialService')->verifyPassword('shop-relay', $gen['password']));
        [, $a] = $this->api('PATCH', 'send-accesses/' . $id, ['enabled' => true]);
        $this->assertTrue($a['data']['plaintext_port25']);
        [$code, $rot] = $this->api('POST', 'send-accesses/' . $id . '/rotate', ['grace_seconds' => 300]);
        $this->assertSame(200, $code);
        $this->assertTrue(service('smtpCredentialService')->verifyPassword('shop-relay', $rot['password']));
        $this->assertTrue(service('smtpCredentialService')->verifyPassword('shop-relay', $gen['password']), 'old password within grace');
        [$code, $res] = $this->api('PATCH', 'send-accesses/' . $id, ['networks' => ['198.51.100.8/29']]);
        $this->assertSame([422, 'INVALID_ACCESS'], [$code, $res['error']['code']], 'networks is an ip-access field');

        $this->assertSame(200, $this->api('DELETE', 'send-profiles/shop')[0]);
        $this->assertFalse(service('smtpCredentialService')->verifyPassword('shop-relay', $rot['password']), 'deleting the profile revokes its accesses');
        $this->assertSame(0, db_connect()->table('send_accesses')->countAllResults());
        $this->seeInDatabase('journal', ['event' => 'send.profile.deleted']);
        // the username is free again
        $this->api('PUT', 'send-profiles/shop', self::PROFILE);
        $this->assertSame(201, $this->api('POST', 'send-profiles/shop/accesses', ['type' => 'smtp', 'username' => 'shop-relay'])[0]);
    }

    public function testIpAccessNetworks(): void
    {
        $cli = ChangeContext::cli();
        $this->api('PUT', 'send-profiles/shop', self::PROFILE);
        [$code, $r, $raw] = $this->api('POST', 'send-profiles/shop/accesses', ['type' => 'ip', 'networks' => ['203.0.113.8/29', '2001:DB8:1::/64', '198.51.100.7']]);
        $this->assertSame(201, $code, $raw);
        $this->assertSame(['203.0.113.8/29', '2001:db8:1::/64', '198.51.100.7/32'], $r['data']['networks']);
        $this->assertArrayNotHasKey('bearer', $r);

        foreach ([
            [['203.0.113.0/28'], 'INVALID_NETWORK'],       // larger than /29
            [['2001:db8:2::/56'], 'INVALID_NETWORK'],      // larger than /64
            [['203.0.113.9/29'], 'INVALID_NETWORK'],       // host bits
            [['127.0.0.1'], 'INVALID_NETWORK'],
            [['::1'], 'INVALID_NETWORK'],
            [['fe80::1'], 'INVALID_NETWORK'],
            [['224.0.0.1'], 'INVALID_NETWORK'],
            [['192.0.2.1', '192.0.2.0/29'], 'INVALID_NETWORK'], // overlap within the list
            [[], 'INVALID_NETWORK'],
            [['nonsense'], 'INVALID_NETWORK'],
        ] as [$nets, $error]) {
            [$code, $res] = $this->api('POST', 'send-profiles/shop/accesses', ['type' => 'ip', 'networks' => $nets]);
            $this->assertSame([422, $error], [$code, $res['error']['code'] ?? null], json_encode($nets));
        }

        // a client address maps to one profile on the node: no overlap, also across projects
        service('projectService')->create('acme/second', '', '', $cli, 'send');
        service('projectService')->update('acme/second', ['send_limit_hour' => 10, 'send_limit_day' => 10], $cli);
        $other = service('apiTokenService')->create('acme/second', 'app', null, $cli)['bearer'];
        $this->api('PUT', 'send-profiles/p', ['senders' => ['x.example'], 'limit_hour' => 10, 'limit_day' => 10], $other);
        [$code, $res] = $this->api('POST', 'send-profiles/p/accesses', ['type' => 'ip', 'networks' => ['203.0.113.12']], $other);
        $this->assertSame([409, 'NETWORK_IN_USE'], [$code, $res['error']['code']]);
        [$code, $res] = $this->api('POST', 'send-profiles/p/accesses', ['type' => 'ip', 'networks' => ['2001:db8:1:0:1::/80']], $other);
        $this->assertSame(409, $code);
        $this->assertSame(201, $this->api('POST', 'send-profiles/p/accesses', ['type' => 'ip', 'networks' => ['203.0.113.16/29']], $other)[0]);

        // the other project cannot see or change this project's access
        $id = $r['data']['access_id'];
        $this->assertSame(404, $this->api('PATCH', 'send-accesses/' . $id, ['label' => 'x'], $other)[0]);
        $this->assertSame(404, $this->api('DELETE', 'send-accesses/' . $id, null, $other)[0]);
        // changing its own networks does not collide with itself
        [$code, $upd] = $this->api('PATCH', 'send-accesses/' . $id, ['networks' => ['203.0.113.8/29']]);
        $this->assertSame([200, ['203.0.113.8/29']], [$code, $upd['data']['networks']]);
        [$code, $res] = $this->api('POST', 'send-accesses/' . $id . '/rotate', []);
        $this->assertSame([422, 'INVALID_ACCESS'], [$code, $res['error']['code']]);

        // receive projects have no profile endpoints; the admin API shows profiles with accesses
        service('projectService')->create('acme/inbound', 'checked', '', $cli);
        $rx = service('apiTokenService')->create('acme/inbound', 'app', null, $cli)['bearer'];
        $this->assertSame([409, 'PROJECT_TYPE'], [($x = $this->api('GET', 'send-profiles', null, $rx))[0], $x[1]['error']['code']]);
        $this->assertSame(409, $this->api('PUT', 'send-profiles/x', self::PROFILE, $rx)[0]);
    }

    public function testRetryScheduleMatchesE27(): void
    {
        $s = SendProfileService::retrySchedule(16, 28800);
        $this->assertCount(15, $s);
        $this->assertSame([60, 28800], [$s[0], $s[14]]);
        $gaps = array_map(static fn ($i) => $s[$i] - ($i === 0 ? 0 : $s[$i - 1]), range(0, 14));
        $this->assertEqualsWithDelta(1.4, $gaps[1] / $gaps[0], 0.05, 'growth factor about 1.4 as in E-27');
        for ($i = 1; $i < 15; $i++) {
            $this->assertGreaterThan($gaps[$i - 1], $gaps[$i]);
        }
        $this->assertSame([60, 120, 180, 240], SendProfileService::retrySchedule(5, 240), 'minimum spacing: one minute each');
        $long = SendProfileService::retrySchedule(32, 172800);
        $this->assertSame([60, 172800], [$long[0], end($long)]);
    }
}
