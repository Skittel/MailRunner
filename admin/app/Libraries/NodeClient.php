<?php

namespace App\Libraries;

use Config\Admin;
use RuntimeException;

/**
 * Client for the admin API of a MailRunner node (https://<node>/admin/v1, mTLS client certificate).
 * Plain cURL: CURLRequest does not keep the client key separate from the certificate.
 */
class NodeClient
{
    public function __construct(private readonly Admin $config, private readonly string $baseUrl, private readonly ?string $actor = null)
    {
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function json(string $method, string $path, ?array $body = null): array
    {
        [$status, $raw] = $this->request($method, $path, $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new RuntimeException('Node answered HTTP ' . $status . ' without JSON');
        }

        return ['status' => $status, 'body' => $data];
    }

    /**
     * @return array<string, mixed> body of a 2xx answer, else exception with the node's error code
     */
    public function ok(string $method, string $path, ?array $body = null): array
    {
        $r = $this->json($method, $path, $body);
        if ($r['status'] >= 300) {
            $e = $r['body']['error'] ?? [];
            throw new NodeError((string) ($e['code'] ?? 'HTTP_' . $r['status']), (string) ($e['message'] ?? 'HTTP ' . $r['status']), $r['status']);
        }

        return $r['body'];
    }

    public function raw(string $path): string
    {
        [$status, $raw] = $this->request('GET', $path, null);
        if ($status !== 200) {
            throw new NodeError('HTTP_' . $status, 'Node answered HTTP ' . $status, $status);
        }

        return $raw;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function request(string $method, string $path, ?string $payload): array
    {
        $ch      = curl_init(rtrim($this->baseUrl, '/') . '/admin/v1/' . ltrim($path, '/'));
        $headers = ['Accept: application/json'];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($this->actor !== null) {
            $headers[] = 'X-MailRunner-Actor: ' . preg_replace('/[^\w@.\- ]/u', '', $this->actor);
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSLCERT        => $this->config->mtlsCert,
            CURLOPT_SSLKEY         => $this->config->mtlsKey,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $this->config->nodeTimeout,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            throw new RuntimeException('Node not reachable: ' . $err);
        }

        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $raw];
    }
}
