<?php

namespace App\Core\Api;

use App\Core\Support\Time;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * JSON responses in one format. Errors: {"error":{"code","message"},"request_id","node_id"}.
 */
final class ApiResponse
{
    private static ?string $requestId = null;

    public static function requestId(): string
    {
        return self::$requestId ??= bin2hex(random_bytes(8));
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function ok(array $body, int $status = 200): ResponseInterface
    {
        return self::send($body, $status);
    }

    public static function error(int $status, string $code, string $message): ResponseInterface
    {
        return self::send(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function send(array $body, int $status): ResponseInterface
    {
        $body += ['node_id' => config('Mailrunner')->nodeId, 'request_id' => self::requestId(), 'time' => Time::toIso(Time::now())];

        return service('response')
            ->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Request-Id', self::requestId())
            ->setContentType('application/json')
            ->setBody(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
