<?php

namespace App\Core\Node;

use RuntimeException;

/**
 * Software update of the node from the git tags of its source checkout (never from MailRunner Admin).
 * plan() shows what an update would fetch and change; run() checks out the tag and runs install.sh.
 * Downgrades are refused unless explicitly allowed on the CLI (a compromised admin cannot install old versions).
 */
class Updater
{
    /**
     * @param list<string> $installer command that installs from the checkout (default: install.sh with node id and host)
     */
    public function __construct(
        private readonly string $sourceDir,
        private readonly string $currentVersion,
        private readonly array $installer,
        private readonly string $requestFile,
    ) {
    }

    public static function forNode(): self
    {
        $c    = config('Mailrunner');
        $host = (string) parse_url((string) config('App')->baseURL, PHP_URL_HOST);

        return new self($c->sourceDir, (new NodeInfo($c))->version(),
            [$c->sourceDir . '/install/install.sh', '--role=receive', '--node-id=' . $c->nodeId, '--hostname=' . $host],
            $c->updateRequestFile());
    }

    /**
     * Version requested by MailRunner Admin, or null.
     *
     * @return array{version: string, requested_at: string, actor: string}|null
     */
    public function requested(): ?array
    {
        $d = is_readable($this->requestFile) ? json_decode((string) file_get_contents($this->requestFile), true) : null;

        return is_array($d) && isset($d['version']) && NodeInfo::isValidVersion((string) $d['version']) ? $d : null;
    }

    public function request(string $version, string $actor): void
    {
        if (! NodeInfo::isValidVersion($version)) {
            throw new RuntimeException('invalid version ' . $version);
        }
        $dir = dirname($this->requestFile);
        if (! is_dir($dir) && ! @mkdir($dir, 0o750, true)) {
            throw new RuntimeException('cannot create ' . $dir);
        }
        file_put_contents($this->requestFile . '.tmp', json_encode(['version' => $version, 'requested_at' => gmdate('Y-m-d\TH:i:s\Z'), 'actor' => $actor]) . "\n");
        rename($this->requestFile . '.tmp', $this->requestFile);
    }

    /**
     * What an update to $target would do. $target null: the version requested by the admin, else the newest tag.
     *
     * @return array{current: string, target: ?string, source: string, commits: list<string>, changelog: string, files: string, problems: list<string>, downgrade: bool}
     */
    public function plan(?string $target, bool $allowDowngrade = false, bool $force = false): array
    {
        $result = ['current' => $this->currentVersion, 'target' => $target, 'source' => $this->sourceDir, 'commits' => [], 'changelog' => '',
            'files' => '', 'problems' => [], 'downgrade' => false];
        if (! is_dir($this->sourceDir . '/.git')) {
            $result['problems'][] = 'no git checkout in ' . $this->sourceDir . ' (MAILRUNNER_SOURCE_DIR)';

            return $result;
        }
        $this->git(['fetch', '-q', '--tags', 'origin'], false);
        $requested = $this->requested()['version'] ?? null;
        if ($requested !== null && version_compare($requested, $this->currentVersion, '<=')) {
            $requested = null; // already installed (or older): an old admin request does not block newer tags
        }
        $target ??= $requested ?? $this->newestTag();
        $result['target'] = $target;
        $tagOk            = $target !== null && NodeInfo::isValidVersion($target) && $this->git(['rev-parse', '-q', '--verify', 'refs/tags/v' . $target], false) !== null;
        if ($target === null || ! NodeInfo::isValidVersion($target)) {
            $result['problems'][] = 'no target version';
        } elseif (! $tagOk) {
            $result['problems'][] = 'tag v' . $target . ' not found in ' . $this->sourceDir;
        }
        $result['downgrade'] = $target !== null && version_compare($target, $this->currentVersion, '<');
        if ($target !== null && $target === $this->currentVersion) {
            $result['problems'][] = 'already at ' . $target;
        }
        if ($result['downgrade'] && ! $allowDowngrade) {
            $result['problems'][] = 'v' . $target . ' is older than the installed v' . $this->currentVersion . ' (downgrade only with --allow-downgrade)';
        }
        if (! $force && trim((string) $this->git(['status', '--porcelain', '--untracked-files=no'], false)) !== '') {
            $result['problems'][] = 'local changes in ' . $this->sourceDir . ' (--force discards them)';
        }
        if ($tagOk) {
            $from                = $this->git(['rev-parse', '-q', '--verify', 'refs/tags/v' . $this->currentVersion], false) !== null ? 'v' . $this->currentVersion : 'HEAD';
            $range               = $result['downgrade'] ? 'v' . $target . '..' . $from : $from . '..v' . $target;
            $result['commits']   = array_values(array_filter(explode("\n", trim((string) $this->git(['log', '--oneline', '--no-decorate', $range], false)))));
            $result['files']     = trim((string) $this->git(['diff', '--shortstat', $from, 'v' . $target], false));
            $result['changelog'] = self::changelogBetween((string) $this->git(['show', 'v' . $target . ':CHANGELOG.md'], false), $this->currentVersion, $target);
        }

        return $result;
    }

