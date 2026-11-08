<?php

namespace App\Core\Admin;

use App\Core\Support\ChangeContext;
use App\Core\Support\Time;
use Throwable;

/**
 * Queue of configuration changes from MailRunner Admin while MAILRUNNER_ADMIN_CONFIG=manual.
 * A newer change for the same object replaces the open one (the admin always sends the full desired state).
 */
class PendingChanges
{
    public function queue(string $kind, string $ref, array $payload, string $actor): int
    {
        $db   = db_connect();
        $open = $db->table('admin_pending_changes')->select('id')->where('kind', $kind)->where('ref', $ref)->where('decided_at', null)->get()->getRow('id');
        $row  = ['payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'actor' => mb_substr($actor, 0, 255), 'received_at' => Time::now()];
        if ($open !== null) {
            $db->table('admin_pending_changes')->where('id', $open)->update($row);
            $id = (int) $open;
        } else {
            $db->table('admin_pending_changes')->insert($row + ['kind' => $kind, 'ref' => $ref]);
            $id = (int) $db->insertID();
        }
        \App\Core\Support\Transaction::run($db, fn () => service('journalWriter')->append('admin.change_queued', new ChangeContext('admin', $actor), null, null,
            ['kind' => $kind, 'ref' => $ref, 'pending_id' => $id]));

        return $id;
    }

    /** @return list<array<string, mixed>> open changes, oldest first, payload decoded */
    public function open(): array
    {
        $rows = db_connect()->table('admin_pending_changes')->where('decided_at', null)->orderBy('id')->get()->getResultArray();

        return array_map(static fn ($r) => ['payload' => json_decode($r['payload'], true) ?: []] + $r, $rows);
    }

    /** Payload of the open change for this object, or null. */
    public function openPayload(string $kind, string $ref): ?array
    {
        $row = db_connect()->table('admin_pending_changes')->select('payload')->where('kind', $kind)->where('ref', $ref)->where('decided_at', null)->get()->getRow('payload');

        return $row === null ? null : (json_decode($row, true) ?: []);
    }

    public function count(): int
    {
        return db_connect()->table('admin_pending_changes')->where('decided_at', null)->countAllResults();
    }

    /**
     * Applies one open change with the admin as actor and the approving CLI user noted. Returns null or the error.
     *
     * @param array<string, mixed> $change row from open()
     */
    public function apply(array $change, string $approvedBy): ?string
    {
        $ctx     = new ChangeContext('admin', $change['actor'] . ' (approved by ' . $approvedBy . ')');
        $changes = new AdminChanges();
        try {
            match ($change['kind']) {
                'customer'     => $changes->customer($change['ref'], $change['payload'], $ctx),
                'project'      => $changes->project($change['ref'], $change['payload'], $ctx),
                'token_revoke' => $changes->revokeToken($change['ref'], $ctx),
                'secrets'      => $changes->secrets((string) $change['payload']['revision'], AdminChanges::decryptSecrets($change['payload']), $ctx),
                'send_access_lock' => $changes->accessLock($change['ref'], $change['payload'], $ctx),
                'send_config'  => $changes->sendConfig($change['ref'], AdminChanges::decryptSendConfig($change['ref'], $change['payload']), $ctx),
            };
            $this->decide((int) $change['id'], 'applied', $approvedBy, null);

            return null;
        } catch (Throwable $e) {
            $msg = ($e instanceof \App\Core\Support\ConfigException ? $e->errorCode . ': ' : '') . $e->getMessage();
            $this->decide((int) $change['id'], 'failed', $approvedBy, $msg);

            return $msg;
        }
    }

    public function discard(array $change, string $by): void
    {
        $this->decide((int) $change['id'], 'discarded', $by, null);
    }

    private function decide(int $id, string $decision, string $by, ?string $result): void
    {
        db_connect()->table('admin_pending_changes')->where('id', $id)
            ->update(['decided_at' => Time::now(), 'decision' => $decision, 'decided_by' => mb_substr($by, 0, 255), 'result' => $result === null ? null : mb_substr($result, 0, 500)]);
    }
}
