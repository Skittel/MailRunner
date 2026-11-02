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
        if ($existing === null) {
            $projects->create($ref, (string) ($body['profile'] ?? ''), (string) ($body['name'] ?? ''), $ctx);
            $created = true;
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
    public function preview(string $kind, string $ref, array $body): array
    {
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
            $now = $now === [] ? [] : ['profile' => $now['profile'], 'name' => $now['name'], 'enabled' => $now['enabled'], 'routes' => $now['routes']] + $now['settings'];
            $want = array_intersect_key($body, ['profile' => 1, 'name' => 1, 'enabled' => 1, 'routes' => 1]) + (is_array($body['settings'] ?? null) ? $body['settings'] : []);
        }
        $diff = [];
        foreach ($want as $field => $value) {
            $old = $now[$field] ?? null;
            if ($now === [] || json_encode($old) !== json_encode($value)) {
                $diff[$field] = [$now === [] ? null : $old, $value];
            }
        }

        return $diff;
    }
}
