<?php

namespace App\Controllers\V1;

use App\Controllers\BaseController;
use App\Core\Api\ApiResponse;
use App\Core\Auth\CurrentToken;
use App\Core\Send\DkimKeys;
use App\Core\Send\SendDomainService;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;

/**
 * Sender domains and DKIM keys of the token's project (send projects only, decision E-27). Without a domain entry
 * with a valid DKIM key nothing can be sent from that domain.
 *
 * GET    /v1/domains                       all domains with selector, algorithm and the DNS record to publish
 * GET    /v1/domains/{domain}
 * PUT    /v1/domains/{domain}              {"selector": "...", "private_key": "...", "algorithm"?: "rsa"|"ed25519"}
 *                                          set or replace the key (idempotent); the private key is never returned
 * DELETE /v1/domains/{domain}
 * POST   /v1/domains/{domain}/test         start a domain test now (202); the result appears in "test"
 * GET    /v1/domains/{domain}/tests        the last 20 test runs
 * POST   /v1/dkim/generate                 {"domain": "...", "algorithm"?: "rsa"|"ed25519", "bits"?: 2048|3072|4096}
 *                                          -> {selector, algorithm, bits, private_key, dns}; stores nothing: the
 *                                          application keeps the key and sets it with PUT /v1/domains/{domain}
 */
class SendDomains extends BaseController
{
    public function index()
    {
        return $this->guard(function () {
            $project = $this->service()->sendProject(CurrentToken::projectId(), false);

            return ApiResponse::ok(['data' => array_map(static fn ($r) => SendDomainService::present($r, $project), $this->service()->list((int) $project['id']))]);
        });
    }

    public function show(string $domain)
    {
        return $this->guard(function () use ($domain) {
            $project = $this->service()->sendProject(CurrentToken::projectId(), false);
            $row     = $this->service()->get((int) $project['id'], urldecode($domain)) ?? throw new ConfigException('DOMAIN_NOT_FOUND', 'Domain not set');

            return ApiResponse::ok(['data' => SendDomainService::present($row, $project)]);
        });
    }

    public function put(string $domain)
    {
        $b = $this->request->getJSON(true);
        if (! is_array($b) || ! is_string($b['selector'] ?? null) || ! is_string($b['private_key'] ?? null)
            || (isset($b['algorithm']) && ! is_string($b['algorithm']))) {
            return ApiResponse::error(400, 'INVALID_REQUEST', 'Body must be {"selector": "...", "private_key": "...", "algorithm"?: "rsa"|"ed25519"}');
        }

        return $this->guard(function () use ($domain, $b) {
            [$row, $changed] = $this->service()->set(CurrentToken::projectId(), urldecode($domain), $b['selector'], $b['private_key'], $b['algorithm'] ?? null, $this->ctx());

            return ApiResponse::ok(['data' => SendDomainService::present($row), 'changed' => $changed]);
        });
    }

    public function test(string $domain)
    {
        return $this->guard(function () use ($domain) {
            $row = $this->service()->requestTest(CurrentToken::projectId(), urldecode($domain), $this->ctx());

            return ApiResponse::ok(['data' => SendDomainService::present($row, $this->service()->sendProject(CurrentToken::projectId(), false))], 202);
        });
    }

    public function tests(string $domain)
    {
        return $this->guard(function () use ($domain) {
            $project = $this->service()->sendProject(CurrentToken::projectId(), false);
            $row     = $this->service()->get((int) $project['id'], urldecode($domain)) ?? throw new ConfigException('DOMAIN_NOT_FOUND', 'Domain not set');

            return ApiResponse::ok(['data' => $this->service()->tests($row)]);
        });
    }

    public function delete(string $domain)
    {
        return $this->guard(fn () => ApiResponse::ok(['deleted' => $this->service()->delete(CurrentToken::projectId(), urldecode($domain), $this->ctx())]));
    }

    public function generate()
    {
        $b = $this->request->getJSON(true);
        if (! is_array($b) || ! is_string($b['domain'] ?? null) || (isset($b['algorithm']) && ! is_string($b['algorithm'])) || (isset($b['bits']) && ! is_int($b['bits']))) {
            return ApiResponse::error(400, 'INVALID_REQUEST', 'Body must be {"domain": "...", "algorithm"?: "rsa"|"ed25519", "bits"?: 2048|3072|4096}');
        }

        return $this->guard(function () use ($b) {
            $this->service()->sendProject(CurrentToken::projectId(), false);
            $domain = SendDomainService::normalize($b['domain']);
            if (! \App\Core\Projects\ProjectService::isDomainName($domain)) {
                throw new ConfigException('INVALID_DOMAIN', 'Not a valid domain name');
            }
            $key      = DkimKeys::generate($b['algorithm'] ?? 'rsa', $b['bits'] ?? DkimKeys::DEFAULT_RSA_BITS);
            $selector = DkimKeys::newSelector();

            return ApiResponse::ok(['data' => [
                'domain' => $domain, 'selector' => $selector, 'algorithm' => $key['algorithm'], 'bits' => $key['bits'],
                'private_key' => $key['private_key'],
                'dns' => DkimKeys::dnsRecord($domain, $selector, $key['algorithm'], $key['public']),
            ]]);
        });
    }

    private function service(): SendDomainService
    {
        return service('sendDomainService');
    }

    private function ctx(): ChangeContext
    {
        return new ChangeContext('api', (string) CurrentToken::get()['token_id']);
    }

    private function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (ConfigException $e) {
            $status = match (true) {
                str_ends_with($e->errorCode, '_NOT_FOUND') => 404,
                $e->errorCode === 'PROJECT_TYPE'           => 409,
                $e->errorCode === 'DOMAIN_NOT_ALLOWED'     => 403,
                default                                    => 422,
            };

            return ApiResponse::error($status, $e->errorCode, $e->getMessage());
        }
    }
}
