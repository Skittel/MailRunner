<?php

namespace Tests\Feature\Send;

use App\Core\Secrets\NodeCrypto;
use App\Core\Send\Mime;
use App\Core\Send\SendDomainService;
use App\Core\Send\SendRejected;
use App\Core\Send\SendSubmissionService;
use App\Core\Support\ChangeContext;
use App\Modules\Receive\Libraries\ConfigBuilder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Send role step 3 (E-27): submission per API and SMTP hand-off, checks, limits, encrypted queueing.
 *
 * @internal
 */
final class SendSubmitTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private NodeCrypto $crypto;
    private string $store;
    private string $manage;
    private string $send;

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(true);
        config('Mailrunner')->sendEnabled    = true;
        config('Mailrunner')->receiveEnabled = true;
        $this->crypto = NodeCrypto::fromKey(random_bytes(32));
        $this->store  = sys_get_temp_dir() . '/mr-send-' . bin2hex(random_bytes(4));
        $domains      = new SendDomainService(db_connect(), service('journalWriter'), fn () => $this->crypto);
        Services::injectMock('sendDomainService', $domains);
        Services::injectMock('sendSubmissionService', new SendSubmissionService(db_connect(), service('journalWriter'), $domains, $this->store, fn () => $this->crypto));

        $cli = ChangeContext::cli();
        service('customerService')->create('acme', '', $cli);
        service('projectService')->create('acme/mailer', '', '', $cli, 'send');
        service('projectService')->update('acme/mailer', ['send_limit_hour' => 100, 'send_limit_day' => 1000, 'send_allowed_domains' => ['shop.example', 'nodkim.example']], $cli);
        $this->manage = service('apiTokenService')->create('acme/mailer', 'app', null, $cli)['bearer'];
        $pid          = (int) service('projectService')->get('acme/mailer')['id'];
        $key          = \App\Core\Send\DkimKeys::generate('ed25519', 0);
        $domains->set($pid, 'shop.example', 's1', $key['private_key'], null, $cli);
        $this->api('PUT', 'send-profiles/shop', ['senders' => ['shop.example', 'info@nodkim.example'], 'limit_hour' => 5, 'limit_day' => 50], $this->manage);
        $this->send = $this->api('POST', 'send-profiles/shop/accesses', ['type' => 'api'], $this->manage)[1]['bearer'];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->store)) {
            exec('rm -rf ' . escapeshellarg($this->store));
        }
    }

    /** @return array{0: int, 1: array<string, mixed>|null, 2: string} */
    private function api(string $method, string $path, ?array $body = null, ?string $bearer = null): array
    {
        $req = $this->withHeaders(['Authorization' => 'Bearer ' . ($bearer ?? $this->send)]);
        $res = $body !== null ? $req->withBodyFormat('json')->call($method, 'v1/' . $path, $body) : $req->call($method, 'v1/' . $path);
        $raw = (string) $res->response()->getBody();

        return [$res->response()->getStatusCode(), json_decode($raw, true), $raw];
    }

    private function stored(string $submissionId): string
    {
        $row = db_connect()->table('send_submissions')->where('submission_id', $submissionId)->get()->getRowArray();

        return $this->crypto->decrypt((string) file_get_contents($this->store . '/' . $row['raw_path']), 'send:' . $submissionId . ':eml');
    }

    public function testStructuredSubmissionIsQueuedEncryptedWithOneMessagePerRecipient(): void
    {
        [$code, $r, $raw] = $this->api('POST', 'send', [
            'from' => 'Jörg vom Shop <info@shop.example>', 'to' => ['Kunde <kunde@example.net>'], 'cc' => 'chef@example.org', 'bcc' => ['archiv@example.com', 'KUNDE@example.net'],
            'subject' => 'Ihre Bestellung Nr. 4711 – vielen Dank', 'text' => "Hallo,\ndanke.", 'html' => '<p>Hallo <img src="cid:logo"></p>',
            'headers' => ['X-Order' => '4711'],
            'attachments' => [['filename' => 'rechnung.pdf', 'content_type' => 'application/pdf', 'content' => base64_encode('%PDF-1.4')],
                ['filename' => 'logo.png', 'content_type' => 'image/png', 'content' => base64_encode('PNG'), 'inline' => true, 'content_id' => 'logo']],
        ]);
        $this->assertSame(202, $code, $raw);
        $sid = $r['data']['submission_id'];
        $this->assertMatchesRegularExpression('/^mrs_[a-z0-9]{16}$/', $sid);
        $this->assertSame(['kunde@example.net', 'chef@example.org', 'archiv@example.com'], array_column($r['data']['messages'], 'recipient'), 'one message per recipient, duplicates merged');
        $this->assertSame(['queued'], array_values(array_unique(array_column($r['data']['messages'], 'status'))));

        $mail = $this->stored($sid);
        $file = (string) file_get_contents($this->store . '/' . db_connect()->table('send_submissions')->where('submission_id', $sid)->get()->getRow()->raw_path);
        $this->assertStringNotContainsString('Bestellung', $file, 'stored encrypted');
        [$fields, $body] = Mime::split($mail);
        $this->assertSame('Ihre Bestellung Nr. 4711 – vielen Dank', Mime::decodeHeader((string) Mime::header($fields, 'subject')));
        $this->assertSame([['Jörg vom Shop', 'info@shop.example']], Mime::parseAddressList((string) Mime::header($fields, 'from')));
        $this->assertNull(Mime::header($fields, 'bcc'), 'Bcc is never in the message');
        $this->assertNotNull(Mime::header($fields, 'message-id'));
        $this->assertNotNull(Mime::header($fields, 'date'));
        $this->assertSame('4711', Mime::header($fields, 'x-order'));
        $this->assertStringContainsString('multipart/mixed', $mail);
        $this->assertStringContainsString('multipart/related', $mail);
        $this->assertStringContainsString('Content-ID: <logo>', $mail);
        $this->assertStringContainsString(base64_encode('%PDF-1.4'), $body);

        $sub = db_connect()->table('send_submissions')->where('submission_id', $sid)->get()->getRowArray();
        $this->assertSame(['api', 'info@shop.example', 'shop.example', '3'], [$sub['via'], $sub['envelope_from'], $sub['from_domain'], (string) $sub['recipients']]);
        $j = db_connect()->table('journal')->where('event', 'send.submitted')->get()->getRowArray();
        $this->assertStringNotContainsString('kunde@', $j['details'], 'no addresses in the journal');

        // status: send token and project token, not other projects
        [$code, $s] = $this->api('GET', 'send/submissions/' . $sid);
        $this->assertSame([200, 3, 'shop'], [$code, count($s['data']['messages']), $s['data']['profile']]);
        [$code, $m] = $this->api('GET', 'send/messages/' . $r['data']['messages'][0]['message_id'], null, $this->manage);
        $this->assertSame([200, 'kunde@example.net', $sid], [$code, $m['data']['recipient'], $m['data']['submission']['submission_id']]);
        $cli = ChangeContext::cli();
        service('projectService')->create('acme/other', '', '', $cli, 'send');
        $other = service('apiTokenService')->create('acme/other', 'x', null, $cli)['bearer'];
        $this->assertSame(404, $this->api('GET', 'send/submissions/' . $sid, null, $other)[0]);
    }

    public function testRawSubmissionTakesRecipientsFromHeadersAndRemovesBcc(): void
    {
        $raw = "From: info@shop.example\nTo: a@example.net, \"B, Person\" <b@example.org>\nBcc: hidden@example.com\nSubject: Raw\n\nBody\n";
        [$code, $r, $body] = $this->api('POST', 'send', ['raw' => base64_encode($raw)]);
        $this->assertSame(202, $code, $body);
        $this->assertSame(['a@example.net', 'b@example.org', 'hidden@example.com'], array_column($r['data']['messages'], 'recipient'));
        $mail = $this->stored($r['data']['submission_id']);
        $this->assertStringNotContainsString('hidden@', $mail);
        $this->assertStringContainsString("\r\nSubject: Raw\r\n", $mail);
        $this->assertStringEndsWith("\r\n\r\nBody\r\n", $mail);

        [$code, $r] = $this->api('POST', 'send', ['raw' => base64_encode($raw), 'recipients' => ['only@example.net']]);
        $this->assertSame([202, ['only@example.net']], [$code, array_column($r['data']['messages'], 'recipient')]);
    }

    public function testRefusals(): void
    {
        $msg = ['from' => 'info@shop.example', 'to' => 'a@example.net', 'subject' => 'x', 'text' => 'x'];
        foreach ([
            [['from' => 'info@evil.example'] + $msg, 422, 'SENDER_NOT_ALLOWED'],
            [['from' => 'x@news.shop.example'] + $msg, 422, 'SENDER_NOT_ALLOWED'],   // exact domain only
            [['from' => 'info@nodkim.example'] + $msg, 422, 'DKIM_MISSING'],
            [['from' => 'a@x.example, b@x.example'] + $msg, 422, 'INVALID_FROM'],
            [['to' => 'not an address'] + $msg, 422, 'INVALID_RECIPIENT'],
            [['to' => array_map(static fn ($i) => "r$i@example.net", range(1, 101))] + $msg, 422, 'INVALID_RECIPIENT'],
            [array_diff_key($msg, ['text' => 1]), 422, 'INVALID_MESSAGE'],
            [['headers' => ['From' => 'x@y.example']] + $msg, 422, 'INVALID_MESSAGE'],
            [['headers' => ['X-A' => "a\r\nBcc: x@y.example"]] + $msg, 422, 'INVALID_MESSAGE'],
            [['spam' => 1] + $msg, 422, 'INVALID_REQUEST'],
            [['raw' => base64_encode("To: a@example.net\r\n\r\nx")], 422, 'INVALID_FROM'],
        ] as $i => [$body, $status, $error]) {
            [$code, $res] = $this->api('POST', 'send', $body);
            $this->assertSame([$status, $error], [$code, $res['error']['code'] ?? null], 'case ' . $i);
        }
        // a project token cannot submit; recipient rules of the profile
        [$code, $res] = $this->api('POST', 'send', $msg, $this->manage);
        $this->assertSame([403, 'SEND_ACCESS_REQUIRED'], [$code, $res['error']['code']]);
        $this->api('PUT', 'send-profiles/shop', ['senders' => ['shop.example'], 'recipients' => ['partner.example'], 'limit_hour' => 5, 'limit_day' => 50], $this->manage);
        [$code, $res] = $this->api('POST', 'send', $msg);
        $this->assertSame([422, 'RECIPIENT_NOT_ALLOWED'], [$code, $res['error']['code']]);
        $this->assertSame(0, db_connect()->table('send_messages')->countAllResults());
        $this->assertSame([], glob($this->store . '/*/*/*/*') ?: [], 'nothing stored for refused mail');
        // refusals of the submission rules are journaled (malformed requests are only answered)
        $this->assertSame(5, db_connect()->table('journal')->where('event', 'send.rejected')->countAllResults());
    }

    public function testLimitsCountRecipientsOfProfileAndProject(): void
    {
        $msg = ['from' => 'info@shop.example', 'subject' => 'x', 'text' => 'x'];
        $this->assertSame(202, $this->api('POST', 'send', ['to' => ['a@example.net', 'b@example.net', 'c@example.net']] + $msg)[0]);
        [$code, $res] = $this->api('POST', 'send', ['to' => ['d@example.net', 'e@example.net', 'f@example.net']] + $msg);
        $this->assertSame([429, 'LIMIT_REACHED'], [$code, $res['error']['code']], 'profile: 5 per hour, 3 used, 3 more refused');
        $this->assertStringContainsString('profile', $res['error']['message']);
        $this->assertSame(202, $this->api('POST', 'send', ['to' => ['d@example.net', 'e@example.net']] + $msg)[0]);

        // the lower project limit wins
        service('projectService')->update('acme/mailer', ['send_limit_hour' => 5], ChangeContext::cli());
        $this->api('PUT', 'send-profiles/bulk', ['senders' => ['shop.example'], 'limit_hour' => 5, 'limit_day' => 50], $this->manage);
        $bulk = $this->api('POST', 'send-profiles/bulk/accesses', ['type' => 'api'], $this->manage)[1]['bearer'];
        [$code, $res] = $this->api('POST', 'send', ['to' => 'g@example.net'] + $msg, $bulk);
        $this->assertSame([429, 'Project limit reached: 5 mails per hour'], [$code, $res['error']['message']]);
    }

    public function testSmtpHandOffChecksEnvelopeAndConfigForTheMailPath(): void
    {
        $svc    = service('sendSubmissionService');
        $smtp   = $this->api('POST', 'send-profiles/shop/accesses', ['type' => 'smtp', 'username' => 'shop-relay'], $this->manage)[1];
        $ip     = $this->api('POST', 'send-profiles/shop/accesses', ['type' => 'ip', 'networks' => ['203.0.113.8/29']], $this->manage)[1];
        $access = $svc->access($smtp['data']['access_id']);
        $raw    = "Received: from client\r\nFrom: Shop <info@shop.example>\r\nTo: a@example.net\r\nSubject: SMTP\r\n\r\nx\r\n";

        $r = $svc->submit($access, 'bounce@shop.example', ['a@example.net'], $raw, ['via' => 'smtp', 'smtp_service' => 'submission', 'client_ip' => '198.51.100.5', 'actor' => 'smtp:shop-relay']);
        $sub = db_connect()->table('send_submissions')->where('submission_id', $r['submission_id'])->get()->getRowArray();
        $this->assertSame(['smtp', 'submission', 'bounce@shop.example', 'info@shop.example'], [$sub['via'], $sub['smtp_service'], $sub['envelope_from'], $sub['header_from']]);

        try {
            $svc->submit($access, 'x@evil.example', ['a@example.net'], $raw, ['via' => 'smtp']);
            $this->fail('envelope sender must be checked');
        } catch (SendRejected $e) {
            $this->assertSame(['SENDER_NOT_ALLOWED', '550', '5.7.1'], [$e->errorCode, $e->smtpCode, $e->smtpEnhanced]);
        }
        $this->api('PATCH', 'send-accesses/' . $smtp['data']['access_id'], ['enabled' => false], $this->manage);
        try {
            $svc->submit($svc->access($smtp['data']['access_id']), 'info@shop.example', ['a@example.net'], $raw, ['via' => 'smtp']);
            $this->fail('disabled access');
        } catch (SendRejected $e) {
            $this->assertSame('ACCESS_DISABLED', $e->errorCode);
        }
        $this->api('PATCH', 'send-accesses/' . $smtp['data']['access_id'], ['enabled' => true], $this->manage);

        // generated mail path config: the send login for mr-auth, rules and client networks for mr-milter and Postfix
        $files = (new ConfigBuilder(db_connect()))->build();
        $creds = json_decode($files['credentials.json'], true)['credentials'];
        $this->assertSame(['send', $smtp['data']['access_id']], [$creds['shop-relay']['scope'], $creds['shop-relay']['access_id']]);
        $send = json_decode($files['send.json'], true);
        $this->assertSame(['info@nodkim.example', 'shop.example'], $send['accesses'][$ip['data']['access_id']]['senders']);
        $this->assertSame([['network' => '203.0.113.8/29', 'access_id' => $ip['data']['access_id']]], $send['ip_accesses']);
        $this->assertStringContainsString("203.0.113.8/29 OK\n", $files['send_clients.cidr']);
        $this->assertCount(2, $send['accesses'], 'only smtp and ip accesses; api tokens never reach the mail path');

        config('Mailrunner')->sendEnabled = false;
        $files = (new ConfigBuilder(db_connect()))->build();
        $this->assertArrayNotHasKey('shop-relay', json_decode($files['credentials.json'], true)['credentials']);
        $this->assertSame([], json_decode($files['send.json'], true)['ip_accesses']);
    }

    public function testMimeHelpers(): void
    {
        $this->assertSame([['B, Person', 'b@example.org'], ['', 'c@xn--mller-kva.example']], Mime::parseAddressList('"B, Person" <b@example.org>, c@müller.example'));
        $this->assertSame([], Mime::parseAddressList('a@example.org, nonsense'));
        $this->assertNull(Mime::parseMailbox('a@example.org, b@example.org'));
        $this->assertSame(['', 'a@example.org'], Mime::parseMailbox('team: a@example.org;'));
        $this->assertSame('=?UTF-8?B?' . base64_encode('Grüße') . '?=', Mime::encodeWord('Grüße'));
        $p = Mime::prepare("From: a@shop.example\nMessage-ID: <x@y>\nBcc: z@q.example\n\nx", 'shop.example');
        $this->assertSame('<x@y>', $p['message_id']);
        $this->assertStringNotContainsString('Bcc', $p['raw']);
    }
}
