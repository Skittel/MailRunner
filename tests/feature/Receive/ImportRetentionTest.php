<?php

namespace Tests\Feature\Receive;

use App\Core\Secrets\NodeCrypto;
use App\Core\Support\ChangeContext;
use App\Modules\Receive\Libraries\Importer;
use App\Modules\Receive\Libraries\MessageStore;
use App\Modules\Receive\Libraries\Retention;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;

/**
 * Hand-off of mr-milter -> database, analysis file access, deletion of files by retention.
 *
 * @internal
 */
final class ImportRetentionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $dir;
    private NodeCrypto $crypto;

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(true);
        $cli = new ChangeContext('cli', 'phpunit');
        service('customerService')->create('smartmailflow', '', $cli);
        service('projectService')->create('smartmailflow/test', 'analysis', '', $cli);
        $this->crypto = NodeCrypto::fromKey(random_bytes(32));
        $this->dir    = sys_get_temp_dir() . '/mr-import-' . bin2hex(random_bytes(4));
        foreach (['spool/analyzed', 'spool/failed', 'store/2026/11/01'] as $d) {
            mkdir($this->dir . '/' . $d, 0o700, true);
        }
    }

    private function handoff(string $id): void
    {
        $analysisPath = '2026/11/01/' . $id . '.analysis.json.enc';
        file_put_contents($this->dir . '/store/' . $analysisPath, $this->crypto->encryptJson(
            ['record' => ['mail_id' => 'test0001'], 'debug' => ['spf' => ['result' => 'pass']], 'raw_headers' => 'From: x'],
            'store:' . $id . ':analysis',
        ));
        $project = service('projectService')->get('smartmailflow/test');
        $meta    = [
            'spool_id' => $id, 'received_at' => '2026-11-01T16:00:00Z', 'queue_id' => '4AbCdEfGhJ', 'client_ip' => '192.0.2.10',
            'client_name' => 'mx.example.net', 'helo' => 'mx.example.net', 'mail_from' => 'joerg@example.net',
            'rcpt' => 'test0001@test.smartmailflow.email', 'project_id' => (int) $project['id'], 'project' => 'smartmailflow/test',
            'profile' => 'analysis', 'size' => 1234, 'sha256' => str_repeat('a', 64),
            'attachments' => [['name' => 'rechnung.pdf', 'size' => 9, 'content_type' => 'application/pdf', 'disposition' => 'attachment']],
            'header_from' => 'Jörg <joerg@example.net>', 'header_to' => 'test0001@test.smartmailflow.email', 'subject' => 'Hallo',
            'date_hdr' => 'Sun, 01 Nov 2026 16:00:00 +0000', 'message_id_hdr' => '<x@example.net>', 'spf_status' => 'pass',
            'dkim_status' => 'none', 'analysis_status' => 'ok',
            'client_info' => ['asn' => 8075, 'as_organization' => 'Microsoft Corporation', 'country_code' => 'DE', 'city' => 'Frankfurt am Main',
                'continent_code' => 'EU', 'carrier_name' => null, 'is_threat' => false], 'analysis_ms' => 2700, 'analysis_path' => $analysisPath, 'raw_path' => null,
        ];
        file_put_contents($this->dir . '/spool/analyzed/' . $id . '.json', $this->crypto->encryptJson($meta, 'spool:' . $id . ':analyzed'));
    }

    public function testImportStoresMetadataAndRetentionDeletesFiles(): void
    {
        $id = '20261101T160000Z-0011223344556677';
        $this->handoff($id);
        $importer = new Importer(db_connect(), service('journalWriter'), $this->crypto, $this->dir . '/spool');

        $this->assertSame(['imported' => 1, 'duplicates' => 0, 'failed' => 0], $importer->run());
        $this->assertSame([], glob($this->dir . '/spool/analyzed/*'));

        $row = db_connect()->table('receive_messages')->get()->getRowArray();
        $this->assertSame('Hallo', $row['subject']);
        $this->assertSame('Jörg <joerg@example.net>', $row['header_from']);
        $this->assertSame('rechnung.pdf', json_decode($row['attachments'], true)[0]['name']);
        $this->assertNull($row['raw_path']);
        $this->assertSame('analyzed', $row['status']);
        $this->assertSame('8075', (string) $row['client_asn']);
        $this->assertSame('DE', $row['client_country']);
        $this->assertSame('0', (string) $row['client_is_threat']);
        $this->assertNull($row['client_carrier']);

        $files = new MessageStore($this->dir . '/store', $this->crypto);
        $this->assertSame('test0001', $files->analysis($row['spool_id'], $row['analysis_path'])['record']['mail_id']);

        // same hand-off again: no duplicate row
        $this->handoff($id);
        $this->assertSame(['imported' => 0, 'duplicates' => 1, 'failed' => 0], $importer->run());

        // acked long ago -> content (analysis file) deleted, metadata stays
        db_connect()->table('receive_messages')->update(['acked_at' => '2026-01-01 00:00:00']);
        $stats = (new Retention(db_connect(), service('journalWriter'), $files))->run();
        $this->assertSame(1, $stats['deleted']);
        $this->assertFileDoesNotExist($this->dir . '/store/' . $row['analysis_path']);
        $row = db_connect()->table('receive_messages')->get()->getRowArray();
        $this->assertNull($row['analysis_path']);
        $this->assertSame('ack_retention', $row['delete_reason']);
        $this->assertSame('Hallo', $row['subject']);
    }
}
