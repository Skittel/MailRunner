<?php

namespace App\Controllers\V1;

use App\Controllers\BaseController;
use App\Core\Api\ApiResponse;
use App\Core\Auth\CurrentToken;
use App\Core\Send\SendAccessService;
use App\Core\Send\SendProfileService;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;

/**
 * Send profiles and their accesses of the token's project (send projects only, decision E-27).
 *
 * GET    /v1/send-profiles                         all profiles with access counts
 * GET    /v1/send-profiles/{name}
 * PUT    /v1/send-profiles/{name}                  complete definition, idempotent ("changed": false if equal):
 *                                                  {"senders": [...], "limit_hour", "limit_day", "recipients"?, "retry"?,
 *                                                   "return_path_domain"?, "test_interval_hours"?, "label"?, "enabled"?}
 * DELETE /v1/send-profiles/{name}                  deletes the profile and revokes its accesses
 * GET    /v1/send-profiles/{name}/accesses
 * POST   /v1/send-profiles/{name}/accesses         {"type": "api"|"smtp"|"ip", ...} -> bearer / generated password once
 * PATCH  /v1/send-accesses/{access_id}             {"label"?, "enabled"?, "networks"? (ip), "plaintext_port25"?, "allowed_networks"? (smtp)}
 * POST   /v1/send-accesses/{access_id}/rotate      {"grace_seconds"?, "password"? (smtp)} -> new bearer / generated password once
 * DELETE /v1/send-accesses/{access_id}             revoke at once
 */
class SendProfiles extends BaseController
{
    public function index()
    {
        return $this->guard(function () {
            $project = $this->project();
            $counts  = service('sendAccessService')->counts((int) $project['id']);

            return ApiResponse::ok(['data' => array_map(static fn ($p) => SendProfileService::present($p, $project, $counts[(int) $p['id']] ?? []),
                service('sendProfileService')->list((int) $project['id']))]);
        });
    }

    public function show(string $name)
    {
        return $this->guard(function () use ($name) {
            $project = $this->project();
            $p       = service('sendProfileService')->get((int) $project['id'], $name);

            return ApiResponse::ok(['data' => SendProfileService::present($p, $project, service('sendAccessService')->counts((int) $project['id'])[(int) $p['id']] ?? [])]);
        });
    }

    public function put(string $name)
    {
        $b = $this->request->getJSON(true);
        if (! is_array($b) || ($b !== [] && array_is_list($b))) {
            return ApiResponse::error(400, 'INVALID_REQUEST', 'Body must be a JSON object with the profile definition');
        }

        return $this->guard(function () use ($name, $b) {
            [$row, $changed, $created] = service('sendProfileService')->put(CurrentToken::projectId(), $name, $b, $this->ctx());
            $project = $this->project();

            return ApiResponse::ok(['data' => SendProfileService::present($row, $project, service('sendAccessService')->counts((int) $project['id'])[(int) $row['id']] ?? []),
                'changed' => $changed], $created ? 201 : 200);
        });
    }

    public function delete(string $name)
    {
        return $this->guard(fn () => ApiResponse::ok(['deleted' => service('sendProfileService')->delete(CurrentToken::projectId(), $name, $this->ctx(), service('sendAccessService'))]));
    }

    public function accesses(string $name)
    {
        return $this->guard(function () use ($name) {
            $project = $this->project();
            $p       = service('sendProfileService')->get((int) $project['id'], $name);

            return ApiResponse::ok(['data' => array_map([SendAccessService::class, 'present'], service('sendAccessService')->list((int) $p['id']))]);
        });
    }

    public function createAccess(string $name)
    {
        $b = $this->request->getJSON(true);
        if (! is_array($b)) {
            return ApiResponse::error(400, 'INVALID_REQUEST', 'Body must be {"type": "api"|"smtp"|"ip", ...}');
        }

        return $this->guard(function () use ($name, $b) {
            $r = service('sendAccessService')->create(CurrentToken::projectId(), $name, $b, $this->ctx());

            return ApiResponse::ok(['data' => SendAccessService::present($r['access'])] + array_intersect_key($r, ['bearer' => 1, 'password' => 1]), 201);
        });
    }

    public function updateAccess(string $id)
    {
        $b = $this->request->getJSON(true);
        if (! is_array($b) || $b === []) {
            return ApiResponse::error(400, 'INVALID_REQUEST', 'Body must be an object with the fields to change');
        }

        return $this->guard(fn () => ApiResponse::ok(['data' => SendAccessService::present(service('sendAccessService')->update(CurrentToken::projectId(), $id, $b, $this->ctx()))]));
    }

    public function rotateAccess(string $id)
    {
        $b        = $this->request->getJSON(true) ?? [];
        $grace    = is_array($b) ? ($b['grace_seconds'] ?? 0) : null;
        $password = is_array($b) ? ($b['password'] ?? null) : null;
        if (! is_int($grace) || ($password !== null && ! is_string($password))) {
            return ApiResponse::error(400, 'INVALID_REQUEST', 'Body: {"grace_seconds"?: 0-' . SendAccessService::MAX_GRACE . ', "password"?: "16-128 chars" (smtp)}');
        }

        return $this->guard(function () use ($id, $grace, $password) {
            $r = service('sendAccessService')->rotate(CurrentToken::projectId(), $id, $grace, $password, $this->ctx());

            return ApiResponse::ok(['data' => SendAccessService::present($r['access'])] + array_intersect_key($r, ['bearer' => 1, 'password' => 1]));
        });
    }

    public function deleteAccess(string $id)
    {
        return $this->guard(function () use ($id) {
            service('sendAccessService')->revoke(CurrentToken::projectId(), $id, $this->ctx());

            return ApiResponse::ok(['revoked' => $id]);
        });
    }

    /** @return array<string, mixed> */
    private function project(): array
    {
        return service('sendDomainService')->sendProject(CurrentToken::projectId(), false);
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
                in_array($e->errorCode, ['PROJECT_TYPE', 'USERNAME_EXISTS', 'NETWORK_IN_USE'], true) => 409,
                in_array($e->errorCode, ['DOMAIN_NOT_ALLOWED', 'LIMIT_EXCEEDS_PROJECT', 'PROJECT_LIMIT_MISSING'], true) => 403,
                default => 422,
            };

            return ApiResponse::error($status, $e->errorCode, $e->getMessage());
        }
    }
}
