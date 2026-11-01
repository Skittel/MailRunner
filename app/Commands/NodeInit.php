<?php

namespace App\Commands;

use CodeIgniter\CLI\CLI;

/**
 * mr:node:init - creates the random node key once. Never overwrites an existing key:
 * losing or replacing it makes all stored messages unreadable.
 */
class NodeInit extends MrCommand
{
    protected $name        = 'mr:node:init';
    protected $description = 'Creates the node key (once). Never overwrites an existing key.';
    protected $usage       = 'mr:node:init';

    protected function execute(): ?int
    {
        $path = config('Mailrunner')->nodeKeyFile();
        if (file_exists($path)) {
            CLI::write('Node key already exists: ' . $path . ' (unchanged)');

            return null;
        }
        if (! is_dir(dirname($path)) || ! is_writable(dirname($path))) {
            throw new \RuntimeException('Directory not writable: ' . dirname($path) . ' (run as root)');
        }
        $old = umask(0o027);
        $fh  = fopen($path, 'x');
        umask($old);
        if ($fh === false) {
            throw new \RuntimeException('Cannot create ' . $path);
        }
        fwrite($fh, base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)) . "\n");
        fflush($fh);
        fclose($fh);
        chmod($path, 0o640);
        if (function_exists('posix_getgrnam') && posix_getgrnam('mailrunner') !== false && posix_geteuid() === 0) {
            chgrp($path, 'mailrunner');
        }
        $this->ok('Node key created: ' . $path . ' (back it up only if you accept the risk; losing it makes stored messages unreadable)');

        return null;
    }
}
