<?php

namespace App\Controllers\Admin\V1;

use App\Core\Admin\AdminChanges;
use App\Core\Api\ApiResponse;
use App\Core\Support\ConfigException;
use App\Core\Support\Time;

/**
 * GET    /admin/v1/projects
 * GET    /admin/v1/projects/{customer}/{project}          incl. routes, tokens (hash, never the secret) and webhook state;
 *        send projects: domains with test state, send profiles with accesses, send_queue (figures, latest messages)
 * PUT    /admin/v1/projects/{customer}/{project}          complete configuration, idempotent:
 *        {"type": "receive"|"send" (fixed at creation, default receive), "profile": "analysis", "name": "...", "enabled": true, "routes": ["example.org", ...],
 *        send projects: profile smarthost, no routes, settings send_limit_hour/day, send_allowed_domains (null = all), send_test_criteria
 *         "settings": {"recipient_pattern": ..., "journal_scope": ..., "role_mail": ..., ...},
 *         "tokens": [{"token_id", "label", "secret_hash", "created_at", "expires_at", "revoked_at"}, ...]}
 *        "tokens" (optional) is the full central token set of the project: see ApiTokenService::sync()
 * POST   /admin/v1/projects/{customer}/{project}/tokens   {"label": "...", "expires_days": 90} -> secret once
 * GET    /admin/v1/projects/{customer}/{project}/access-config   what the customer's application set up, WITH secrets,
 *        for the central copy in MailRunner Admin (AccessConfigSync): send projects domains (DKIM private keys),
 *        profiles, accesses; receive projects smtp_credentials (password hashes); both webhook (only while set).
 *        Journal config.exported. Alias send-config (admin 0.1.31-0.1.32).
 * PUT    /admin/v1/projects/{customer}/{project}/access-config   {the set, "if_hash"?}: makes the node's set equal
 *        (409 SEND_CONFIG_CHANGED if it changed since if_hash); manual mode: queued (encrypted with the node key)
 * PUT    /admin/v1/projects/{customer}/{project}/send-accesses/{access_id}/lock   {"reason": "..."}: admin lock, the
 *        customer cannot lift it; DELETE lifts it. Manual mode: queued (kind send_access_lock)
 * POST   /admin/v1/projects/{customer}/{project}/domains/{domain}/test   brings this node's domain test forward
 *        (also in manual mode: changes no configuration)
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

        $sync = service('accessConfigSync'); // hash per project: the admin reconciles only projects that changed

        return ApiResponse::ok(['data' => array_map(static fn ($p) => self::present($p, $routes[(int) $p['id']] ?? [])
            + ['access_config_hash' => $p['customer_key'] === \App\Core\Access\ApiTokenService::LOCAL_CUSTOMER ? null : $sync->hash($p)], service('projectService')->list())]);
    }

    /**
     * Send profiles with their accesses (no secrets, no hashes) for the admin view.
     *
     * @param array<string, mixed> $p
     *
     * @return list<array<string, mixed>>
     */
    private static function sendProfiles(array $p): array
    {
        $out = [];
        foreach (service('sendProfileService')->list((int) $p['id']) as $row) {
            $out[] = \App\Core\Send\SendProfileService::present($row, $p)
                + ['access_list' => array_map([\App\Core\Send\SendAccessService::class, 'present'], service('sendAccessService')->list((int) $row['id']))];
        }

        return $out;
    }

    /**
     * Queue of a send project: figures and the latest messages (recipient domain only).
     *
     * @return array<string, mixed>
     */
    private static function sendQueue(int $projectId): array
    {
        $stats = new \App\Core\Send\SendQueueStats(db_connect());

        return $stats->figures($projectId) + ['recent' => $stats->recent($projectId)];
    }

    public function show(string $customer, string $project)
    {
        return $this->guard(function () use ($customer, $project) {
            $ref = $customer . '/' . $project;
            $p   = service('projectService')->get($ref);

            $hashes  = array_column(db_connect()->table('api_tokens')->select('token_id, secret_hash')->where('project_id', $p['id'])->where('scope', 'manage')->get()->getResultArray(), 'secret_hash', 'token_id');
            $unacked = db_connect()->table('receive_messages')->where('project_id', $p['id'])->where('acked_at', null)->where('content_deleted_at', null)->countAllResults();

            return ApiResponse::ok(['data' => self::present($p, array_column(service('routeService')->list($ref), 'route')) + [
                'messages_unacked' => $unacked,
                'domains' => $p['type'] === 'send' ? array_map(static fn ($d) => \App\Core\Send\SendDomainService::present($d, $p), service('sendDomainService')->list((int) $p['id'])) : null,
                'send_profiles' => $p['type'] === 'send' ? self::sendProfiles($p) : null,
                'send_queue' => $p['type'] === 'send' ? self::sendQueue((int) $p['id']) : null,
                'access_config' => $config = ['hash' => service('accessConfigSync')->hash($p), 'changed_at' => service('accessConfigSync')->changedAt($p)],
                'send_config' => $p['type'] === 'send' ? $config : null, // admin 0.1.31-0.1.32
                'smtp_credentials' => $p['type'] === 'send' ? null : array_map(static fn ($c) => [
                    'credential_id' => $c['credential_id'], 'username' => $c['username'], 'label' => $c['label'], 'enabled' => (bool) $c['enabled'],
                    'tls_required' => (bool) $c['tls_required'], 'allowed_networks' => $c['allowed_networks'] === null ? null : json_decode($c['allowed_networks'], true),
                    'password_change_required' => (bool) $c['password_change_required'], 'previous_valid_until' => Time::toIso($c['previous_valid_until']),
                    'created_at' => Time::toIso($c['created_at']), 'last_used_at' => Time::toIso($c['last_used_at']),
                ], array_values(array_filter(service('smtpCredentialService')->list($ref), static fn ($c) => $c['revoked_at'] === null))),
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

    public function accessConfig(string $customer, string $project)
    {
        return $this->guard(function () use ($customer, $project) {
            $p      = $this->configProjectRow($customer . '/' . $project);
            $sync   = service('accessConfigSync');
            $set    = $sync->snapshot($p, true);
            $counts = AdminChanges::configCounts($set);
            \App\Core\Support\Transaction::run(db_connect(), fn () => service('journalWriter')->append($p['type'] === 'send' ? 'send.config.exported' : 'receive.config.exported',
                $this->ctx(), (int) $p['customer_id'], (int) $p['id'], $counts));

            return ApiResponse::ok(['data' => $set + ['hash' => \App\Core\Send\SendConfigSync::hashOf($set), 'changed_at' => $sync->changedAt($p)]]);
        });
    }

    public function putAccessConfig(string $customer, string $project)
    {
        return $this->guard(function () use ($customer, $project) {
            $ref  = $customer . '/' . $project;
            $body = $this->body();
            $this->configProjectRow($ref);
            if ($this->configManual()) { // keys, hashes and the webhook URL never lie in the queue in clear text
                $enc = base64_encode(\App\Core\Secrets\NodeCrypto::forNode()->encrypt(json_encode($body), 'admin-access-config:' . $ref));

                return $this->queued('access_config', $ref, ['enc' => $enc] + AdminChanges::configCounts($body));
            }
            $ifHash = is_string($body['if_hash'] ?? null) ? $body['if_hash'] : null;
            unset($body['if_hash']);

            return ApiResponse::ok(['data' => service('accessConfigSync')->apply($ref, $body, $ifHash, $this->ctx())]);
        });
    }

    public function lockAccess(string $customer, string $project, string $accessId)
    {
        return $this->accessLock($customer . '/' . $project, $accessId, true, (string) ($this->body()['reason'] ?? ''));
    }

    public function unlockAccess(string $customer, string $project, string $accessId)
    {
        return $this->accessLock($customer . '/' . $project, $accessId, false, '');
    }

    private function accessLock(string $ref, string $accessId, bool $locked, string $reason)
    {
        return $this->guard(function () use ($ref, $accessId, $locked, $reason) {
            $p = $this->sendProjectRow($ref);
            service('sendAccessService')->get((int) $p['id'], $accessId); // 404 before queueing
            if ($this->configManual()) {
                return $this->queued('send_access_lock', $ref . '/' . $accessId, ['locked' => $locked, 'reason' => $reason]);
            }
            $a = (new AdminChanges())->accessLock($ref . '/' . $accessId, ['locked' => $locked, 'reason' => $reason], $this->ctx());

            return ApiResponse::ok(['data' => \App\Core\Send\SendAccessService::present($a) + ['send_config_hash' => service('accessConfigSync')->hash($p)]]);
        });
    }

    public function testDomain(string $customer, string $project, string $domain)
    {
        return $this->guard(function () use ($customer, $project, $domain) {
            $p   = $this->sendProjectRow($customer . '/' . $project);
            $row = service('sendDomainService')->requestTest((int) $p['id'], $domain, $this->ctx());

            return ApiResponse::ok(['data' => \App\Core\Send\SendDomainService::present($row, $p)], 202);
        });
    }

    /** @return array<string, mixed> */
    private function configProjectRow(string $ref): array
    {
        if (str_starts_with($ref, \App\Core\Access\ApiTokenService::LOCAL_CUSTOMER . '/')) {
            throw new ConfigException('SEND_CONFIG_LOCAL', 'The configuration of node system projects stays local');
        }

        return service('projectService')->get($ref);
    }

    /** @return array<string, mixed> */
    private function sendProjectRow(string $ref): array
    {
        $p = $this->configProjectRow($ref);
        if ($p['type'] !== 'send') {
            throw new ConfigException('PROJECT_TYPE', 'This endpoint needs a project of type send');
        }

        return $p;
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
