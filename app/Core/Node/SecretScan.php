<?php

namespace App\Core\Node;

use CodeIgniter\Database\BaseConnection;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Looks for secret values of the node (/etc/mailrunner/*.env, node key) in places where they must never be:
 * every text column of the node database and the log/data files of the node (without the encrypted mail store).
 *
 * Results name only the setting and the location, never the value. Used by mr:secrets:scan and the self test
 * (check base.secret_leak).
 *
 * Known findings can be accepted (mr:secrets:scan --accept, e.g. on a development node). An accepted finding is
 * bound to setting, location, a fingerprint of the value and its count: a new place, a new value (rotated key)
 * or more occurrences fail again.
 */
class SecretScan
{
    /** Settings that are configuration, not secrets. */
    private const NOT_SECRET = '/(^CI_ENVIRONMENT$|^SELFTEST_SMTP_USER$|hostname$|DBDriver$|savePath$|baseURL$|\.database$|username$|_NAMES$|_HELO$|_IPV4$|_IPV6$|_DIR$|_PATH$|_FILE$|_ENABLED$|_DAYS$|_ID$|_SOURCE$)/i';

    private const MIN_LENGTH = 12;
    private const MAX_FILE   = 50_000_000;

    /**
     * @param list<string> $envFiles
     * @param list<string> $dirs     directories with logs and runtime data
     * @param list<string> $skipDirs directories not to scan (encrypted mail store, sessions)
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly array $envFiles,
        private readonly ?string $nodeKeyFile,
        private readonly array $dirs,
        private readonly array $skipDirs = [],
        private readonly ?string $acceptFile = null,
    ) {
    }

    public static function forNode(BaseConnection $db): self
    {
        $c = config('Mailrunner');

        return new self($db, [...(glob($c->configDir . '/*.env') ?: []), $c->commonSecretsFile()], $c->nodeKeyFile(),
            array_values(array_unique([$c->dataDir, rtrim(WRITEPATH, '/')])),
            [$c->receiveSpoolDir(), $c->receiveStoreDir(), rtrim(WRITEPATH, '/') . '/session'], $c->dataDir . '/secret-scan-accepted.json');
    }

    /**
     * Secret values by their name ("secrets.env:SPAMHAUS_DQS_KEY").
     *
     * @return array<string, string> value => name
     */
    public function secrets(): array
    {
        $values = [];
        foreach ($this->envFiles as $file) {
            $lines = is_readable($file) ? file($file, FILE_IGNORE_NEW_LINES) : false;
            foreach ($lines ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = array_map('trim', explode('=', $line, 2));
                $value         = trim($value, "'\"");
                if (strlen($value) >= self::MIN_LENGTH && preg_match(self::NOT_SECRET, $key) !== 1
                    && ! str_starts_with($value, '/') && ! str_starts_with($value, 'http')) {
                    $values[$value] ??= basename($file) . ':' . $key; // first name wins (local secrets.env before common.env)
                }
            }
        }
        if ($this->nodeKeyFile !== null && is_readable($this->nodeKeyFile)) {
            $raw   = trim((string) file_get_contents($this->nodeKeyFile));
            $bin   = base64_decode($raw, true);
            $forms = [$raw, bin2hex($raw)];
            if ($bin !== false) {
                $forms[] = bin2hex($bin);
                $forms[] = rtrim($raw, '=');
            }
            foreach ($forms as $form) {
                if (strlen($form) >= 16) {
                    $values[$form] = 'node.key';
                }
            }
        }

        return $values;
    }

    /**
     * @return array{checked: int, findings: list<array{secret: string, where: string, count: int}>, skipped: list<string>, sources: int}
     */
    public function run(): array
    {
        $secrets  = $this->secrets();
        $findings = [];
        $skipped  = [];
        $sources  = 0;
        if ($secrets === []) {
            return ['checked' => 0, 'findings' => [], 'skipped' => ['no readable secrets'], 'sources' => 0];
        }
        $values = array_keys($secrets);

        // database: every text-like column, one query per column with all values bound as parameters
        $columns = $this->db->query(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
             AND DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext','json','blob','mediumblob','longblob')",
        )->getResultArray();
        foreach ($columns as $col) {
            $expr = sprintf('`%s`', str_replace('`', '', $col['c']));
            $sums = implode(', ', array_map(static fn ($i) => "COALESCE(SUM(INSTR({$expr}, ?) > 0), 0) AS h{$i}", array_keys($values)));
            $row  = $this->db->query(sprintf('SELECT %s FROM `%s`', $sums, str_replace('`', '', $col['t'])), $values)->getRowArray();
            foreach ($values as $i => $v) {
                if ((int) ($row['h' . $i] ?? 0) > 0) {
                    $findings[] = ['secret' => $secrets[$v], 'where' => 'db ' . $col['t'] . '.' . $col['c'], 'count' => (int) $row['h' . $i], 'fp' => self::fp($v)];
                }
            }
            $sources++;
        }

        // files (the secret sources themselves excluded)
        $sourceFiles = array_filter(array_map('realpath', [...$this->envFiles, (string) $this->nodeKeyFile]));
        foreach ($this->dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            if (! is_readable($dir)) {
                $skipped[] = $dir;

                continue;
            }
            $it = new RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                    fn ($f) => ! $f->isLink() && ! in_array(rtrim($f->getPathname(), '/'), $this->skipDirs, true),
                ),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            foreach ($it as $file) {
                if (! $file->isFile() || $file->getSize() > self::MAX_FILE || in_array($file->getRealPath(), $sourceFiles, true)) {
                    continue;
                }
                $data = @file_get_contents($file->getPathname());
                if ($data === false) {
                    $skipped[] = $file->getPathname();

                    continue;
                }
                foreach ($values as $v) {
                    $n = substr_count($data, $v);
                    if ($n > 0) {
                        $findings[] = ['secret' => $secrets[$v], 'where' => $file->getPathname(), 'count' => $n, 'fp' => self::fp($v)];
                    }
                }
                $sources++;
            }
        }

        // several forms of one secret (node key) can hit the same place: report once
        $unique = [];
        foreach ($findings as $f) {
            $k          = $f['secret'] . "\0" . $f['where'];
            $unique[$k] = isset($unique[$k]) ? ['count' => max($unique[$k]['count'], $f['count'])] + $f : $f;
        }
        $findings = array_values($unique);
        $accepted = $this->accepted();
        foreach ($findings as &$f) {
            $a             = $accepted[$f['where'] . "\0" . $f['fp']] ?? null; // by value fingerprint: the same value under another name stays accepted
            $f['accepted'] = $a !== null && $f['count'] <= $a;
        }
        unset($f);

        return ['checked' => count(array_unique($secrets)), 'findings' => $findings, 'skipped' => $skipped, 'sources' => $sources];
    }

    /**
     * The scan as a self test check (same shape as the checks of mr-selftest).
     *
     * @return array{name: string, group: string, status: string, detail: string, ms: int}
     */
    public function check(): array
    {
        $t0 = microtime(true);
        try {
            $r      = $this->run();
            $open     = array_values(array_filter($r['findings'], static fn ($f) => ! $f['accepted']));
            $accepted = count($r['findings']) - count($open);
            $status   = $open === [] ? 'ok' : 'fail';
            $detail   = $open === []
                ? sprintf('%d secrets, %d sources clean', $r['checked'], $r['sources']) . ($accepted > 0 ? sprintf(' (%d accepted finding%s)', $accepted, $accepted === 1 ? '' : 's') : '')
                : 'found: ' . implode('; ', array_map(static fn ($f) => $f['secret'] . ' in ' . $f['where'], $open));
        } catch (\Throwable $e) {
            [$status, $detail] = ['fail', 'scan failed: ' . $e::class];
        }

        return ['name' => 'base.secret_leak', 'group' => 'base', 'status' => $status, 'detail' => substr($detail, 0, 300),
            'ms' => (int) round((microtime(true) - $t0) * 1000)];
    }

    /**
     * Accepts the given findings (replaces earlier acceptances). Returns the number accepted.
     *
     * @param list<array{secret: string, where: string, count: int, fp: string}> $findings
     */
    public function accept(array $findings): int
    {
        if ($this->acceptFile === null) {
            throw new \RuntimeException('no accept file configured');
        }
        $rows = array_map(static fn ($f) => ['secret' => $f['secret'], 'where' => $f['where'], 'fp' => $f['fp'], 'count' => $f['count'],
            'accepted_at' => gmdate('Y-m-d\TH:i:s\Z')], $findings);
        if (file_put_contents($this->acceptFile, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
            throw new \RuntimeException('cannot write ' . $this->acceptFile);
        }

        return count($rows);
    }

    /** @return array<string, int> "where\0fp" => accepted count */
    private function accepted(): array
    {
        $rows = $this->acceptFile !== null && is_readable($this->acceptFile) ? json_decode((string) file_get_contents($this->acceptFile), true) : [];
        $map  = [];
        foreach (is_array($rows) ? $rows : [] as $a) {
            $map[($a['where'] ?? '') . "\0" . ($a['fp'] ?? '')] = (int) ($a['count'] ?? 0);
        }

        return $map;
    }

    /** Short fingerprint of a value (not reversible), so a rotated key is a new finding. */
    private static function fp(string $value): string
    {
        return substr(hash('sha256', 'mr-secret-scan:' . $value), 0, 16);
    }
}
