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
 * Imports the metadata hand-off of mr-milter (spool/analyzed/<id>.json) into receive_messages.
 * Mail and analysis stay as encrypted files in store/ (see MessageStore).
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
            $status    = $meta['profile'] === 'analysis' ? 'analyzed' : 'pending';
            $this->db->table('receive_messages')->insert([
                'message_id'      => $messageId,
                'project_id'      => $project['id'],
                'spool_id'        => $spoolId,
                'profile'         => $meta['profile'],
                'status'          => $status,
                'received_at'     => (new \DateTimeImmutable($meta['received_at']))->format(Time::DB_FORMAT),
                'imported_at'     => Time::now(),
                'queue_id'        => self::cut($meta['queue_id'] ?? '', 32),
                'mail_from'       => self::cut($meta['mail_from'] ?? '', 320),
                'rcpt'            => self::cut($meta['rcpt'] ?? '', 320),
                'client_ip'       => self::cut($meta['client_ip'] ?? '', 45),
                'helo'            => self::cut($meta['helo'] ?? '', 255),
                'client_name'     => self::cut($meta['client_name'] ?? '', 255),
                'client_asn'       => isset($meta['client_info']['asn']) ? (int) $meta['client_info']['asn'] : null,
                'client_as_org'    => self::cutOrNull($meta['client_info']['as_organization'] ?? null, 255),
                'client_country'   => self::cutOrNull($meta['client_info']['country_code'] ?? null, 2),
                'client_city'      => self::cutOrNull($meta['client_info']['city'] ?? null, 128),
                'client_continent' => self::cutOrNull($meta['client_info']['continent_code'] ?? null, 2),
                'client_carrier'   => self::cutOrNull($meta['client_info']['carrier_name'] ?? null, 128),
                'client_is_threat' => isset($meta['client_info']['is_threat']) ? ($meta['client_info']['is_threat'] ? 1 : 0) : null,
                'header_from'     => self::cutOrNull($meta['header_from'] ?? null, 998),
                'header_to'       => self::cutOrNull($meta['header_to'] ?? null, 65000),
                'subject'         => self::cutOrNull($meta['subject'] ?? null, 998),
                'date_hdr'        => self::cutOrNull($meta['date_hdr'] ?? null, 255),
                'message_id_hdr'  => self::cutOrNull($meta['message_id_hdr'] ?? null, 998),
                'attachments'     => json_encode($meta['attachments'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'spf_status'      => self::cutOrNull($meta['spf_status'] ?? null, 16),
                'dkim_status'     => self::cutOrNull($meta['dkim_status'] ?? null, 16),
                'analysis_status' => ($meta['analysis_status'] ?? 'ok') === 'timeout' ? 'timeout' : 'ok',
                'analysis_ms'     => isset($meta['analysis_ms']) ? (int) $meta['analysis_ms'] : null,
                'analysis_path'   => $meta['analysis_path'] ?? null,
                'raw_path'        => $meta['raw_path'] ?? null,
                'size'            => (int) $meta['size'],
                'sha256'          => (string) $meta['sha256'],
            ]);
            $this->journal->append('receive.received', new ChangeContext('system', 'mr:import'), (int) $project['customer_id'], (int) $project['id'], [
                'message_id' => $messageId, 'status' => $status, 'profile' => $meta['profile'],
                'analysis' => $meta['analysis_status'] ?? 'ok',
            ]);

            return true;
        });

        @unlink($this->spoolDir . '/analyzed/' . $spoolId . '.eml');
        unlink($this->spoolDir . '/analyzed/' . $spoolId . '.json');

        return $imported;
    }

    private static function cut(?string $value, int $length): string
    {
        return mb_substr((string) $value, 0, $length);
    }

    private static function cutOrNull(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
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
