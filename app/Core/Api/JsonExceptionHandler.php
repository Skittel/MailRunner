<?php

namespace App\Core\Api;

use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Uncaught exceptions under /v1 and /admin/v1: HTTP 500 with the usual JSON error (code INTERNAL_ERROR and the
 * request_id, which is also in the log). No details of the exception go to the client.
 */
final class JsonExceptionHandler implements ExceptionHandlerInterface
{
    public function handle(Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void
    {
        $status = $statusCode >= 400 && $statusCode < 600 ? $statusCode : 500;
        log_message('error', 'API request {id} failed: {class} {msg} at {file}:{line}', ['id' => ApiResponse::requestId(),
            'class' => $exception::class, 'msg' => $exception->getMessage(), 'file' => $exception->getFile(), 'line' => $exception->getLine()]);
        ApiResponse::error($status, $status === 500 ? 'INTERNAL_ERROR' : 'ERROR', 'Internal error; quote request_id when reporting it')->send();
    }
}
