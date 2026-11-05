<?php

namespace App\Controllers\V1;

use App\Controllers\BaseController;
use App\Core\Api\ApiResponse;
use App\Core\Auth\CurrentToken;
use App\Core\Projects\ProjectService;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;
use App\Core\Support\Time;

/**
 * SMTP credentials of the token's project (profile checked_auth). Mail delivered with such a login on port 25
 * (STARTTLS, or plain text if allowed), 587 (STARTTLS) or 465 (TLS) is stored in the project like MX mail,
 * without recipient checks; nothing is relayed.
 *
 * GET    /v1/smtp-credentials                       list (never passwords)
 * POST   /v1/smtp-credentials                       {"username", "password"?, "label"?, "plaintext_port25"?: false, "allowed_networks"?: [...]}
 *                                                   password set by the application (same login on several nodes) or generated
 *                                                   (then returned once)
 * PATCH  /v1/smtp-credentials/{credential_id}       {"label"?, "enabled"?, "plaintext_port25"?, "allowed_networks"?}
 * POST   /v1/smtp-credentials/{credential_id}/rotate {"password"?, "grace_seconds"?: 0..604800} -> generated password once
 * DELETE /v1/smtp-credentials/{credential_id}       revoke at once (the username can be used again)
 */
class SmtpCredentials extends BaseController
{
    private const MAX_GRACE = 604800;

    public function index()
    {
        $rows = service('smtpCredentialService')->list($this->ref());

        return ApiResponse::ok(['data' => array_map([self::class, 'present'], array_values(array_filter($rows, static fn ($r) => $r['revoked_at'] === null)))]);
    }

    public function create()
    {
        $b = $this->body();
        if (! is_string($b['username'] ?? null) || (isset($b['label']) && ! is_string($b['label'])) || (isset($b['password']) && ! is_string($b['password']))
            || (isset($b['plaintext_port25']) && ! is_bool($b['plaintext_port25'])) || (isset($b['allowed_networks']) && ! is_array($b['allowed_networks']))) {
            return ApiResponse::error(400, 'INVALID_REQUEST', 'Body must be {"username": "...", "password"?: "16-128 chars", "label"?: "...", "plaintext_port25"?: bool, "allowed_networks"?: ["cidr", ...]}');
        }

        return $this->guard(function () use ($b) {
            $r = service('smtpCredentialService')->create($this->ref(), $b['username'], $b['label'] ?? '', $b['password'] ?? null,
                ! ($b['plaintext_port25'] ?? false), $b['allowed_networks'] ?? null, $this->ctx());

            // a password set by the application is never echoed
            return ApiResponse::ok(['data' => self::present($r['credential'])] + (isset($b['password']) ? [] : ['password' => $r['password']]), 201);
        });
    }

    public function update(string $id)
    {
        $b       = $this->body();
        $changes = [];
        foreach (['label' => 'is_string', 'enabled' => 'is_bool', 'plaintext_port25' => 'is_bool'] as $k => $check) {
            if (array_key_exists($k, $b)) {
                if (! $check($b[$k])) {
                    return ApiResponse::error(400, 'INVALID_REQUEST', $k . ' has the wrong type');
                }
                $changes[$k === 'plaintext_port25' ? 'tls_required' : $k] = $k === 'plaintext_port25' ? ! $b[$k] : $b[$k];
            }
        }
        if (array_key_exists('allowed_networks', $b)) {
            if ($b['allowed_networks'] !== null && ! is_array($b['allowed_networks'])) {
                return ApiResponse::error(400, 'INVALID_REQUEST', 'allowed_networks must be a list or null');
            }
            $changes['allowed_networks'] = $b['allowed_networks'];
        }
        if ($changes === []) {
            return ApiResponse::error(400, 'INVALID_REQUEST', 'Nothing to change: label, enabled, plaintext_port25, allowed_networks');
        }

        return $this->guard(fn () => ApiResponse::ok(['data' => self::present(service('smtpCredentialService')->update($this->own($id), $changes, $this->ctx()))]));
    }

    public function rotate(string $id)
    {
        $b        = $this->body();
        $grace    = $b['grace_seconds'] ?? 0;
        $password = $b['password'] ?? null;
        if (! is_int($grace) || $grace < 0 || $grace > self::MAX_GRACE || ($password !== null && ! is_string($password))) {
            return ApiResponse::error(400, 'INVALID_REQUEST', 'Body: {"password"?: "16-128 chars", "grace_seconds"?: 0-' . self::MAX_GRACE . '}');
        }

        return $this->guard(function () use ($id, $grace, $password) {
            $r = service('smtpCredentialService')->rotate($this->own($id), $grace, $password, $this->ctx());

            return ApiResponse::ok(['data' => self::present($r['credential'])] + ($password !== null ? [] : ['password' => $r['password']]));
        });
    }

    public function delete(string $id)
    {
        return $this->guard(function () use ($id) {
            service('smtpCredentialService')->revoke($this->own($id), $this->ctx());

            return ApiResponse::ok(['revoked' => $id]);
        });
    }

    /** @param array<string, mixed> $c */
    public static function present(array $c): array
    {
        return [
            'credential_id' => $c['credential_id'], 'username' => $c['username'], 'label' => $c['label'],
            'plaintext_port25' => ! (bool) $c['tls_required'], 'allowed_networks' => $c['allowed_networks'] !== null ? json_decode($c['allowed_networks'], true) : null,
            'enabled' => (bool) $c['enabled'], 'created_at' => Time::toIso($c['created_at']), 'last_used_at' => Time::toIso($c['last_used_at']),
            'previous_password_valid_until' => Time::toIso($c['previous_valid_until']),
        ];
    }

    /** credential id of the token's project, else CREDENTIAL_NOT_FOUND */
    private function own(string $id): string
    {
        if (! str_starts_with($id, 'mrc_')) {
            throw new ConfigException('CREDENTIAL_NOT_FOUND', 'SMTP credential "' . $id . '" not found');
        }
        $c = service('smtpCredentialService')->get($id);
        if ((int) $c['project_id'] !== CurrentToken::projectId() || $c['revoked_at'] !== null) {
            throw new ConfigException('CREDENTIAL_NOT_FOUND', 'SMTP credential "' . $id . '" not found');
        }

        return $id;
    }

    private function ref(): string
    {
        return ProjectService::refOf(service('projectService')->getById(CurrentToken::projectId()));
    }

    private function ctx(): ChangeContext
    {
        return new ChangeContext('api', (string) CurrentToken::get()['token_id']);
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $b = $this->request->getJSON(true);

        return is_array($b) ? $b : [];
    }

    private function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (ConfigException $e) {
            $status = match (true) {
                str_ends_with($e->errorCode, '_NOT_FOUND') => 404,
                $e->errorCode === 'USERNAME_EXISTS'        => 409,
                default                                    => 422,
            };

            return ApiResponse::error($status, $e->errorCode, $e->getMessage());
        }
    }
}
