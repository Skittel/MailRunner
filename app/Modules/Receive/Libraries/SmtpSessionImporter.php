<?php

namespace App\Modules\Receive\Libraries;

use App\Core\Journal\JournalWriter;
use App\Core\Secrets\NodeCrypto;
use App\Core\Support\ChangeContext;
use App\Core\Support\Time;
use App\Core\Support\Transaction;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Imports SMTP sessions from mr-smtplog (spool/smtp/<session_id>.json) into the journal.
 *
 * - Session without MAIL FROM          -> one "smtp.connection" event without project
 * - Each transaction (MAIL FROM ...)    -> one "smtp.attempt" event per project of its recipients
 *                                          (route lookup: exact address, then domain), recipients
 *                                          without a project -> one event without project.
 *   An event only lists the recipients and message ids of its own project.
 *
 * Which events without project a token sees is the project setting journal_scope.
 */
class SmtpSessionImporter
{
    public const EVENT_CONNECTION = 'smtp.connection';
    public const EVENT_ATTEMPT    = 'smtp.attempt';

    /** @var array<string, array{customer_id: int, project_id: int}>|null */
    private ?array $routes = null;

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
        $files = glob($this->spoolDir . '/smtp/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $id = basename($file, '.json');

            try {
                $stats[$this->importOne($id, $file) ? 'imported' : 'duplicates']++;
                unlink($file);
            } catch (Throwable $e) {
                $stats['failed']++;
                @mkdir($this->spoolDir . '/failed', 0o750, true);
                rename($file, $this->spoolDir . '/failed/' . $id . '.smtp.json');
                file_put_contents($this->spoolDir . '/failed/' . $id . '.reason', gmdate('Y-m-d\TH:i:s\Z') . ' stage=smtp-import ' . $e::class . ': ' . $e->getMessage() . "\n");
                log_message('error', 'mr:import smtp session {id} failed: {msg}', ['id' => $id, 'msg' => $e->getMessage()]);
            }
        }

        return $stats;
    }

    private function importOne(string $id, string $file): bool
    {
        $session = json_decode($this->crypto->decrypt((string) file_get_contents($file), 'spool:' . $id . ':smtp'), true, 512, JSON_THROW_ON_ERROR);

        return Transaction::run($this->db, function () use ($id, $session): bool {
            if ($this->db->table('receive_smtp_sessions')->where('session_id', $id)->countAllResults() > 0) {
                return false;
            }
            $this->db->table('receive_smtp_sessions')->insert([
                'session_id'  => $id,
                'started_at'  => (new \DateTimeImmutable($session['started_at']))->format(Time::DB_FORMAT),
                'imported_at' => Time::now(),
            ]);
            foreach (self::events($session, fn (string $rcpt) => $this->projectFor($rcpt), fn (array $spoolIds, int $projectId) => $this->messageIds($spoolIds, $projectId)) as $event) {
                $this->journal->append($event['event'], new ChangeContext('system', 'mr-smtplog'), $event['customer_id'], $event['project_id'], $event['details']);
            }

            return true;
        });
    }

    /**
     * Splits a session into journal events (pure; lookups are passed in for tests).
     *
     * @param array<string, mixed>                                                 $session
     * @param callable(string): (array{customer_id: int, project_id: int}|null)    $projectFor
     * @param callable(list<string>, int): list<string>                            $messageIds
     *
     * @return list<array{event: string, customer_id: int|null, project_id: int|null, details: array<string, mixed>}>
     */
    public static function events(array $session, callable $projectFor, callable $messageIds): array
    {
        $base = [
            'session_id' => $session['session_id'],
            'started_at'  => $session['started_at'],
            'ended_at'    => $session['ended_at'],
            'duration_ms' => $session['duration_ms'] ?? null,
            'client'     => $session['client'],
            'helo'       => $session['helo'],
            'tls'        => $session['tls'],
        ];
        if (($session['transactions'] ?? []) === []) {
            return [[
                'event'       => self::EVENT_CONNECTION,
                'customer_id' => null,
                'project_id'  => null,
                'details'     => $base + [
                    'end'      => $session['end'],
                    'tls_ms'   => $session['tls_ms'] ?? null,
                    'commands' => $session['commands'],
                    'warnings' => $session['warnings'] ?? [],
                ],
            ]];
        }

        $events = [];
        foreach ($session['transactions'] as $n => $tx) {
            $groups = []; // project_id|'' => [project, recipients]
            foreach ($tx['recipients'] as $r) {
                $project = $projectFor((string) $r['rcpt']);
                $key     = $project === null ? '' : (string) $project['project_id'];
                $groups[$key] ??= ['project' => $project, 'recipients' => []];
                $groups[$key]['recipients'][] = $r;
            }
            if ($groups === []) {
                $groups[''] = ['project' => null, 'recipients' => []];
            }
            foreach ($groups as $group) {
                $project = $group['project'];
                $accepted = array_values(array_filter($group['recipients'], static fn ($r) => $r['status'] === 'accepted'));
                $result   = $tx['result'];
                if ($accepted === [] && $group['recipients'] !== []) {
                    // the data result belongs to other recipients; for this group the attempt ended at RCPT
                    $result = ['status' => 'none', 'reply' => null];
                }
                $events[] = [
                    'event'       => self::EVENT_ATTEMPT,
                    'customer_id' => $project['customer_id'] ?? null,
                    'project_id'  => $project['project_id'] ?? null,
                    'details'     => $base + [
                        'attempt'     => $n + 1,
                        'mail_from'   => $tx['mail_from'],
                        'queue_id'    => $tx['queue_id'],
                        'recipients'  => array_map(static fn ($r) => ['rcpt' => $r['rcpt'], 'status' => $r['status'], 'reply' => $r['reply']], $group['recipients']),
                        'result'      => ['status' => $result['status'] ?? 'none', 'reply' => $result['reply'] ?? null],
                        'message_ids' => $project === null ? [] : $messageIds($tx['spool_ids'] ?? [], (int) $project['project_id']),
                        'timing'      => $tx['timing'] ?? null,
                        'end'         => $session['end'],
                    ],
                ];
            }
        }

        return $events;
    }

    /**
     * @return array{customer_id: int, project_id: int}|null
     */
    private function projectFor(string $rcpt): ?array
    {
        if ($this->routes === null) {
            $this->routes = [];
            $rows         = $this->db->query('SELECT r.route, p.id AS project_id, p.customer_id FROM receive_routes r JOIN projects p ON p.id = r.project_id')->getResultArray();
            foreach ($rows as $row) {
                $this->routes[$row['route']] = ['customer_id' => (int) $row['customer_id'], 'project_id' => (int) $row['project_id']];
            }
        }
        $rcpt = strtolower(trim($rcpt));
        $at   = strrpos($rcpt, '@');

        return $this->routes[$rcpt] ?? ($at === false ? null : ($this->routes[substr($rcpt, $at + 1)] ?? null));
    }

    /**
     * @param list<string> $spoolIds
     *
     * @return list<string>
     */
    private function messageIds(array $spoolIds, int $projectId): array
    {
        if ($spoolIds === []) {
            return [];
        }

        return array_column($this->db->table('receive_messages')->select('message_id')
            ->where('project_id', $projectId)->whereIn('spool_id', $spoolIds)->orderBy('id')->get()->getResultArray(), 'message_id');
    }
}
