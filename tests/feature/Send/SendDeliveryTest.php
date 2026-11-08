<?php

namespace Tests\Feature\Send;

use App\Core\Secrets\NodeCrypto;
use App\Core\Send\SendDomainService;
use App\Core\Send\SendRetention;
use App\Core\Send\SendSubmissionService;
use App\Core\Support\ChangeContext;
use App\Core\Support\Time;
use App\Core\Webhooks\WebhookSender;
use App\Core\Webhooks\WebhookService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Send role step 4 (E-27): the queue as the delivery worker (Python, mailrunner_tx.queue) sees it, against the real
 * test database: domain-test gate, claim, record, change_seq cursor, attempts, webhook "delivery", retention.
 *
 * @internal
 */
final class SendDeliveryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $store;
    private string $manage;
    private string $send;
    private int $pid;

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(true);
        config('Mailrunner')->sendEnabled = true;
        $crypto      = NodeCrypto::fromKey(random_bytes(32));
        $this->store = sys_get_temp_dir() . '/mr-dlv-' . bin2hex(random_bytes(4));
        $domains     = new SendDomainService(db_connect(), service('journalWriter'), fn () => $crypto);
        Services::injectMock('sendDomainService', $domains);
        Services::injectMock('sendSubmissionService', new SendSubmissionService(db_connect(), service('journalWriter'), $domains, $this->store, fn () => $crypto));

        $cli = ChangeContext::cli();
        service('customerService')->create('acme', '', $cli);
        service('projectService')->create('acme/mailer', '', '', $cli, 'send');
        service('projectService')->update('acme/mailer', ['send_limit_hour' => 100, 'send_limit_day' => 1000], $cli);
        $this->pid    = (int) service('projectService')->get('acme/mailer')['id'];
        $this->manage = service('apiTokenService')->create('acme/mailer', 'app', null, $cli)['bearer'];
        $domains->set($this->pid, 'shop.example', 's1', \App\Core\Send\DkimKeys::generate('ed25519', 0)['private_key'], null, $cli);
        $this->api('PUT', 'send-profiles/shop', ['senders' => ['shop.example'], 'limit_hour' => 50, 'limit_day' => 500], $this->manage);
        $this->send = $this->api('POST', 'send-profiles/shop/accesses', ['type' => 'api'], $this->manage)[1]['bearer'];
        db_connect()->table('project_webhooks')->insert(['project_id' => $this->pid, 'url' => 'https://hooks.example.com/x', 'cooldown_seconds' => 60,
            'created_at' => Time::now(), 'updated_at' => Time::now()]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        exec('rm -rf ' . escapeshellarg($this->store));
    }

    /** @return array{0: int, 1: array<string, mixed>|null} */
    private function api(string $method, string $path, ?array $body = null, ?string $bearer = null): array
    {
        $req = $this->withHeaders(['Authorization' => 'Bearer ' . ($bearer ?? $this->send)]);
        $res = $body !== null ? $req->withBodyFormat('json')->call($method, 'v1/' . $path, $body) : $req->call($method, 'v1/' . $path);

        return [$res->response()->getStatusCode(), json_decode((string) $res->response()->getBody(), true)];
    }

    /**
     * Runs the Python queue code against the test database.
     *
     * @param list<array<string, mixed>> $steps
     *
     * @return list<array<string, mixed>>
     */
    private function worker(array $steps): array
    {
        $cfg  = config('Database')->tests;
        $in   = json_encode(['db' => ['host' => $cfg['hostname'], 'port' => (int) ($cfg['port'] ?: 3306), 'user' => $cfg['username'],
            'password' => $cfg['password'], 'database' => $cfg['database']], 'steps' => $steps]);
        $proc = proc_open(['python3', '-I', ROOTPATH . 'mailpath/tests/queue_driver.py'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $in);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($proc), $err);

        return array_map(static fn ($l) => json_decode($l, true), array_values(array_filter(explode("\n", (string) $out))));
    }

    /** @return array<string, array<string, mixed>> recipient => row */
    private function messages(): array
    {
        $out = [];
        foreach (db_connect()->table('send_messages')->get()->getResultArray() as $m) {
            $out[$m['recipient']] = $m;
        }

        return $out;
    }

    private function passTest(): void
    {
        $d = db_connect()->table('send_domains')->where('project_id', $this->pid)->get()->getRowArray();
        db_connect()->table('send_domains')->where('id', $d['id'])->update(['test_status' => 'passed', 'test_key_sha256' => $d['key_sha256'], 'test_passed_at' => Time::now()]);
    }

    public function testNothingIsSentWithoutAPassedDomainTest(): void
    {
        $this->assertSame(202, $this->api('POST', 'send', ['from' => 'info@shop.example', 'to' => ['a@example.net'], 'subject' => 'x', 'text' => 'x'])[0]);
        [$r] = $this->worker([['claim' => 10]]);
        $this->assertSame([], $r['claimed']);
        $m = $this->messages()['a@example.net'];
        $this->assertSame(['waiting_test', 'domain_not_verified'], [$m['status'], $m['status_reason']]);
        $this->assertSame(1, $r['pings'], 'status change -> webhook pending');
        [, $d] = $this->api('GET', 'domains/shop.example', null, $this->manage);
        $this->assertSame('untested', $d['data']['test']['status']);

        // 5 minutes without a passed test -> hardfail dns_not_verified
        db_connect()->table('send_messages')->update(['created_at' => Time::nowObject()->sub(new \DateInterval('PT6M'))->format(Time::DB_FORMAT), 'next_attempt_at' => Time::now()]);
        $this->worker([['claim' => 10]]);
        $this->assertSame(['failed', 'dns_not_verified'], [$this->messages()['a@example.net']['status'], $this->messages()['a@example.net']['status_reason']]);

        // a passed test for an older key does not count
        $this->assertSame(202, $this->api('POST', 'send', ['from' => 'info@shop.example', 'to' => ['b@example.net'], 'subject' => 'x', 'text' => 'x'])[0]);
        db_connect()->table('send_domains')->update(['test_status' => 'passed', 'test_key_sha256' => str_repeat('0', 64)]);
        [$r] = $this->worker([['claim' => 10]]);
        $this->assertSame([], $r['claimed']);
        $this->assertSame('waiting_test', $this->messages()['b@example.net']['status']);
    }

    public function testClaimRecordStatusListAttemptsAndWebhook(): void
    {
        $this->passTest();
        [, $s] = $this->api('POST', 'send', ['from' => 'info@shop.example', 'to' => ['a@example.net', 'bad@example.net', 'later@example.org'], 'subject' => 'x', 'text' => 'x']);
        [, $list] = $this->api('GET', 'send/messages');
        $cursor   = $list['next_cursor'];
        $this->assertCount(3, $list['data']);

        [$c, $rec] = $this->worker([['claim' => 10], ['record' => ['a@example.net' => 'sent', 'bad@example.net' => 'bounced', 'later@example.org' => 'deferred']]]);
        $this->assertSame(['a@example.net', 'bad@example.net', 'later@example.org'], $c['claimed']);
        $this->assertSame([3, 1], [$rec['recorded'], $rec['pings']]);
        $m = $this->messages();
        $this->assertSame(['sent', null, '250', 1], [$m['a@example.net']['status'], $m['a@example.net']['status_reason'], (string) $m['a@example.net']['last_code'], (int) $m['a@example.net']['attempts']]);
        $this->assertNotNull($m['a@example.net']['sent_at']);
        $this->assertSame(['bounced', 'rejected_rcpt'], [$m['bad@example.net']['status'], $m['bad@example.net']['status_reason']]);
        $this->assertSame(['deferred', 'temporary_rcpt'], [$m['later@example.org']['status'], $m['later@example.org']['status_reason']]);
        $next = strtotime($m['later@example.org']['next_attempt_at'] . ' UTC') - strtotime($m['later@example.org']['created_at'] . ' UTC');
        $this->assertEqualsWithDelta(60, $next, 5, 'first retry one minute after the first attempt');
        $this->assertNull($m['later@example.org']['lease_until']);

        // cursor: only the changes after it, in commit order
        [, $changes] = $this->api('GET', 'send/messages?after=' . $cursor);
        $got = array_column($changes['data'], 'recipient');
        sort($got);
        $this->assertSame(['a@example.net', 'bad@example.net', 'later@example.org'], $got);
        $cursors = array_column($changes['data'], 'cursor');
        $this->assertSame($cursors, array_values(array_unique($cursors)));
        $this->assertSame($cursors, (static function (array $c) { sort($c); return $c; })($cursors), 'oldest change first');
        $this->assertGreaterThan($cursor, $cursors[0]);
        $this->assertSame($s['data']['submission_id'], $changes['data'][0]['submission_id']);
        [, $none] = $this->api('GET', 'send/messages?after=' . $changes['next_cursor']);
        $this->assertSame([[], $changes['next_cursor']], [$none['data'], $none['next_cursor']]);
        [, $bounced] = $this->api('GET', 'send/messages?status=bounced', null, $this->manage);
        $this->assertSame(['bad@example.net'], array_column($bounced['data'], 'recipient'));

        // every try is kept: a refused connection to the first MX and the answer of the second
        [, $detail] = $this->api('GET', 'send/messages/' . $m['a@example.net']['message_id']);
        $this->assertSame([['connect', 'deferred', null, 'mx0.example.net'], ['data', 'sent', 250, 'mx.example.net']],
            array_map(static fn ($a) => [$a['stage'], $a['result'], $a['smtp_code'], $a['mx_host']], $detail['data']['delivery_attempts']));
        $this->assertSame(['192.0.2.25', '192.0.2.1', 'TLSv1.3', 1], [$detail['data']['delivery_attempts'][1]['mx_ip'], $detail['data']['delivery_attempts'][1]['local_ip'],
            $detail['data']['delivery_attempts'][1]['tls_version'], $detail['data']['delivery_attempts'][1]['attempt']]);
        $this->assertSame(['code' => 250, 'enhanced' => '2.0.0', 'message' => '250 test answer'], $detail['data']['last_response']);
        $this->assertNull($detail['data']['next_attempt_at'], 'final: no next attempt');

        // the webhook ping of a send project says "delivery"
        $events = [];
        $hooks  = new WebhookService(db_connect(), service('journalWriter'), null, static fn (string $host): array => ['93.184.216.34']);
        (new WebhookSender(db_connect(), service('journalWriter'), $hooks, static function (string $url, string $host, array $ips, string $event = 'messages') use (&$events): array {
            $events[] = $event;

            return ['code' => 204, 'error' => null];
        }))->run(5);
        $this->assertSame(['delivery'], $events);

        // a crashed worker: the lease runs out, the message goes back to deferred
        db_connect()->table('send_messages')->where('recipient', 'later@example.org')->update(['status' => 'sending', 'lease_until' => '2026-01-01 00:00:00']);
        $this->worker([['claim' => 0]]);
        $this->assertSame(['deferred', 'worker_lease_expired'], [$this->messages()['later@example.org']['status'], $this->messages()['later@example.org']['status_reason']]);
    }

    public function testDomainTestStateIsKeptAndReleasesWaitingMail(): void
    {
        // a new key requests a test; the worker leases it
        [$t] = $this->worker([['due_tests' => 5]]);
        $this->assertSame([['domain' => 'shop.example', 'trigger' => 'requested']], $t['tests']);
        [, $d] = $this->api('GET', 'domains/shop.example', null, $this->manage);
        $this->assertTrue($d['data']['test']['running']);
        $this->assertSame([[]], array_column($this->worker([['due_tests' => 5]]), 'tests'), 'leased: not twice');

        // a mail waits for the test; the test passes -> the mail is due at once, journal, history, interval from the profile
        db_connect()->table('send_domains')->update(['test_running_until' => null]);
        $this->api('POST', 'send', ['from' => 'info@shop.example', 'to' => ['a@example.net'], 'subject' => 'x', 'text' => 'x']);
        $this->worker([['claim' => 10]]);
        $this->assertSame('waiting_test', $this->messages()['a@example.net']['status']);
        db_connect()->table('send_messages')->update(['next_attempt_at' => '2030-01-01 00:00:00']);
        [$t, $r] = $this->worker([['due_tests' => 5], ['record_test' => ['outcome' => 'passed', 'details' => ['failed' => []]]]]);
        $this->assertSame(['passed'], $r['recorded']);
        $this->assertLessThan(time() + 5, strtotime($this->messages()['a@example.net']['next_attempt_at'] . ' UTC'), 'released');
        [, $d] = $this->api('GET', 'domains/shop.example', null, $this->manage);
        $this->assertSame(['passed', true, false, false, null], [$d['data']['test']['status'], $d['data']['test']['valid'], $d['data']['test']['requested'],
            $d['data']['test']['running'], $d['data']['test']['error']]);
        $this->assertEqualsWithDelta(24 * 3600, strtotime($d['data']['test']['next_at']) - time(), 10, 'interval of the profile (24 h)');
        [$c] = $this->worker([['claim' => 10]]);
        $this->assertSame(['a@example.net'], $c['claimed'], 'the mail goes now');
        $j = db_connect()->table('journal')->where('event', 'send.domain.tested')->get()->getRowArray();
        $this->assertSame(['shop.example', 'passed', 'requested'], [json_decode($j['details'], true)['domain'], json_decode($j['details'], true)['outcome'], json_decode($j['details'], true)['trigger']]);
        [, $hist] = $this->api('GET', 'domains/shop.example/tests', null, $this->manage);
        $this->assertSame(['passed'], array_column($hist['data'], 'outcome'));

        // SmartMailFlow unavailable: not a failure, the passed result stays, retry in 10 minutes
        $this->assertSame(202, $this->api('POST', 'domains/shop.example/test', [], $this->manage)[0]);
        [, $r] = $this->worker([['due_tests' => 5], ['record_test' => ['outcome' => 'unavailable', 'details' => ['error' => 'no SmartMailFlow result for IPv6']]]]);
        [, $d] = $this->api('GET', 'domains/shop.example', null, $this->manage);
        $this->assertSame(['passed', true, 'no SmartMailFlow result for IPv6'], [$d['data']['test']['status'], $d['data']['test']['valid'], $d['data']['test']['error']]);
        $this->assertEqualsWithDelta(600, strtotime($d['data']['test']['next_at']) - time(), 10);

        // the key changes while a test runs: the result does not count, a new test is requested
        db_connect()->table('send_domains')->update(['test_next_at' => Time::now()]);
        [$t, $r] = $this->worker([['due_tests' => 5], ['record_test' => ['outcome' => 'passed', 'key' => str_repeat('e', 64)]]]);
        $this->assertSame(['key_changed'], $r['recorded']);

        // a failed interval test stops sending at once
        db_connect()->table('send_domains')->update(['test_next_at' => Time::now(), 'test_running_until' => null]);
        [, $r] = $this->worker([['due_tests' => 5], ['record_test' => ['outcome' => 'failed', 'details' => ['failed' => ['dkim.v4']]]]]);
        $this->assertSame(['failed'], $r['recorded']);
        [, $d] = $this->api('GET', 'domains/shop.example', null, $this->manage);
        $this->assertSame(['failed', false, ['failed' => ['dkim.v4']]], [$d['data']['test']['status'], $d['data']['test']['valid'], $d['data']['test']['details']]);
        $this->assertSame(3, db_connect()->table('send_domain_tests')->countAllResults() - 1, 'every run is kept (passed, unavailable, key_changed, failed)');
    }

    public function testRetentionDeletesTheMailWhenEveryRecipientIsFinal(): void
    {
        $this->passTest();
        $this->api('POST', 'send', ['from' => 'info@shop.example', 'to' => ['a@example.net', 'later@example.org'], 'subject' => 'x', 'text' => 'x']);
        $this->worker([['claim' => 10], ['record' => ['a@example.net' => 'sent', 'later@example.org' => 'deferred']]]);
        $sub  = db_connect()->table('send_submissions')->get()->getRowArray();
        $file = $this->store . '/' . $sub['raw_path'];
        $ret  = new SendRetention(db_connect(), $this->store);
        $this->assertSame(0, $ret->run()['raw_deleted'], 'one recipient still open');
        $this->assertFileExists($file);
        db_connect()->table('send_messages')->where('recipient', 'later@example.org')->update(['status' => 'failed', 'status_reason' => 'retries_exhausted']);
        $this->assertSame(1, $ret->run()['raw_deleted']);
        $this->assertFileDoesNotExist($file);
        db_connect()->table('send_submissions')->update(['created_at' => '2020-01-01 00:00:00']);
        $this->assertSame(1, $ret->run()['purged'], 'metadata after metadata_days');
        $this->assertSame(0, db_connect()->table('send_messages')->countAllResults());
    }
}
