<?php

namespace Tests\Feature\Core;

use App\Core\Node\SecretScan;
use App\Core\Node\SelfTest;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Secret leak scan: values from env files and the node key must be found in DB columns and log files,
 * configuration values are ignored, findings never contain the value.
 *
 * @internal
 */
final class SecretScanTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/mr-secretscan-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/logs', 0o700, true);
        mkdir($this->dir . '/spool', 0o700, true);
        file_put_contents($this->dir . '/secrets.env', "# test\nSPAMHAUS_DQS_KEY = \"abcdefghij0123456789xyzuvw\"\nSHORT = abc\n");
        file_put_contents($this->dir . '/app.env', "database.default.username = mailrunner_app_long_name\nMAILRUNNER_DATA_DIR = /var/lib/mailrunner\n");
        file_put_contents($this->dir . '/node.key', base64_encode(str_repeat("\x01", 32)) . "\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function scan(): SecretScan
    {
        return new SecretScan($this->db, [$this->dir . '/secrets.env', $this->dir . '/app.env'], $this->dir . '/node.key',
            [$this->dir], [$this->dir . '/spool'], $this->dir . '/accepted.json');
    }

    public function testSecretsIgnoreConfiguration(): void
    {
        $names = array_values($this->scan()->secrets());
        $this->assertContains('secrets.env:SPAMHAUS_DQS_KEY', $names);
        $this->assertContains('node.key', $names);
        $this->assertNotContains('app.env:database.default.username', $names);
        $this->assertNotContains('secrets.env:SHORT', $names);
    }

    public function testCleanThenLeakInDatabaseAndLog(): void
    {
        $check = $this->scan()->check();
        $this->assertSame('ok', $check['status'], $check['detail']);

        $this->db->table('journal')->insert(['id' => (int) $this->db->query('SELECT COALESCE(MAX(id), 0) + 1 AS n FROM journal')->getRow('n'), 'created_at' => '2026-11-02 08:00:00', 'event' => 'node.selftest', 'source' => 'system',
            'actor' => 'test', 'details' => json_encode(['detail' => 'query 2.0.0.127.abcdefghij0123456789xyzuvw.zen.dq.spamhaus.net'])]);
        file_put_contents($this->dir . '/logs/log-2026-11-02.log', 'key=' . bin2hex(str_repeat("\x01", 32)));
        file_put_contents($this->dir . '/spool/encrypted', 'abcdefghij0123456789xyzuvw'); // skipped directory

        $r     = $this->scan()->run();
        $where = array_map(static fn ($f) => $f['secret'] . ' @ ' . $f['where'], $r['findings']);
        $this->assertContains('secrets.env:SPAMHAUS_DQS_KEY @ db journal.details', $where);
        $this->assertContains('node.key @ ' . $this->dir . '/logs/log-2026-11-02.log', $where);
        $this->assertCount(2, $r['findings']);

        $check = $this->scan()->check();
        $this->assertSame('fail', $check['status']);
        $this->assertStringNotContainsString('abcdefghij0123456789xyzuvw', $check['detail']);

        $result = SelfTest::withCheck(['status' => 'pass', 'ok' => 3, 'failed' => [], 'checks' => []], $check);
        $this->assertSame(['fail', ['base.secret_leak']], [$result['status'], $result['failed']]);
    }

    public function testAcceptedFindingPassesUntilSomethingChanges(): void
    {
        $insert = fn () => $this->db->table('journal')->insert(['id' => (int) $this->db->query('SELECT COALESCE(MAX(id), 0) + 1 AS n FROM journal')->getRow('n'),
            'created_at' => '2026-11-02 08:00:00', 'event' => 'node.selftest', 'source' => 'system', 'actor' => 'test',
            'details' => json_encode(['detail' => 'x.abcdefghij0123456789xyzuvw.y'])]);
        $insert();
        $this->assertSame('fail', $this->scan()->check()['status']);

        $this->assertSame(1, $this->scan()->accept($this->scan()->run()['findings']));
        $this->assertStringNotContainsString('abcdefghij0123456789xyzuvw', (string) file_get_contents($this->dir . '/accepted.json'));
        $check = $this->scan()->check();
        $this->assertSame('ok', $check['status']);
        $this->assertStringContainsString('1 accepted finding', $check['detail']);

        $insert(); // a second occurrence is new
        $this->assertSame('fail', $this->scan()->check()['status']);

        $this->scan()->accept($this->scan()->run()['findings']);
        $this->assertSame('ok', $this->scan()->check()['status']);
        // rotated key: the same place with a new value fails again
        file_put_contents($this->dir . '/secrets.env', "SPAMHAUS_DQS_KEY = \"abcdefghij0123456789xyzuvw\"\nOTHER_TOKEN = zyxwvutsrqponmlk\n");
        file_put_contents($this->dir . '/logs/x.log', 'zyxwvutsrqponmlk');
        $this->assertSame('fail', $this->scan()->check()['status']);
    }
}
