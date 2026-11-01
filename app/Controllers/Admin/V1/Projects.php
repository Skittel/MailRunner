<?php

namespace App\Controllers\Admin\V1;

use App\Core\Api\ApiResponse;
use App\Core\Support\ConfigException;
use App\Core\Support\Time;

/**
 * GET    /admin/v1/projects
 * GET    /admin/v1/projects/{customer}/{project}          incl. routes and tokens (without secrets)
 * PUT    /admin/v1/projects/{customer}/{project}          complete configuration, idempotent:
 *        {"profile": "analysis", "name": "...", "enabled": true, "routes": ["example.org", ...],
 *         "settings": {"recipient_pattern": ..., "journal_scope": ..., "role_mail": ..., ...}}
 * POST   /admin/v1/projects/{customer}/{project}/tokens   {"label": "...", "expires_days": 90} -> secret once
 * DELETE /admin/v1/tokens/{token_id}
 */
class Projects extends AdminController
{
    private const SETTINGS = ['recipient_pattern', 'recipient_always_allow', 'delete_after_ack_hours', 'unacked_days',
        'metadata_days', 'journal_scope', 'role_mail', 'role_mail_forward_to'];

    public function index()
    {
        $routes = [];
        foreach (db_connect()->table('receive_routes')->select('project_id, route')->orderBy('route')->get()->getResultArray() as $r) {
            $routes[(int) $r['project_id']][] = $r['route'];
        }

        return ApiResponse::ok(['data' => array_map(static fn ($p) => self::present($p, $routes[(int) $p['id']] ?? []), service('projectService')->list())]);
    }

    public function show(string $customer, string $project)
    {
        return $this->guard(function () use ($customer, $project) {
            $ref = $customer . '/' . $project;
            $p   = service('projectService')->get($ref);

            return ApiResponse::ok(['data' => self::present($p, array_column(service('routeService')->list($ref), 'route')) + [
                'tokens' => array_map(static fn ($t) => [
                    'token_id' => $t['token_id'], 'label' => $t['label'], 'created_at' => Time::toIso($t['created_at']),
                    'expires_at' => Time::toIso($t['expires_at']), 'revoked_at' => Time::toIso($t['revoked_at']),
                    'last_used_at' => Time::toIso($t['last_used_at']),
                ], service('apiTokenService')->list($ref)),
            ]]);
        });
    }

    public function put(string $customer, string $project)
    {
        return $this->guard(function () use ($customer, $project) {
            $ref      = $customer . '/' . $project;
            $body     = $this->body();
            $projects = service('projectService');
            $created  = false;
            $existing = $projects->find($ref);
            if ($existing === null) {
                $projects->create($ref, (string) ($body['profile'] ?? ''), (string) ($body['name'] ?? ''), $this->ctx());
                $created = true;
            } elseif (isset($body['profile']) && $body['profile'] !== $existing['profile']) {
                throw new ConfigException('PROFILE_IMMUTABLE', 'The profile of an existing project cannot be changed');
            }
            $settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];
            $unknown  = array_diff(array_keys($settings), self::SETTINGS);
            if ($unknown !== []) {
                throw new ConfigException('INVALID_SETTING', 'Unknown setting(s): ' . implode(', ', $unknown));
            }
            $changes = $settings + array_intersect_key($body, ['name' => 1, 'enabled' => 1]);
            if ($changes !== []) {
                $projects->update($ref, $changes, $this->ctx());
            }
            if (array_key_exists('routes', $body)) {
                if (! is_array($body['routes'])) {
                    throw new ConfigException('INVALID_ROUTE', 'routes must be a list');
                }
                $current = array_column(service('routeService')->list($ref), 'route');
                $wanted  = array_values(array_map('strval', $body['routes']));
                if (array_map('strtolower', $wanted) != $current || $created) {
                    service('routeService')->set($ref, $wanted, $this->ctx());
                }
            }
            $p = $projects->get($ref);

            return ApiResponse::ok(['data' => self::present($p, array_column(service('routeService')->list($ref), 'route')), 'created' => $created], $created ? 201 : 200);
        });
    }

    public function createToken(string $customer, string $project)
    {
        return $this->guard(function () use ($customer, $project) {
            $body  = $this->body();
            $days  = isset($body['expires_days']) ? (int) $body['expires_days'] : null;
            $token = service('apiTokenService')->create($customer . '/' . $project, (string) ($body['label'] ?? ''), $days ? $days * 86400 : null, $this->ctx());

            return ApiResponse::ok(['data' => ['token_id' => $token['token']['token_id'], 'bearer' => $token['bearer'],
                'expires_at' => Time::toIso($token['token']['expires_at'] ?? null)]], 201);
        });
    }

    public function revokeToken(string $tokenId)
    {
        return $this->guard(function () use ($tokenId) {
            service('apiTokenService')->revoke($tokenId, $this->ctx());

            return ApiResponse::ok(['revoked' => $tokenId]);
        });
    }

    /**
     * @param array<string, mixed> $p
     * @param list<string>         $routes
     *
     * @return array<string, mixed>
     */
    public static function present(array $p, array $routes): array
    {
        $settings = [];
        foreach (self::SETTINGS as $k) {
            $settings[$k] = $k === 'recipient_always_allow' ? json_decode((string) ($p[$k] ?? 'null'), true) : $p[$k];
        }
        foreach (['delete_after_ack_hours', 'unacked_days', 'metadata_days'] as $k) {
            $settings[$k] = (int) $settings[$k];
        }

        return [
            'ref' => $p['customer_key'] . '/' . $p['project_key'], 'customer' => $p['customer_key'], 'project' => $p['project_key'],
            'name' => $p['name'], 'profile' => $p['profile'], 'enabled' => (bool) $p['enabled'], 'managed_by' => $p['managed_by'],
            'revision' => (int) $p['revision'], 'updated_at' => Time::toIso($p['updated_at']), 'routes' => $routes, 'settings' => $settings,
        ];
    }
}
