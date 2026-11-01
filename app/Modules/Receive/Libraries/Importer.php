<?php

namespace App\Modules\Receive\Libraries;

use App\Core\Journal\JournalWriter;
use App\Core\Secrets\NodeCrypto;
use App\Core\Support\ChangeContext;
use App\Core\Support\RandomId;
use App\Core\Support\Time;
use App\Core\Support\Transaction;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Imports analysed spool entries (spool/analyzed/) into receive_messages.
 *
 * One transaction per message (row + journal event "receive.received"); spool files are
 * removed only after commit. Re-running is safe: spool_id is unique.
 */
class Importer
{
    public const MESSAGE_PREFIX = 'mrm';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly JournalWriter $journal,
        private readonly NodeCrypto $crypto,
        private readonly string $spoolDir,
    ) {
    }

    /**
     * @return array{imported: int, duplicates: int, failed: int}
     */
    public function run(): array
    {
        $stats = ['imported' => 0, 'duplicates' => 0, 'failed' => 0];
        $lock  = fopen($this->spoolDir . '/.import.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return $stats; // another import is running
        }

        $files = glob($this->spoolDir . '/analyzed/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $spoolId = basename($file, '.json');

            try {
                $stats[$this->importOne($spoolId) ? 'imported' : 'duplicates']++;
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->fail($spoolId, $e);
            }
        }
        flock($lock, LOCK_UN);

        return $stats;
    }

    /**
     * @return bool true = imported, false = already imported (files removed)
     */
    private function importOne(string $spoolId): bool
    {
        $meta = json_decode(
            $this->crypto->decrypt((string) file_get_contents($this->spoolDir . '/analyzed/' . $spoolId . '.json'), 'spool:' . $spoolId . ':analyzed'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $imported = Transaction::run($this->db, function () use ($spoolId, $meta): bool {
            if ($this->db->table('receive_messages')->where('spool_id', $spoolId)->countAllResults() > 0) {
                return false;
            }
            $project = $this->db->table('projects')->where('id', (int) $meta['project_id'])->get()->getRowArray()
                ?? throw new \RuntimeException('Project #' . $meta['project_id'] . ' no longer exists');

            $messageId = RandomId::publicId(self::MESSAGE_PREFIX);
            $data      = [
                'record'      => self::record($meta),
                'debug'       => $meta['analysis']['debug'] ?? null,
                'raw_headers' => $meta['analysis']['raw_headers'] ?? null,
            ];
            $this->db->table('receive_messages')->insert([
                'message_id'  => $messageId,
                'project_id'  => $project['id'],
                'spool_id'    => $spoolId,
                'profile'     => $meta['profile'],
                'status'      => $meta['profile'] === 'analysis' ? 'analyzed' : 'pending',
                'received_at' => (new \DateTimeImmutable($meta['received_at']))->format(Time::DB_FORMAT),
                'imported_at' => Time::now(),
                'queue_id'    => mb_substr((string) $meta['queue_id'], 0, 32),
                'mail_from'   => mb_substr((string) $meta['mail_from'], 0, 320),
                'rcpt'        => mb_substr((string) $meta['rcpt'], 0, 320),
                'client_ip'   => mb_substr((string) $meta['client_ip'], 0, 45),
                'size'        => (int) $meta['size'],
                'sha256'      => (string) $meta['sha256'],
                'raw_stored'  => 0,
                'data_enc'    => $this->crypto->encryptJson($data, 'db:receive_messages:' . $messageId),
            ]);
            $this->journal->append('receive.received', new ChangeContext('system', 'mr:import'), (int) $project['customer_id'], (int) $project['id'], [
                'message_id' => $messageId, 'status' => $meta['profile'] === 'analysis' ? 'analyzed' : 'pending', 'profile' => $meta['profile'],
            ]);

            return true;
        });

        @unlink($this->spoolDir . '/analyzed/' . $spoolId . '.eml');
        unlink($this->spoolDir . '/analyzed/' . $spoolId . '.json');

        return $imported;
    }

    /**
     * Header data as in SmartMailFlow's "emails" row (without raw).
     *
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    public static function record(array $meta): array
    {
        $a = $meta['analysis'] ?? [];

        return [
            'queue_id'       => $a['queue_id'] ?? $meta['queue_id'],
            'smtp_mail_from' => $a['smtp_mail_from'] ?? $meta['mail_from'],
            'smtp_rcpt_to'   => $a['smtp_rcpt_to'] ?? $meta['rcpt'],
            'header_from'    => $a['header_from'] ?? null,
            'header_to'      => $a['header_to'] ?? null,
            'subject'        => $a['subject'] ?? null,
            'date_hdr'       => $a['date_hdr'] ?? null,
            'spf_status'     => $a['spf_status'] ?? null,
            'dkim_status'    => $a['dkim_status'] ?? null,
            'mail_id'        => $a['mail_id'] ?? null,
            'created'        => $meta['received_at'],
        ];
    }

    private function fail(string $spoolId, Throwable $e): void
    {
        $failed = $this->spoolDir . '/failed';
        foreach (['eml', 'json'] as $ext) {
            $src = $this->spoolDir . '/analyzed/' . $spoolId . '.' . $ext;
            if (is_file($src)) {
                rename($src, $failed . '/' . $spoolId . '.analyzed.' . $ext);
            }
        }
        file_put_contents($failed . '/' . $spoolId . '.reason', gmdate('Y-m-d\TH:i:s\Z') . ' stage=import ' . $e::class . ': ' . $e->getMessage() . "\n");

        try {
            Transaction::run($this->db, function () use ($spoolId, $e): void {
                $this->journal->append('receive.import_failed', new ChangeContext('system', 'mr:import'), null, null, [
                    'spool_id' => $spoolId, 'error' => $e::class,
                ]);
            });
        } catch (Throwable) {
            // database down: the .reason file is the record
        }
        log_message('error', 'mr:import failed for {id}: {msg}', ['id' => $spoolId, 'msg' => $e->getMessage()]);
    }
}
