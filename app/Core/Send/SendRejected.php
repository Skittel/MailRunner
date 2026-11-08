<?php

namespace App\Core\Send;

use RuntimeException;

/**
 * A submission the node refuses, with the answer for both ways in: HTTP status and error code for the API, SMTP
 * reply code and enhanced status for mr-milter (4xx = try again later, 5xx = permanent).
 */
final class SendRejected extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly string $smtpCode,
        public readonly string $smtpEnhanced,
    ) {
        parent::__construct($message);
    }
}
