<?php

namespace App\Core\Node;

use Config\Mailrunner;
use RuntimeException;

/**
 * Common secrets distributed by MailRunner Admin (e.g. Spamhaus DQS key for all nodes).
 * File: <dataDir>/secrets/common.env (KEY=VALUE, first line "# revision=<id>"), user mailrunner, 0600.
 * The local /etc/mailrunner/secrets.env (root-owned, not writable by the API) wins for every non-empty value,
 * so a node can always override or pin a credential locally.
 */
class CommonSecrets
{
    public function __construct(private readonly string $file, private readonly string $localFile)
    {
    }

    public static function forNode(): self
    {
        $c = config('Mailrunner');

        return new self($c->commonSecretsFile(), $c->configDir . '/secrets.env');
    }

    /**
     * Validates a set from the admin; returns the error or null.
     *
     * @param array<mixed> $values
     */
    public static function invalid(array $values, string $revision): ?string
    {
        if (preg_match('/^[0-9a-z]{4}-[0-9a-z]{4}$/', $revision) !== 1) {
            return 'revision must be xxxx-xxxx';
        }
        foreach ($values as $key => $value) {
            if (! in_array($key, Mailrunner::COMMON_SECRET_KEYS, true)) {
                return 'unknown secret ' . $key . ' (allowed: ' . implode(', ', Mailrunner::COMMON_SECRET_KEYS) . ')';
            }
            if (! is_string($value) || $value === '' || strlen($value) > 512 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                return 'invalid value for ' . $key;
            }
        }

        return null;
    }

    /**
     * Replaces the whole common set (the admin always sends the full desired state).
     *
     * @param array<string, string> $values
     */
    public function write(array $values, string $revision): void
    {
        if (($err = self::invalid($values, $revision)) !== null) {
            throw new RuntimeException($err);
        }
        $dir = dirname($this->file);
        if (! is_dir($dir) && ! @mkdir($dir, 0o700, true)) {
            throw new RuntimeException('cannot create ' . $dir);
        }
        ksort($values);
        $body = "# revision=" . $revision . "\n# Written by MailRunner Admin (common secrets). Local values in secrets.env win.\n";
        foreach ($values as $k => $v) {
            $body .= $k . '=' . $v . "\n";
        }
        $tmp = $this->file . '.tmp';
        $old = umask(0o077);
        try {
            if (file_put_contents($tmp, $body) === false || ! rename($tmp, $this->file)) {
                throw new RuntimeException('cannot write ' . $this->file);
            }
        } finally {
            umask($old);
        }
        @chmod($this->file, 0o600);
    }

    public function revision(): ?string
    {
        $first = is_readable($this->file) ? (string) fgets(fopen($this->file, 'r')) : '';

        return preg_match('/^# revision=([0-9a-z]{4}-[0-9a-z]{4})/', $first, $m) === 1 ? $m[1] : null;
    }

    /**
     * Names only, never values: which secret is set centrally, which locally, which one is used.
     *
     * @return array{revision: ?string, common: list<string>, local: list<string>, local_overrides: list<string>}
     */
    public function summary(): array
    {
        $common = array_keys(self::parse($this->file));
        $local  = array_values(array_intersect(array_keys(self::parse($this->localFile)), Mailrunner::COMMON_SECRET_KEYS));

        return ['revision' => $this->revision(), 'common' => $common, 'local' => $local, 'local_overrides' => array_values(array_intersect($common, $local))];
    }

    /** @return array<string, string> non-empty values */
    public static function parse(string $file): array
    {
        $out = [];
        foreach ((is_readable($file) ? file($file, FILE_IGNORE_NEW_LINES) : false) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            $v       = trim($v, "'\"");
            if ($v !== '') {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
