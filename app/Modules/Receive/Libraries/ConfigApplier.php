<?php

namespace App\Modules\Receive\Libraries;

use App\Core\Secrets\NodeCrypto;
use RuntimeException;

/**
 * Writes a generated config set to generated/<hash>/, checks it and activates it atomically
 * (symlink "current", previous kept as "previous"), then reloads Postfix.
 * If the check fails, the active set stays unchanged.
 */
class ConfigApplier
{
    /**
     * @param list<string> $encryptedFiles files written encrypted as <name>.enc (context "generated:<name>")
     */
    public function __construct(
        private readonly string $generatedDir,
        private readonly ?NodeCrypto $crypto = null,
        private readonly array $encryptedFiles = [],
    ) {
    }

    /**
     * @param array<string, string> $files
     *
     * @return array{hash: string, changed: bool, reloaded: bool, messages: list<string>}
     */
    public function apply(array $files, bool $reload = true): array
    {
        ksort($files);
        $hash     = substr(hash('sha256', serialize($files)), 0, 16);
        $target   = $this->generatedDir . '/' . $hash;
        $current  = $this->generatedDir . '/current';
        $messages = [];

        if (! is_dir($this->generatedDir) && ! mkdir($this->generatedDir, 0o750, true)) {
            throw new RuntimeException('Cannot create ' . $this->generatedDir);
        }
        $changed = ! (is_link($current) && readlink($current) === $hash);

        if (! is_dir($target)) {
            $tmp = $target . '.tmp-' . bin2hex(random_bytes(4));
            mkdir($tmp, 0o750);
            foreach ($files as $name => $content) {
                if (in_array($name, $this->encryptedFiles, true)) {
                    if ($this->crypto === null) {
                        throw new RuntimeException('Node key needed to write ' . $name);
                    }
                    $content = $this->crypto->encrypt($content, 'generated:' . $name);
                    $name .= '.enc';
                }
                file_put_contents($tmp . '/' . $name, $content);
                chmod($tmp . '/' . $name, 0o640);
            }
            $this->checkPcre($tmp . '/recipient_access.pcre');
            self::chgrpTree($tmp);
            rename($tmp, $target);
        }

        if ($changed) {
            if (is_link($current)) {
                $this->switchLink($this->generatedDir . '/previous', (string) readlink($current));
            }
            $this->switchLink($current, $hash);
            $messages[] = 'Activated config ' . $hash;
        } else {
            $messages[] = 'Config ' . $hash . ' already active';
        }

        $reloaded = false;
        if ($reload && $changed) {
            [$reloaded, $msg] = $this->reloadPostfix();
            $messages[]       = $msg;
        }
        $this->cleanup([$hash, is_link($this->generatedDir . '/previous') ? (string) readlink($this->generatedDir . '/previous') : '']);

        return ['hash' => $hash, 'changed' => $changed, 'reloaded' => $reloaded, 'messages' => $messages];
    }

    private function checkPcre(string $file): void
    {
        $postmap = '/usr/sbin/postmap';
        if (! is_executable($postmap)) {
            return; // Postfix not installed (development); PHP already validated the patterns
        }
        exec(escapeshellarg($postmap) . ' -q probe@invalid.example pcre:' . escapeshellarg($file) . ' 2>&1', $out, $code);
        // exit 1 = "not found" (fine); anything printed as error means the map is broken
        foreach ($out as $line) {
            if (stripos($line, 'error') !== false || stripos($line, 'fatal') !== false || stripos($line, 'warning') !== false) {
                throw new RuntimeException('Generated recipient_access.pcre is invalid: ' . $line);
            }
        }
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function reloadPostfix(): array
    {
        if (! is_executable('/usr/sbin/postfix')) {
            return [false, 'Postfix not installed, no reload'];
        }
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            return [false, 'Not root: Postfix not reloaded (run "sudo spark mr:apply" or "postfix reload")'];
        }
        exec('/usr/sbin/postfix check 2>&1', $out, $code);
        if ($code !== 0) {
            throw new RuntimeException('postfix check failed: ' . implode(' ', $out));
        }
        exec('/usr/sbin/postfix status >/dev/null 2>&1', $o, $running);
        if ($running !== 0) {
            return [false, 'Postfix not running, no reload'];
        }
        exec('/usr/sbin/postfix reload 2>&1', $out2, $code2);

        return [$code2 === 0, $code2 === 0 ? 'Postfix reloaded' : 'Postfix reload failed: ' . implode(' ', $out2)];
    }

    private function switchLink(string $link, string $target): void
    {
        $tmp = $link . '.tmp-' . bin2hex(random_bytes(4));
        symlink($target, $tmp);
        rename($tmp, $link); // atomic replace
    }

    /**
     * @param list<string> $keep
     */
    private function cleanup(array $keep): void
    {
        foreach (glob($this->generatedDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (! is_link($dir) && ! in_array($name, $keep, true)) {
                array_map('unlink', glob($dir . '/*') ?: []);
                rmdir($dir);
            }
        }
    }

    private static function chgrpTree(string $dir): void
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0 || posix_getgrnam('mailrunner') === false) {
            return;
        }
        chgrp($dir, 'mailrunner');
        foreach (glob($dir . '/*') ?: [] as $file) {
            chgrp($file, 'mailrunner');
        }
    }
}
