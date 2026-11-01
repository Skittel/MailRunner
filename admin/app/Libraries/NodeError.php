<?php

namespace App\Libraries;

use RuntimeException;

/** Error answer of a node admin API (code from the node, e.g. INVALID_ROUTE). */
class NodeError extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
