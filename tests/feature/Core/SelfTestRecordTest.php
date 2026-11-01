<?php

namespace Tests\Feature\Core;

use App\Core\Node\SelfTest;
use App\Core\Support\ChangeContext;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Self test result: journal event node.selftest (visible to every project), files, /v1/node/status.
 *
 * @internal
 */
final class SelfTestRecordTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    public function testResultIsJournaledAndShownInNodeStatus(): void
    {
        Services::reset(true);
        config('Mailrunner')->receiveEnabled = true;
        $cli = new ChangeContext('cli', 'phpunit');
        service('customerService')->create('acme', '', $cli);
        service('projectService')->create('acme/inbound', 'analysis', '', $cli);
        $bearer = service('apiTokenService')->create('acme/inbound', 'test', 600, $cli)['bearer'];

        $dir = sys_get_temp_dir() . '/mr-selftest-' . bin2hex(random_bytes(4));
        $st  = new SelfTest(db_connect(), service('journalWriter'), '/bin/false', $dir);
        $st->record([
            'status' => 'fail', 'started_at' => '2026-11-01T22:00:00Z', 'finished_at' => '2026-11-01T22:01:00Z', 'duration_ms' => 60000, 'ok' => 1,
            'checks' => [
                ['name' => 'base.disk', 'group' => 'base', 'status' => 'ok', 'detail' => '80 % free on /', 'ms' => 1],
                ['name' => 'smtp.v4.certificate', 'group' => 'smtp', 'status' => 'fail', 'detail' => '3 days left', 'ms' => 2],
                ['name' => 'base.mail_queue', 'group' => 'base', 'status' => 'warn', 'detail' => '60 in queue', 'ms' => 3],
            ],
        ]);

        $this->assertStringContainsString('FAIL  smtp.v4.certificate', (string) file_get_contents($dir . '/last.txt'));
        $this->assertSame('fail', json_decode((string) file_get_contents($dir . '/last.json'), true)['status']);
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);

        $r = $this->withHeaders(['Authorization' => 'Bearer ' . $bearer])->get('v1/journal?event=node.selftest');
        $events = json_decode($r->response()->getBody(), true)['data'];
        $this->assertCount(1, $events);
        $this->assertSame('node', $events[0]['scope']);
        $this->assertSame([['name' => 'smtp.v4.certificate', 'detail' => '3 days left']], $events[0]['details']['failed']);
        $this->assertSame('base.mail_queue', $events[0]['details']['warnings'][0]['name']);

        $status = json_decode($this->withHeaders(['Authorization' => 'Bearer ' . $bearer])->get('v1/node/status')->response()->getBody(), true);
        $this->assertSame(['status' => 'fail', 'finished_at' => '2026-11-01T22:01:00Z', 'failed' => ['smtp.v4.certificate']], $status['selftest']);
    }

    public function testBrokenScriptIsRecordedAsError(): void
    {
        Services::reset(true);
        $dir    = sys_get_temp_dir() . '/mr-selftest-' . bin2hex(random_bytes(4));
        $result = (new SelfTest(db_connect(), service('journalWriter'), '/bin/false', $dir))->run('node.example.net');
        $this->assertSame('error', $result['status']);
        $this->assertSame(1, db_connect()->table('journal')->where('event', 'node.selftest')->countAllResults());
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }
}
