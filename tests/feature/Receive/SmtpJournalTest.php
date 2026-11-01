<?php

namespace Tests\Feature\Receive;

use App\Core\Secrets\NodeCrypto;
use App\Core\Support\ChangeContext;
use App\Modules\Receive\Libraries\Retention;
use App\Modules\Receive\Libraries\SmtpSessionImporter;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Journal of delivery attempts: import of mr-smtplog sessions, assignment to projects,
 * visibility per journal_scope, retention.
 *
 * @internal
 */
final class SmtpJournalTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $dir;
    private NodeCrypto $crypto;

    /** @var array<string, string> project ref => bearer */
    private array $bearer = [];

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(true);
        $this->dir = sys_get_temp_dir() . '/mr-smtp-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/smtp', 0o700, true);
        $this->crypto = NodeCrypto::fromKey(random_bytes(32));
        config('Mailrunner')->receiveEnabled = true;

        $cli = new ChangeContext('cli', 'phpunit');
        service('customerService')->create('acme', '', $cli);
        service('customerService')->create('other', '', $cli);
        foreach (['acme/inbound' => 'in.example.org', 'other/mail' => 'other.example.org'] as $ref => $domain) {
            service('projectService')->create($ref, 'analysis', '', $cli);
            service('routeService')->set($ref, [$domain], $cli);
            $this->bearer[$ref] = service('apiTokenService')->create($ref, 'test', 600, $cli)['bearer'];
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/{smtp,failed}/*', GLOB_BRACE) ?: []);
        @rmdir($this->dir . '/failed');
        rmdir($this->dir . '/smtp');
        rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * @param list<array<string, mixed>> $transactions
     */
    private function session(string $id, array $transactions, ?string $end = 'disconnect'): void
    {
        $s = [
            'session_id' => $id, 'service' => 'smtpd', 'started_at' => '2026-11-01T20:00:00.000000Z', 'ended_at' => '2026-11-01T20:00:01.000000Z',
            'client' => ['ip' => '192.0.2.10', 'port' => 40001, 'name' => 'mx.example.net'], 'helo' => 'mx.example.net',
            'tls' => ['protocol' => 'TLSv1.3', 'cipher' => 'TLS_AES_256_GCM_SHA384'], 'end' => $end, 'commands' => 'ehlo=1 commands=1',
            'warnings' => [], 'transactions' => $transactions,
        ];
        file_put_contents($this->dir . '/smtp/' . $id . '.json', $this->crypto->encryptJson($s, 'spool:' . $id . ':smtp'));
    }

    private function import(): array
    {
        return (new SmtpSessionImporter(db_connect(), service('journalWriter'), $this->crypto, $this->dir))->run();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function journal(string $ref, string $query = ''): array
    {
        $r = $this->withHeaders(['Authorization' => 'Bearer ' . $this->bearer[$ref]])->get('v1/journal?limit=1000' . $query);
        $r->assertStatus(200);

        return array_values(array_filter(
            json_decode($r->response()->getBody(), true)['data'],
            static fn ($e) => str_starts_with($e['event'], 'smtp.'),
        ));
    }

    private function setScope(string $ref, string $scope): void
    {
        service('projectService')->update($ref, ['journal_scope' => $scope], new ChangeContext('cli', 'phpunit'));
    }

    public function testAssignmentAndScopes(): void
    {
        $this->session('mrs_scanner', []);
        $this->session('mrs_mixed', [[
            'started_at' => '2026-11-01T20:00:00.500000Z', 'mail_from' => 'a@example.net', 'queue_id' => 'Q1', 'spool_ids' => [],
            'recipients' => [
                ['rcpt' => 'User0001@IN.example.org', 'status' => 'accepted', 'reply' => '250', 'by' => 'milter'],
                ['rcpt' => 'x@in.example.org', 'status' => 'rejected', 'reply' => '550 5.1.1 User unknown', 'by' => 'postfix'],
                ['rcpt' => 'b@other.example.org', 'status' => 'tempfail', 'reply' => '452 4.5.3 Too many recipients', 'by' => 'milter'],
                ['rcpt' => 'relay@foreign.example', 'status' => 'rejected', 'reply' => '554 5.7.1 Relay access denied', 'by' => 'postfix'],
            ],
            'result' => ['status' => 'accepted', 'reply' => '250'],
        ]]);
        $this->assertSame(['imported' => 2, 'duplicates' => 0, 'failed' => 0], $this->import());

        // same session handed over again after a restart: no second set of events
        $this->session('mrs_mixed', []);
        $this->assertSame(['imported' => 0, 'duplicates' => 1, 'failed' => 0], $this->import());

        // own (default): only the attempt with own recipients, other projects' recipients filtered out
        $own = $this->journal('acme/inbound');
        $this->assertCount(1, $own);
        $this->assertSame('smtp.attempt', $own[0]['event']);
        $this->assertSame('project', $own[0]['scope']);
        $this->assertSame(['User0001@IN.example.org', 'x@in.example.org'], array_column($own[0]['details']['recipients'], 'rcpt'));
        $this->assertSame('accepted', $own[0]['details']['result']['status']);
        $this->assertSame('192.0.2.10', $own[0]['details']['client']['ip']);

        // the other project: its recipient was deferred -> result "none" for its part of the attempt
        $other = $this->journal('other/mail');
        $this->assertSame(['b@other.example.org'], array_column($other[0]['details']['recipients'], 'rcpt'));
        $this->assertSame('none', $other[0]['details']['result']['status']);

        $this->setScope('acme/inbound', 'connections');
        $events = $this->journal('acme/inbound');
        $this->assertEqualsCanonicalizing(['smtp.connection', 'smtp.attempt'], array_column($events, 'event'));
        $connection = array_values(array_filter($events, static fn ($e) => $e['event'] === 'smtp.connection'))[0];
        $this->assertSame('unassigned', $connection['scope']);
        $this->assertSame('mx.example.net', $connection['details']['helo']);

        $this->setScope('acme/inbound', 'unassigned');
        $events = $this->journal('acme/inbound');
        $this->assertCount(3, $events);
        $unassignedAttempt = array_values(array_filter($events, static fn ($e) => $e['event'] === 'smtp.attempt' && $e['scope'] === 'unassigned'))[0];
        $this->assertSame(['relay@foreign.example'], array_column($unassignedAttempt['details']['recipients'], 'rcpt'));
        $this->assertSame('none', $unassignedAttempt['details']['result']['status']);

        // never another project's events
        foreach ($events as $e) {
            $this->assertNotContains('b@other.example.org', array_column($e['details']['recipients'] ?? [], 'rcpt'));
        }

        // event filter
        $this->assertSame(['smtp.connection'], array_column($this->journal('acme/inbound', '&event=smtp.connection'), 'event'));
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->bearer['acme/inbound']])->get('v1/journal?event=bad')->assertStatus(400);
    }

    public function testRetentionDeletesOldSmtpEntriesOnly(): void
    {
        $this->session('mrs_old', []);
        $this->import();
        db_connect()->table('journal')->where('event', 'smtp.connection')->update(['created_at' => '2026-01-01 00:00:00']);
        db_connect()->table('receive_smtp_sessions')->update(['imported_at' => '2026-01-01 00:00:00']);

        $stats = (new Retention(db_connect(), service('journalWriter'), null, 30))->run();
        $this->assertSame(1, $stats['smtp_journal']);
        $this->assertSame(0, db_connect()->table('receive_smtp_sessions')->countAllResults());
        $this->assertGreaterThan(0, db_connect()->table('journal')->where('event', 'project.created')->countAllResults());
    }

    public function testInvalidScopeRejected(): void
    {
        $this->expectException(\App\Core\Support\ConfigException::class);
        $this->setScope('acme/inbound', 'everything');
    }
}
