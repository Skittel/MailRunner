<?php

namespace App\Core\Node;

use App\Core\Journal\JournalWriter;
use App\Core\Support\ChangeContext;
use App\Core\Support\Transaction;
use CodeIgniter\Database\BaseConnection;

/**
 * Node self test (decision E-17): runs mailpath/receive/bin/mr-selftest, stores the last run as files
 * and records the result as journal event "node.selftest" (no project, visible to every project).
 */
class SelfTest
{
    public const EVENT = 'node.selftest';

    /** Reserved customer of the self test projects (system/selftest, system/selftest-isolation; install.sh). */
    public const CUSTOMER = 'system';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly JournalWriter $journal,
        private readonly string $script,
        private readonly string $outDir,
        private readonly ?SecretScan $secretScan = null,
    ) {
    }

    /**
     * Adds a check computed in PHP to the result of mr-selftest and updates status and counters.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $check
     *
     * @return array<string, mixed>
     */
    public static function withCheck(array $result, array $check): array
    {
        $result['checks'][] = $check;
        if ($check['status'] === 'ok') {
            $result['ok'] = ($result['ok'] ?? 0) + 1;
        } elseif ($check['status'] === 'fail') {
            $result['failed'][] = $check['name'];
            $result['status']   = 'fail';
        } elseif ($check['status'] === 'warn') {
            $result['warnings'][] = $check['name'];
        }

        return $result;
    }

    /**
     * @return array<string, mixed> result of mr-selftest (status pass|fail|error)
     */
    /**
     * @param array{helo?: string, ips?: list<string>}|null $send send role settings (E-27); null = send role off
     */
    public function run(string $hostname, bool $baseOnly = false, string $mxNames = '', ?array $send = null): array
    {
        $after = (int) ($this->db->query('SELECT COALESCE(MAX(id), 0) AS id FROM journal')->getRow('id'));
        $cmd   = [$this->script, '--hostname=' . $hostname, '--journal-after=' . $after];
        if ($baseOnly) {
            $cmd[] = '--base-only';
        }
        if ($mxNames !== '') {
            $cmd[] = '--mx-names=' . $mxNames;
        }
        if ($send !== null) {
            $cmd[] = '--send-helo=' . ($send['helo'] ?? '');
            $cmd[] = '--send-ips=' . implode(',', $send['ips'] ?? []);
            if (($send['bounce_source'] ?? '') !== '') {
                $cmd[] = '--bounce-source=' . $send['bounce_source'];
            }
        }
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            $result = ['status' => 'error', 'error' => 'cannot start ' . $this->script];
        } else {
            $out  = stream_get_contents($pipes[1]);
            $err  = stream_get_contents($pipes[2]);
            $code = proc_close($proc);
            $result = json_decode((string) $out, true);
            if (! is_array($result)) {
                $result = ['status' => 'error', 'error' => 'exit ' . $code . ': ' . substr(trim((string) $err), -300)];
            }
        }
        if (isset($result['checks']) && $this->secretScan !== null) {
            $result = self::withCheck($result, $this->secretScan->check());
        }
        $this->record($result);

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     */
    public function record(array $result): void
    {
        $checks  = $result['checks'] ?? [];
        $details = [
            'status'      => $result['status'],
            'version'     => (new NodeInfo(config('Mailrunner')))->version(),
            'started_at'  => $result['started_at'] ?? null,
            'finished_at' => $result['finished_at'] ?? null,
            'duration_ms' => $result['duration_ms'] ?? null,
            'ok'          => $result['ok'] ?? 0,
            'failed'      => self::named($checks, 'fail'),
            'warnings'    => self::named($checks, 'warn'),
            'skipped'     => self::named($checks, 'skip'),
        ];
        if (isset($result['error'])) {
            $details['error'] = $result['error'];
        }
        Transaction::run($this->db, function () use ($details): void {
            $this->journal->append(self::EVENT, new ChangeContext('system', 'mr:selftest'), null, null, $details);
        });

        if (is_dir($this->outDir) || @mkdir($this->outDir, 0o750, true)) {
            file_put_contents($this->outDir . '/last.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            $lines = array_map(static fn ($c) => sprintf('%-5s %-36s %6s  %s', strtoupper($c['status']), $c['name'], $c['ms'] === null ? '' : $c['ms'] . 'ms', $c['detail']), $checks);
            file_put_contents($this->outDir . '/last.txt', sprintf("%s %s %s\n", $details['finished_at'] ?? '-', strtoupper($details['status']), $result['error'] ?? '') . implode("\n", $lines) . "\n");
        }
    }

    /**
     * Last recorded self test (for /v1/node/status), or null.
     *
     * @return array<string, mixed>|null
     */
    public static function last(BaseConnection $db): ?array
    {
        $row = $db->table('journal')->select('details')->where('event', self::EVENT)->where('project_id', null)
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        $d = json_decode((string) $row['details'], true) ?: [];

        return ['status' => $d['status'] ?? null, 'finished_at' => $d['finished_at'] ?? null, 'failed' => array_column($d['failed'] ?? [], 'name')];
    }

    /**
     * @param list<array<string, mixed>> $checks
     *
     * @return list<array{name: string, detail: string}>
     */
    private static function named(array $checks, string $status): array
    {
        return array_values(array_map(
            static fn ($c) => ['name' => (string) $c['name'], 'detail' => (string) $c['detail']],
            array_filter($checks, static fn ($c) => ($c['status'] ?? '') === $status),
        ));
    }
}
