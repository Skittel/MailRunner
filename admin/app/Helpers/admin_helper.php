<?php

/** Formatting helpers of the admin GUI. DB timestamps are UTC; display in Europe/Berlin. */
if (! function_exists('dt')) {
    function dt(?string $ts, string $format = 'd.m.Y H:i'): string
    {
        if ($ts === null || $ts === '') {
            return '–';
        }
        $tz = str_ends_with($ts, 'Z') || str_contains($ts, '+') ? null : new DateTimeZone('UTC');

        return (new DateTimeImmutable($ts, $tz))->setTimezone(new DateTimeZone('Europe/Berlin'))->format($format);
    }

    function ago(?string $ts): string
    {
        if ($ts === null || $ts === '') {
            return 'nie';
        }
        $tz = str_ends_with($ts, 'Z') || str_contains($ts, '+') ? null : new DateTimeZone('UTC');
        $s  = time() - (new DateTimeImmutable($ts, $tz))->getTimestamp();

        return match (true) {
            $s < 60    => 'gerade eben',
            $s < 3600  => 'vor ' . intdiv($s, 60) . ' min',
            $s < 86400 => 'vor ' . intdiv($s, 3600) . ' h',
            default    => 'vor ' . intdiv($s, 86400) . ' Tagen',
        };
    }

    function health_badge(string $level): string
    {
        [$cls, $txt] = match ($level) {
            'ok'    => ['bg-green-lt', 'gesund'],
            'warn'  => ['bg-yellow-lt', 'Warnung'],
            'fail'  => ['bg-red-lt', 'Fehler'],
            default => ['bg-secondary-lt', 'unbekannt'],
        };

        return '<span class="badge ' . $cls . '">' . $txt . '</span>';
    }

    function status_dot(string $level): string
    {
        $cls = ['ok' => 'status-green', 'warn' => 'status-yellow', 'fail' => 'status-red'][$level] ?? 'status-secondary';

        return '<span class="status-dot status-dot-animated ' . $cls . ' me-2"></span>';
    }

    /** True if the signed-in user is in an RW group (may change configuration). */
    function can_write(): bool
    {
        return session('access') === 'rw';
    }

    function bytes(int $n): string
    {
        return $n >= 1048576 ? round($n / 1048576, 1) . ' MB' : ($n >= 1024 ? round($n / 1024) . ' KB' : $n . ' B');
    }
}
