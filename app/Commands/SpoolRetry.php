<?php

namespace App\Commands;

use CodeIgniter\CLI\CLI;

/**
 * mr:spool:retry - moves entries from spool/failed/ back to the stage where they failed,
 * so mr-analyze / mr:import process them again.
 */
class SpoolRetry extends MrCommand
{
    protected $name        = 'mr:spool:retry';
    protected $description = 'Moves failed spool entries back for another attempt.';
    protected $usage       = 'mr:spool:retry [<spool_id>] [--list]';
    protected $options     = ['--list' => 'Only list failed entries with reason'];

    protected function execute(): ?int
    {
        $spool  = config('Mailrunner')->receiveSpoolDir();
        $only   = $this->arg(0);
        $count  = 0;
        $reasons = glob($spool . '/failed/*.reason') ?: [];
        foreach ($reasons as $reasonFile) {
            $id = basename($reasonFile, '.reason');
            if ($only !== null && $id !== $only) {
                continue;
            }
            $reason = trim((string) file_get_contents($reasonFile));
            if ($this->flag('list')) {
                CLI::write($id . '  ' . $reason);

                continue;
            }
            $moved = false;
            foreach (glob($spool . '/failed/' . $id . '.*.{eml,json}', GLOB_BRACE) ?: [] as $file) {
                // <id>.<stage>.<ext>
                [$stage, $ext] = array_slice(explode('.', basename($file)), -2);
                if (! in_array($stage, ['new', 'analyzed'], true)) {
                    continue;
                }
                $target = $spool . '/' . $stage . '/' . $id . '.' . $ext;
                // eml first, json last (entry becomes visible when complete)
                if ($ext === 'json') {
                    $jsonMove = [$file, $target];
                } else {
                    rename($file, $target);
                }
                $moved = true;
            }
            if (isset($jsonMove)) {
                rename($jsonMove[0], $jsonMove[1]);
                unset($jsonMove);
            }
            if ($moved) {
                unlink($reasonFile);
                $count++;
            }
        }
        if (! $this->flag('list')) {
            $this->ok('Moved back: ' . $count);
        }

        return null;
    }
}
