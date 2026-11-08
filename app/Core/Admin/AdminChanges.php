<?php

namespace App\Core\Admin;

use App\Controllers\Admin\V1\Projects as ProjectsApi;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;

/**
 * Applies the desired state sent by MailRunner Admin (customer, project, token revocation). Used directly by the
 * node admin API (MAILRUNNER_ADMIN_CONFIG=auto) and by mr:config:apply for queued changes (manual).
 */
class AdminChanges
{
    /**
     * @param array<string, mixed> $body {name?, enabled?}
     *
     * @return array{0: array<string, mixed>, 1: bool} customer row, created
     */
    public function customer(string $key, array $body, ChangeContext $ctx): array
    {
        $service = service('customerService');
        $created = false;
        if ($service->findByKey($key) === null) {
            $service->create($key, (string) ($body['name'] ?? ''), $ctx);
            $created = true;
        }
        $changes = array_intersect_key($body, ['name' => 1, 'enabled' => 1]);

        return [$changes === [] ? $service->getByKey($key) : $service->update($key, $changes, $ctx), $created];
    }

    /**
     * @param array<string, mixed> $body {profile, name, enabled, routes, settings}
     *
     * @return array{0: array<string, mixed>, 1: bool} project row, created
     */
    public function project(string $ref, array $body, ChangeContext $ctx): array
    {
        $projects = service('projectService');
        $created  = false;
        $existing = $projects->find($ref);
        $type     = (string) ($body['type'] ?? ($existing['type'] ?? 'receive'));
        if ($existing === null) {
            $projects->create($ref, (string) ($body['profile'] ?? ''), (string) ($body['name'] ?? ''), $ctx, $type);
            $created = true;
        } elseif ($type !== $existing['type']) {
            throw new ConfigException('TYPE_IMMUTABLE', 'The type of an existing project (receive or send) cannot be changed');
        } elseif (isset($body['profile']) && $body['profile'] !== $existing['profile']) {
            throw new ConfigException('PROFILE_IMMUTABLE', 'The profile of an existing project cannot be changed');
        }
        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];
        $unknown  = array_diff(array_keys($settings), ProjectsApi::SETTINGS);
        if ($unknown !== []) {
            throw new ConfigException('INVALID_SETTING', 'Unknown setting(s): ' . implode(', ', $unknown));
        }
        $changes = $settings + array_intersect_key($body, ['name' => 1, 'enabled' => 1]);
        if ($changes !== []) {
            $projects->update($ref, $changes, $ctx);
        }
        if (array_key_exists('routes', $body)) {
            if (! is_array($body['routes'])) {
                throw new ConfigException('INVALID_ROUTE', 'routes must be a list');
            }
            $current = array_column(service('routeService')->list($ref), 'route');
            $wanted  = array_values(array_map('strval', $body['routes']));
            if (array_map('strtolower', $wanted) != $current || $created) {
                service('routeService')->set($ref, $wanted, $ctx);
            }
        }
        if (array_key_exists('tokens', $body)) { // central tokens: the full set of the project (hashes only)
            if (! is_array($body['tokens'])) {
                throw new ConfigException('INVALID_TOKEN', 'tokens must be a list');
            }
            service('apiTokenService')->sync($ref, array_values($body['tokens']), $ctx);
        }

