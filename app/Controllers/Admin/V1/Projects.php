<?php

namespace App\Controllers\Admin\V1;

use App\Core\Admin\AdminChanges;
use App\Core\Api\ApiResponse;
use App\Core\Support\ConfigException;
use App\Core\Support\Time;

/**
 * GET    /admin/v1/projects
 * GET    /admin/v1/projects/{customer}/{project}          incl. routes, tokens (hash, never the secret) and webhook state
 * PUT    /admin/v1/projects/{customer}/{project}          complete configuration, idempotent:
 *        {"type": "receive"|"send" (fixed at creation, default receive), "profile": "analysis", "name": "...", "enabled": true, "routes": ["example.org", ...],
 *        send projects: profile smarthost, no routes, settings send_limit_hour/day, send_allowed_domains (null = all), send_test_criteria
 *         "settings": {"recipient_pattern": ..., "journal_scope": ..., "role_mail": ..., ...},
 *         "tokens": [{"token_id", "label", "secret_hash", "created_at", "expires_at", "revoked_at"}, ...]}
 *        "tokens" (optional) is the full central token set of the project: see ApiTokenService::sync()
 * POST   /admin/v1/projects/{customer}/{project}/tokens   {"label": "...", "expires_days": 90} -> secret once
 * DELETE /admin/v1/tokens/{token_id}
 */
class Projects extends AdminController
{
    public const SETTINGS = ['recipient_pattern', 'recipient_always_allow', 'delete_after_ack_hours', 'unacked_days',
        'metadata_days', 'journal_scope', 'role_mail', 'role_mail_forward_to',
        'send_limit_hour', 'send_limit_day', 'send_allowed_domains', 'send_test_criteria'];

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

            $hashes  = array_column(db_connect()->table('api_tokens')->select('token_id, secret_hash')->where('project_id', $p['id'])->get()->getResultArray(), 'secret_hash', 'token_id');
            $unacked = db_connect()->table('receive_messages')->where('project_id', $p['id'])->where('acked_at', null)->where('content_deleted_at', null)->countAllResults();

            return ApiResponse::ok(['data' => self::present($p, array_column(service('routeService')->list($ref), 'route')) + [
                'messages_unacked' => $unacked,
                'domains' => $p['type'] === 'send' ? array_map(static fn ($d) => \App\Core\Send\SendDomainService::present($d, $p), service('sendDomainService')->list((int) $p['id'])) : null,
                'tokens_central' => (int) ($p['tokens_central'] ?? 0) === 1,
                'webhook' => \App\Core\Webhooks\WebhookService::present(service('webhookService')->get((int) $p['id'])), // set by the customer (PUT /v1/webhook)
                'tokens' => array_map(static fn ($t) => [
                    'token_id' => $t['token_id'], 'label' => $t['label'], 'secret_hash' => $hashes[$t['token_id']] ?? null, 'created_at' => Time::toIso($t['created_at']),
                    'expires_at' => Time::toIso($t['expires_at']), 'revoked_at' => Time::toIso($t['revoked_at']),
                    'last_used_at' => Time::toIso($t['last_used_at']),
                ], service('apiTokenService')->list($ref)), // secret_hash only here (mTLS admin API): central token import
            ]]);
        });
    }

    public function put(string $customer, string $project)
    {
        return $this->guard(function () use ($customer, $project) {
            $ref  = $customer . '/' . $project;
            $body = $this->body();
            if ($this->configManual()) {
                return $this->queued('project', $ref, $body);
            }
            [$p, $created] = (new AdminChanges())->project($ref, $body, $this->ctx());

            return ApiResponse::ok(['data' => self::present($p, array_column(service('routeService')->list($ref), 'route')), 'created' => $created], $created ? 201 : 200);
        });
    }

    public function createToken(string $customer, string $project)
    {
        if ($this->configManual()) {
            return ApiResponse::error(409, 'CONFIG_MANUAL', 'This node applies admin configuration only after approval; create tokens on the node: sudo mailrunner mr:token:create <customer/project>');
        }

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
        if ($this->configManual()) {
            return $this->queued('token_revoke', $tokenId, []);
        }

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
        $type     = $p['type'] ?? 'receive';
        $settings = [];
        foreach (array_diff(self::SETTINGS, $type === 'send' ? \App\Core\Projects\ProjectService::RECEIVE_SETTINGS : \App\Core\Projects\ProjectService::SEND_SETTINGS) as $k) {
            $settings[$k] = in_array($k, ['recipient_always_allow', 'send_allowed_domains'], true) ? json_decode((string) ($p[$k] ?? 'null'), true) : $p[$k];
        }
        foreach (['delete_after_ack_hours', 'unacked_days', 'metadata_days', 'send_limit_hour', 'send_limit_day'] as $k) {
            if (array_key_exists($k, $settings) && $settings[$k] !== null) {
                $settings[$k] = (int) $settings[$k];
            }
        }
        if ($type === 'send') {
            $settings['send_test_criteria'] = \App\Core\Projects\ProjectService::testCriteria($p);
        }

        return [
            'ref' => $p['customer_key'] . '/' . $p['project_key'], 'customer' => $p['customer_key'], 'project' => $p['project_key'],
            'type' => $type, 'name' => $p['name'], 'profile' => $p['profile'], 'enabled' => (bool) $p['enabled'], 'managed_by' => $p['managed_by'],
            'revision' => (int) $p['revision'], 'updated_at' => Time::toIso($p['updated_at']), 'routes' => $routes, 'settings' => $settings,
        ];
    }
}
