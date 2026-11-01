<?php

namespace App\Libraries;

/**
 * Health of a node from the last poll: ok | warn | fail | unknown, with reasons.
 * fail: not reachable, self test failed/broken, last self test older than 3 h.
 * warn: self test with warnings or older than 90 min, last successful poll older than 5 min.
 */
final class Health
{
    /**
     * @param array<string, mixed> $node row of nodes
     *
     * @return array{level: string, reasons: list<string>}
     */
    public static function of(array $node): array
    {
        if ($node['last_checked_at'] === null) {
            return ['level' => 'unknown', 'reasons' => ['not polled yet']];
        }
        $reasons = [];
        $level   = 'ok';
        $bump    = static function (string $to, string $why) use (&$level, &$reasons): void {
            $order = ['ok' => 0, 'warn' => 1, 'fail' => 2];
            if ($order[$to] > $order[$level]) {
                $level = $to;
            }
            $reasons[] = $why;
        };
        if ($node['last_error'] !== null) {
            $bump('fail', 'not reachable: ' . $node['last_error']);
        }
        $okAge = Clock::ageSeconds($node['last_ok_at']);
        if ($okAge !== null && $okAge > 300) {
            $bump('warn', 'last successful poll ' . intdiv($okAge, 60) . ' min ago');
        }
        $status = json_decode((string) ($node['last_status'] ?? ''), true) ?: [];
        $st     = $status['selftest'] ?? null;
        if ($status !== [] && $st === null) {
            $bump('warn', 'no self test yet');
        } elseif ($st !== null) {
            if (($st['status'] ?? '') !== 'pass') {
                $bump('fail', 'self test ' . ($st['status'] ?? '?') . ': ' . implode(', ', array_column($st['failed'] ?? [], 'name')));
            }
            if (! empty($st['warnings'])) {
                $bump('warn', 'self test warnings: ' . implode(', ', array_column($st['warnings'], 'name')));
            }
            $age = isset($st['finished_at']) ? time() - strtotime((string) $st['finished_at']) : null;
            if ($age !== null && $age > 3 * 3600) {
                $bump('fail', 'last self test ' . intdiv($age, 60) . ' min ago');
            } elseif ($age !== null && $age > 90 * 60) {
                $bump('warn', 'last self test ' . intdiv($age, 60) . ' min ago');
            }
        }

        return ['level' => $level, 'reasons' => $reasons];
    }
}