        return [$projects->get($ref), $created];
    }

    public function revokeToken(string $tokenId, ChangeContext $ctx): void
    {
        service('apiTokenService')->revoke($tokenId, $ctx);
    }

    /**
     * What a change would do, for the approval prompt: field => [current, new]; null current = new object.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    /**
     * Writes the common secrets and journals the names (never values).
     *
     * @param array<string, string> $values
     */
    public function secrets(string $revision, array $values, ChangeContext $ctx): void
    {
        $store = \App\Core\Node\CommonSecrets::forNode();
        $store->write($values, $revision);
        \App\Core\Support\Transaction::run(db_connect(), static fn () => service('journalWriter')->append('node.secrets_updated', $ctx, null, null,
            ['revision' => $revision, 'keys' => array_keys($values), 'local_overrides' => $store->summary()['local_overrides']]));
    }

    /** @return array<string, string> values of a queued secrets change (encrypted with the node key) */
    public static function decryptSecrets(array $payload): array
    {
        $json = \App\Core\Secrets\NodeCrypto::forNode()->decrypt((string) base64_decode((string) ($payload['enc'] ?? ''), true), 'admin-secrets:' . ($payload['revision'] ?? ''));

        return json_decode($json, true) ?: [];
    }

    /**
     * Send configuration from the admin (queued in manual mode, approved on the node). Applied without if_hash: the
     * operator approved exactly this set.
     *
     * @param array<string, mixed> $set
     */
    public function sendConfig(string $ref, array $set, ChangeContext $ctx): array
    {
        unset($set['if_hash']);
        $p = service('projectService')->get($ref);

        return service('sendConfigSync')->apply((int) $p['id'], $set, null, $ctx);
    }

    /**
     * Admin lock of a send access; $ref = customer/project/access_id.
     *
     * @param array{locked?: bool, reason?: string} $payload
     *
     * @return array<string, mixed> the access
     */
    public function accessLock(string $ref, array $payload, ChangeContext $ctx): array
    {
        [$project, $accessId] = self::splitAccessRef($ref);
        $p = service('projectService')->get($project);

        return service('sendAccessService')->lock((int) $p['id'], $accessId, ! empty($payload['locked']), (string) ($payload['reason'] ?? ''), $ctx);
    }

    /** @return array{0: string, 1: string} project ref, access id */
    private static function splitAccessRef(string $ref): array
    {
        $pos = strrpos($ref, '/');
        if ($pos === false || substr_count($ref, '/') !== 2) {
            throw new ConfigException('INVALID_ACCESS', 'Expected customer/project/access_id');
        }

        return [substr($ref, 0, $pos), substr($ref, $pos + 1)];
    }

    /** @return array<string, mixed> */
    public static function decryptSendConfig(string $ref, array $payload): array
    {
        $json = \App\Core\Secrets\NodeCrypto::forNode()->decrypt((string) base64_decode((string) ($payload['enc'] ?? ''), true), 'admin-send-config:' . $ref);

        return json_decode($json, true) ?: [];
    }

    public function preview(string $kind, string $ref, array $body): array
    {
        if ($kind === 'send_config') { // counts only, never keys or hashes
            $p   = service('projectService')->find($ref);
            $now = $p === null ? ['domains' => [], 'profiles' => [], 'accesses' => []] : service('sendConfigSync')->snapshot((int) $p['id']);

            return ['domains' => [count($now['domains']), (int) ($body['domains'] ?? 0)], 'profiles' => [count($now['profiles']), (int) ($body['profiles'] ?? 0)],
                'accesses' => [count($now['accesses']), (int) ($body['accesses'] ?? 0)]];
        }
        if ($kind === 'secrets') { // names and set/changed/removed only, never values
            $now  = \App\Core\Node\CommonSecrets::parse(config('Mailrunner')->commonSecretsFile());
            $want = self::decryptSecrets($body);
            $diff = [];
            foreach (array_unique([...array_keys($now), ...array_keys($want)]) as $k) {
                $state = match (true) {
                    ! isset($want[$k])     => [ 'set', 'removed'],
                    ! isset($now[$k])      => [null, 'set'],
                    $now[$k] !== $want[$k] => ['set', 'changed'],
                    default                => null,
                };
                if ($state !== null) {
                    $diff[$k] = $state;
                }
            }

            return $diff;
        }
        if ($kind === 'send_access_lock') {
            [$project, $accessId] = self::splitAccessRef($ref);
            $p   = service('projectService')->find($project);
            $row = $p === null ? null : db_connect()->table('send_accesses')->select('locked_at, locked_reason')->where('project_id', $p['id'])->where('access_id', $accessId)->get()->getRowArray();
            $now = $row === null ? null : ($row['locked_at'] === null ? 'unlocked' : 'locked: ' . $row['locked_reason']);

            return ['access ' . $accessId => [$now, ! empty($body['locked']) ? 'locked: ' . ($body['reason'] ?? '') : 'unlocked']];
        }
        if ($kind === 'token_revoke') {
            return ['revoke' => [$ref, 'revoked']];
        }
        if ($kind === 'customer') {
            $current = service('customerService')->findByKey($ref);
            $now     = $current === null ? [] : ['name' => $current['name'], 'enabled' => (bool) $current['enabled']];
            $want    = array_intersect_key($body, ['name' => 1, 'enabled' => 1]);
        } else {
            $p   = service('projectService')->find($ref);
            $now = $p === null ? [] : ProjectsApi::present($p, array_column(service('routeService')->list($ref), 'route'));
            $now = $now === [] ? [] : ['type' => $now['type'], 'profile' => $now['profile'], 'name' => $now['name'], 'enabled' => $now['enabled'], 'routes' => $now['routes']] + $now['settings'];
            $want = array_intersect_key($body, ['type' => 1, 'profile' => 1, 'name' => 1, 'enabled' => 1, 'routes' => 1]) + (is_array($body['settings'] ?? null) ? $body['settings'] : []);
        }
        $diff = [];
        foreach ($want as $field => $value) {
            $old = $now[$field] ?? null;
            if ($now === [] || json_encode($old) !== json_encode($value)) {
                $diff[$field] = [$now === [] ? null : $old, $value];
            }
        }
        if ($kind === 'project' && is_array($body['tokens'] ?? null)) { // token ids only, never hashes
            $diff += self::tokenPreview($ref, $body['tokens']);
        }

        return $diff;
    }

    /**
     * @param list<array<string, mixed>> $tokens
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private static function tokenPreview(string $ref, array $tokens): array
    {
        $p    = service('projectService')->find($ref);
        $have = [];
        if ($p !== null) {
            foreach (db_connect()->table('api_tokens')->select('token_id, label, expires_at, revoked_at')->where('project_id', $p['id'])->where('scope', 'manage')->get()->getResultArray() as $t) {
                $have[$t['token_id']] = $t;
            }
        }
        $active = static fn (array $t): bool => ($t['revoked_at'] ?? null) === null;
        $out    = [];
        foreach ($tokens as $t) {
            $id  = (string) ($t['token_id'] ?? '');
            $cur = $have[$id] ?? null;
            if ($cur === null) {
                $out['token ' . $id] = [null, ($t['label'] ?? '') . ($active($t) ? '' : ' (revoked)')];
            } elseif ($cur['revoked_at'] === null && ! $active($t)) {
                $out['token ' . $id] = ['active', 'revoked'];
            }
            unset($have[$id]);
        }
        foreach ($have as $id => $t) {
            if ($t['revoked_at'] === null) {
                $out['token ' . $id] = ['active', 'revoked (not in admin)'];
            }
        }
        if ($p !== null && (int) $p['tokens_central'] !== 1) {
            $out['tokens'] = ['local', 'managed in MailRunner Admin'];
        }

        return $out;
    }
}