    /**
     * Checks out the tag and runs the installer; returns its exit code. Each output line goes to $out.
     * A checkout on a branch (development node) stays on it when the tag is ahead: the branch is fast-forwarded to
     * the tag instead of detaching HEAD, so later commits do not end up outside the branch.
     */
    public function run(string $target, bool $force, callable $out): int
    {
        $tag    = 'v' . $target;
        $branch = trim((string) $this->git(['symbolic-ref', '-q', '--short', 'HEAD'], false));
        if ($branch !== '' && $this->git(['merge-base', '--is-ancestor', 'HEAD', $tag], false) !== null) {
            $this->git($force ? ['reset', '-q', '--hard', $tag] : ['merge', '-q', '--ff-only', $tag]);
        } else {
            $this->git($force ? ['checkout', '-q', '-f', $tag] : ['checkout', '-q', $tag]);
        }
        $proc = proc_open($this->installer, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $this->sourceDir);
        if (! is_resource($proc)) {
            throw new RuntimeException('cannot start installer');
        }
        while (($line = fgets($pipes[1])) !== false) {
            $out(rtrim($line, "\n"));
        }

        return proc_close($proc);
    }

    /** Sections of CHANGELOG.md newer than $from up to $to. */
    public static function changelogBetween(string $changelog, string $from, string $to): string
    {
        $keep = [];
        foreach (preg_split('/^(?=## \d+\.\d+\.\d+)/m', $changelog) ?: [] as $part) {
            if (preg_match('/^## (\d+\.\d+\.\d+)/', $part, $m) && version_compare($m[1], $from, '>') && version_compare($m[1], $to, '<=')) {
                $keep[] = trim($part);
            }
        }

        return implode("\n\n", $keep);
    }

    private function newestTag(): ?string
    {
        $tags = array_values(array_filter(explode("\n", (string) $this->git(['tag', '--list', 'v*'], false)), static fn ($t) => NodeInfo::isValidVersion(substr($t, 1))));
        usort($tags, static fn ($a, $b) => version_compare(substr($b, 1), substr($a, 1)));

        return $tags === [] ? null : substr($tags[0], 1);
    }

    private function git(array $args, bool $required = true): ?string
    {
        $proc = proc_open(['git', '-c', 'safe.directory=*', '-C', $this->sourceDir, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out  = stream_get_contents($pipes[1]);
        $err  = stream_get_contents($pipes[2]);
        if (proc_close($proc) !== 0) {
            if ($required) {
                throw new RuntimeException('git ' . $args[0] . ' failed: ' . trim((string) $err));
            }

            return null;
        }

        return (string) $out;
    }
}
