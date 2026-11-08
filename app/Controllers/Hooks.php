<?php

namespace App\Controllers;

/**
 * POST /hooks/bounces - webhook target for the receive project of the bounce domain (E-27 step 6). Asks mr-sender to
 * collect bounces now instead of within the next minute. Answers 204 without reading the body.
 */
class Hooks extends BaseController
{
    public function bounces()
    {
        $dir = config('Mailrunner')->dataDir . '/send/requests';
        if (is_dir($dir) && is_writable($dir)) {
            @touch($dir . '/bounce.request');
        }

        return $this->response->setStatusCode(204)->setBody('');
    }
}
